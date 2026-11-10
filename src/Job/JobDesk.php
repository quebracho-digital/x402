<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

use Psr\Clock\ClockInterface;
use Quebracho\X402\Facilitator;
use Quebracho\X402\MockFacilitator;
use Quebracho\X402\PaymentLedger;
use Quebracho\X402\Scheme;
use Quebracho\X402\Settlement;
use Quebracho\X402\X402Middleware;

/**
 * Where job mode meets the payment: the hooks X402McpMiddleware calls, routed by the tool's job role. A job
 * is bought in two payments, and each has its own class:
 *
 *   `_start`   the deposit, settled BEFORE the tool runs: JobDeposit
 *   `_result`  the rest, settled AFTER the tool answered: JobDelivery (also `_status`, which is free)
 *
 * Wire its four hooks into the middleware, and call afterResponse() once the response went out:
 *
 *     $desk = JobDesk::for($jobs, $store, $runner, $slots, $ledger, $facilitator, $priceList(...));
 *     $mcp = $seller->mcp($priceList, validate: $desk->validate(...), payer: $desk->payer(...));
 *     // with Seller: ->sensitive($desk->sensitive(...)) and Seller::with($schemes, $desk->settle(...), …)
 *
 * The order the middleware calls the hooks in, for one tools/call:
 *
 *   validate()   before any payment is read: your own check (a schema), then a `_start`'s work check
 *   payer()      once the signature proved who pays, before the claim: the role's own refusal, or NULL
 *   sensitive()  whether the ledger may keep the answer
 *   settle()     around the facilitator's settle: the role's beforeSettle(), the facilitator, its afterSettle()
 *
 * then, after the response went out, afterResponse() (in Symfony, a kernel.terminate listener; with PHP-FPM,
 * after fastcgi_finish_request()). A tool that is no job goes straight through: your check, the rules, the
 * facilitator.
 *
 * The tools themselves stay thin: `_start` calls $desk->deposit->queue(), `_result` calls
 * $desk->delivery->deliver(), `_status` calls $desk->delivery->status().
 *
 * One instance per request, like its two parts: the role is this request's call, set by payer() and cleared
 * by afterResponse(). A long-running worker that does not call afterResponse() must call reset().
 */
final class JobDesk
{
    /** The key under which a job tool publishes its role in its MCP `_meta`, and a re-read marks its receipt. */
    public const string META = 'x402/job';

    /** This request's role ('start' or 'result'), from payer(); NULL for a tool that is no job. */
    private ?string $role = null;
    private readonly \Closure $settle;

    /**
     * @param \Closure|Facilitator                                                    $settle    the facilitator, or the closure a middleware would be given as $settle
     * @param array<string, array{role: string, pairedWith: string, price?: ?string}> $jobs      the job tools by name (role: start or result)
     * @param ?\Closure                                                               $validate  fn(array $params): ?string, your own check of a call (its input schema), run first
     * @param ?\Closure                                                               $sensitive fn(array $params): bool, for the tools that are no job (SensitiveRules)
     */
    public function __construct(
        public readonly JobDeposit $deposit,
        public readonly JobDelivery $delivery,
        \Closure|Facilitator $settle,
        private readonly array $jobs,
        private readonly ?\Closure $validate = null,
        private readonly ?\Closure $sensitive = null,
    ) {
        $this->settle = Settlement::settler($settle);
    }

