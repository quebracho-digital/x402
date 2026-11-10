<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * What a seller needs, said by name, and the middlewares built from it:
 *
 *     $seller = Seller::with([new EvmExact()], $facilitator, $psr17, $psr17)
 *         ->ledger(new FileLedger($dir))
 *         ->events($dispatcher);
 *     $http = $seller->http(fn (ServerRequestInterface $r): ?array => $accepts);   // X402Middleware
 *     $mcp = $seller->mcp(fn (array $params): ?array => $accepts);                 // X402McpMiddleware
 *
 * The same options as the middlewares' constructors (which stay as they are): this only spares counting
 * positions. Each method returns a copy; the one it is called on is not changed.
 *
 * A seller needs somewhere to remember a payment was used: a ledger() (recommended: it also replays the
 * answer to a retry) or claims(). Building a middleware without either throws.
 */
final class Seller
{
    private ?\Closure $claim = null;
    private ?\Closure $release = null;
    private ?PaymentLedger $ledger = null;
    private ?EventDispatcherInterface $events = null;
    private ?\Closure $verify = null;
    private ?\Closure $lookup = null;
    private ?\Closure $sensitive = null;
    private ?OfacSdn $ofac = null;
    private ?Stop $stop = null;

    /**
     * @param Scheme[] $schemes
     */
    private function __construct(
        private readonly array $schemes,
        private readonly \Closure|Facilitator $settle,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @param Scheme[]             $schemes the schemes this seller verifies, one per chain family
     * @param \Closure|Facilitator $settle  a Facilitator (it settles, and verifies online unless verify() says
     *                                      otherwise), or a closure fn(array $payment, array $req): array
     */
    public static function with(array $schemes, \Closure|Facilitator $settle, ResponseFactoryInterface $responses, StreamFactoryInterface $streams): self
    {
        return new self($schemes, $settle, $responses, $streams);
    }

    /** Keeps each payment's outcome and replays the answer to a retry. Takes the place of claims(). */
    public function ledger(PaymentLedger $ledger): self
    {
        return $this->set('ledger', $ledger);
    }

    /**
     * Without a ledger: where a payment is claimed once (FileClaims, or closures over a DB unique key).
     *
     * @param FileClaims|\Closure $claim   fn(string $key): bool
     * @param ?\Closure           $release fn(string $key): void; with FileClaims, its own release
     */
    public function claims(FileClaims|\Closure $claim, ?\Closure $release = null): self
    {
        $copy = $this->set('claim', $claim instanceof FileClaims ? $claim(...) : $claim);
        $copy->release = $release ?? ($claim instanceof FileClaims ? $claim->release(...) : null);

        return $copy;
    }

    /** Where AddressCheck, PaymentIntent, PaymentOutcome and PaymentFault go. */
    public function events(EventDispatcherInterface $events): self
    {
        return $this->set('events', $events);
    }

    /**
     * The online check before the claim, fn(array $payment, array $req): ?string, in place of the
     * facilitator's own /verify.
     */
    public function verify(\Closure $verify): self
    {
        return $this->set('verify', $verify);
    }

    /** Asks the chain about a pending payment (ReceiptLookup): fn(array $state, array $payment, array $req): ?array. */
    public function lookup(\Closure|ReceiptLookup $lookup): self
    {
        return $this->set('lookup', $lookup instanceof ReceiptLookup ? $lookup(...) : $lookup);
    }

    /** Which answers the ledger must not keep: fn(ServerRequestInterface|array $subject): bool (SensitiveRules). */
    public function sensitive(\Closure|SensitiveRules $sensitive): self
    {
        return $this->set('sensitive', $sensitive instanceof SensitiveRules ? $sensitive(...) : $sensitive);
    }

    /** The OFAC list to screen payers with, in place of the one shipped with the library. */
    public function ofac(OfacSdn $ofac): self
    {
        return $this->set('ofac', $ofac);
    }

    /** The stop switch: while its file exists, nothing new is sold. */
    public function stop(Stop $stop): self
    {
        return $this->set('stop', $stop);
    }

    /**
     * @param \Closure  $price    fn(ServerRequestInterface): ?array, the `accepts` list or NULL when free
     * @param ?\Closure $resource fn(ServerRequestInterface): array, the 402's resource
     */
    public function http(\Closure $price, ?\Closure $resource = null): X402Middleware
    {
        return new X402Middleware(
            $this->schemes,
            $price,
            $this->claimer(),
            $this->responses,
            $this->streams,
            $this->settle,
            events: $this->events,
            verify: $this->verify,
            release: $this->release,
            resource: $resource,
            ledger: $this->ledger,
            lookup: $this->lookup,
            sensitive: $this->sensitive,
            ofac: $this->ofac,
            stop: $this->stop,
        );
    }

    /**
     * @param \Closure|PriceList $price    fn(array $params): ?array, the `accepts` list of a tools/call or NULL when free
     * @param ?\Closure          $validate fn(array $params): ?string, before any payment is read
     * @param ?\Closure          $payer    fn(array $params, string $payer): ?string, before the claim
     * @param ?\Closure          $duration fn(array $params): int, worst-case seconds to the settle; with a PriceList, its own
     * @param ?\Closure          $resource fn(array $params): array, the PaymentRequired's resource
     */
    public function mcp(\Closure|PriceList $price, ?\Closure $validate = null, ?\Closure $payer = null, ?\Closure $duration = null, ?\Closure $resource = null): X402McpMiddleware
    {
        return new X402McpMiddleware(
            $this->schemes,
            $price instanceof PriceList ? $price(...) : $price,
            $this->claimer(),
            $this->responses,
            $this->streams,
            $this->settle,
            events: $this->events,
            verify: $this->verify,
            release: $this->release,
            resource: $resource,
            validate: $validate,
            payer: $payer,
            duration: $duration ?? ($price instanceof PriceList ? $price->duration(...) : null),
            ledger: $this->ledger,
            lookup: $this->lookup,
            sensitive: $this->sensitive,
            ofac: $this->ofac,
            stop: $this->stop,
        );
    }

    private function claimer(): \Closure
    {
        if (null !== $this->claim) {
            return $this->claim;
        }
        if (null === $this->ledger) {
            throw new \LogicException('A seller needs a ledger() or claims(): without one a payment could be delivered more than once.');
        }

        // Never called: with a ledger the middlewares claim in it.
        return static fn (string $key): bool => throw new \LogicException('The ledger claims.');
    }

    private function set(string $option, mixed $value): self
    {
        $copy = clone $this;
        $copy->$option = $value;

        return $copy;
    }
}
