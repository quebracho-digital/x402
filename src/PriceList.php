<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Quebracho\X402\Job\Job;

/**
 * X402McpMiddleware's $price (and $duration) for a server that sells tools at fixed prices: the paid tools have
 * a price (the default, or their own), the rest are free. A slow tool gets a longer signature ($timeouts) and
 * declares its worst case ($durations). A job tool ($jobs) is always paid.
 *
 * Fails closed, when it is built: a name that is no served tool (a typo would leave that tool free), a price
 * for a free tool, a timeout no buyer signs, a duration no signature covers, or a paid tool that cannot be
 * priced, all throw. Build it at deploy.
 */
final class PriceList
{
    /**
     * @param string                              $network   the CAIP-2 network every tool is charged on
     * @param string                              $payTo     who is paid
     * @param string                              $price     the default price, in dollars
     * @param list<string>                        $paid      the paid tools, by name
     * @param array<string, string>               $prices    a tool's own price, in dollars
     * @param array<string, mixed>                $timeouts  maxTimeoutSeconds per tool, 1..Budget::MAX_TIMEOUT; default Networks::TIMEOUT
     * @param array<string, mixed>                $durations worst-case seconds from the claim to the settle, per tool
     * @param array<string, array<string, mixed>> $jobs      the job tools (Job\JobDesk) by name: [role => start|result, pairedWith => its other tool, price => its own, optional]
     * @param ?list<string>                       $served    every tool the server serves; NULL skips the typo check
     * @param iterable<Scheme>                    $schemes   your own schemes, for a chain family the library does not ship
     *
     * @throws \InvalidArgumentException naming the setting at fault ($paid, $prices[name]…)
     */
    public function __construct(
        private readonly string $network,
        private readonly string $payTo,
        private readonly string $price,
        private readonly array $paid = [],
        private readonly array $prices = [],
        private readonly array $timeouts = [],
        private readonly array $durations = [],
        private readonly array $jobs = [],
        ?array $served = null,
        private readonly iterable $schemes = [],
    ) {
        if (null !== $served) {
            // A typo fails open: "wisdon" among the paid tools leaves wisdom free. Every name must be a served tool.
            foreach (['paid' => $this->paid, 'prices' => array_keys($this->prices), 'timeouts' => array_keys($this->timeouts), 'durations' => array_keys($this->durations), 'jobs' => array_keys($this->jobs)] as $setting => $names) {
                foreach ($names as $name) {
                    if (!\in_array($name, $served, true)) {
                        throw new \InvalidArgumentException("$setting names \"$name\", which is no tool this server serves.");
                    }
                }
            }
        }
        foreach ($this->jobs as $name => $job) {
            // A job is two tools that name each other: a `_start` without its `_result` takes deposits for nothing.
            $pair = $this->jobs[$job['pairedWith'] ?? ''] ?? null;
            if (!\in_array($job['role'] ?? null, ['start', 'result'], true) || null === $pair || ($pair['role'] ?? null) === $job['role'] || ($pair['pairedWith'] ?? null) !== $name) {
                throw new \InvalidArgumentException("jobs[$name]: its role is start or result, and its pair must be a job with the other role, paired back with it.");
            }
        }
        foreach ($this->prices as $name => $_) {
            // Ignored otherwise: a price for a tool nobody pays for is a misread config.
            if (!$this->isPaid((string) $name)) {
                throw new \InvalidArgumentException("prices prices \"$name\", which is free: add it to the paid tools or remove the price.");
            }
        }
        foreach ($this->timeouts as $tool => $seconds) {
            // A buyer (Budget) never signs longer than MAX_TIMEOUT: a longer offer would sell nothing.
            if (!\is_int($seconds) || $seconds < 1 || $seconds > Budget::MAX_TIMEOUT) {
                throw new \InvalidArgumentException("timeouts[$tool] must be an integer from 1 to ".Budget::MAX_TIMEOUT.'.');
            }
        }
        foreach ($this->durations as $tool => $seconds) {
            // A duration no fresh signature can cover would refuse every paid call of that tool.
            if (!\is_int($seconds) || $seconds < 0 || $seconds + EvmExact::SLACK >= ($this->timeouts[$tool] ?? Networks::TIMEOUT)) {
                throw new \InvalidArgumentException("durations[$tool] must be an integer under its timeout minus ".EvmExact::SLACK.' s.');
            }
        }
        // Every paid tool must be sellable, or it errors on its first call.
        foreach (array_unique([...$this->paid, ...array_keys($this->jobs)]) as $name) {
            try {
                $this(['name' => (string) $name]);
            } catch (\InvalidArgumentException $e) {
                $from = null !== ($this->jobs[$name]['price'] ?? null) ? "the job price of $name" : (isset($this->prices[$name]) ? "prices[$name]" : 'price');
                throw new \InvalidArgumentException("$from: {$e->getMessage()}", 0, $e);
            }
        }
    }

    /**
     * @param array{name: string, arguments?: array<string, mixed>} $params the tools/call params
     *
     * @return list<array>|null the x402 `accepts` list, or null when free
     */
    public function __invoke(array $params): ?array
    {
        $name = $params['name'];
        // Fails closed: a job tool is never free, listed as paid or not.
        if (!$this->isPaid($name)) {
            return null;
        }
        $job = $this->jobs[$name] ?? null;
        $price = $job['price'] ?? $this->prices[$name] ?? $this->price;
        $req = Networks::requirements($this->network, (string) $price, $this->payTo, (int) ($this->timeouts[$name] ?? Networks::TIMEOUT), $this->schemes);
        if ('start' === ($job['role'] ?? null)) {
            // The deposit settles before the job is queued.
            $req['extra']['paymentFlow'] = 'upfront';
            // Said before the buyer signs: the deposit is not refunded; what a failure of ours costs instead is
            // nothing more, the result tool is free then. A buyer's agent can read it from the offer.
            $req['extra']['job'] = [
                'deposit' => 'non_refundable',
                'refund' => 'none; if the job fails by our fault (failed_ours), '.$job['pairedWith'].' is free',
                'result' => $job['pairedWith'],
                'resultTtl' => Job::RESULT_TTL,
            ];
        }

        return [$req];
    }

    /**
     * X402McpMiddleware's $duration: 0 (no pre-flight) unless $durations names the tool.
     */
    public function duration(array $params): int
    {
        return (int) ($this->durations[$params['name']] ?? 0);
    }

    public function isPaid(string $tool): bool
    {
        return isset($this->jobs[$tool]) || \in_array($tool, $this->paid, true);
    }
}