    /**
     * A desk with its deposit, delivery, janitor and reconciler built from the same parts.
     *
     * @param array<string, array{role: string, pairedWith: string, price?: ?string}> $jobs              the job tools by name
     * @param \Closure|Facilitator                                                    $settle            a MockFacilitator never asks the chain
     * @param \Closure                                                                $price             the middleware's $price (a PriceList's __invoke)
     * @param ?\Closure                                                               $validate          fn(array $params): ?string
     * @param ?\Closure                                                               $sensitive         fn(array $params): bool (SensitiveRules)
     * @param ?\Closure(string, string, string): bool                                 $authorizationUsed AuthorizationState, for the reconciler
     * @param iterable<Scheme>                                                        $schemes           the seller's own schemes
     */
    public static function for(
        array $jobs,
        JobStore $store,
        JobRunner $runner,
        JobSlots $slots,
        PaymentLedger $ledger,
        \Closure|Facilitator $settle,
        \Closure $price,
        ?\Closure $validate = null,
        ?\Closure $sensitive = null,
        ?\Closure $authorizationUsed = null,
        ClockInterface|\Closure|null $clock = null,
        iterable $schemes = [],
    ): self {
        $payments = new JobPayments($ledger, $authorizationUsed, $settle instanceof MockFacilitator ? 'mock' : '');

        return new self(
            new JobDeposit($store, $runner, $slots, $price, $jobs, $sensitive, $clock, $schemes),
            new JobDelivery($store, $jobs, new JobReconciler($store, $payments, $ledger), new JobJanitor($store), $sensitive, $clock, $schemes),
            $settle,
            $jobs,
            $validate,
            $sensitive,
        );
    }

    /**
     * X402McpMiddleware's $validate: your own check, then for a `_start` its work's own check.
     */
    public function validate(array $params): ?string
    {
        $invalid = null === $this->validate ? null : ($this->validate)($params);

        return null === $invalid && 'start' === $this->role($params['name']) ? $this->deposit->check($params) : $invalid;
    }

    /**
     * X402McpMiddleware's $payer: before the claim, why $from cannot buy this call, or NULL. Never throws.
     */
    public function payer(array $params, string $from): ?string
    {
        $this->role = $this->role($params['name']);

        return match ($this->role) {
            'start' => $this->deposit->payer($params, $from),
            'result' => $this->delivery->payer($params, $from),
            default => null,
        };
    }

    /**
     * X402McpMiddleware's $settle, around the facilitator's. Never throws after the facilitator answered:
     * Settlement::run would turn the throw into a failed receipt, and withhold an answer that was paid for.
     */
    public function settle(array $payment, array $req, ?\Closure $reference = null): array
    {
        $instead = match ($this->role) {
            'start' => $this->deposit->beforeSettle($payment, $req),
            'result' => $this->delivery->beforeSettle($req),
            default => null,
        };
        if (null !== $instead) {
            return $instead;
        }
        $receipt = null === $reference ? ($this->settle)($payment, $req) : ($this->settle)($payment, $req, $reference);
        $moved = true === ($receipt['success'] ?? null);
        // A clear refusal: no transaction, and a reason that says no money can have moved.
        $nothing = !$moved && '' === ($receipt['transaction'] ?? '') && !\in_array($receipt['errorReason'] ?? null, Settlement::MAY_HAVE_MOVED, true);
        match ($this->role) {
            'start' => $this->deposit->afterSettle($nothing),
            'result' => $this->delivery->afterSettle($moved, $nothing),
            default => null,
        };

        return $receipt;
    }

    /**
     * X402McpMiddleware's $sensitive: whether the ledger must not keep this call's answer.
     */
    public function sensitive(array $params): bool
    {
        return match ($this->role($params['name'])) {
            // A job id, no buyer text: always kept, so a retry of the same deposit gets the same job
            // instead of already_settled. A sensitive deposit marks its job instead (see the result).
            'start' => false,
            'result' => $this->delivery->sensitive($params),
            default => X402Middleware::sensitive($this->sensitive, $params),
        };
    }

    /**
     * After the response went out: a `_result` never settled is ready again, a queued `_start` runs.
     */
    public function afterResponse(): void
    {
        try {
            $this->delivery->afterResponse();
            $this->deposit->afterResponse();
        } finally {
            $this->reset();
        }
    }

    public function reset(): void
    {
        $this->role = null;
        $this->deposit->reset();
        $this->delivery->reset();
    }

    private function role(string $name): ?string
    {
        return $this->jobs[$name]['role'] ?? null;
    }
}
