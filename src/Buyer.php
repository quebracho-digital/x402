<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * What a buyer needs, said by name, and the paying clients built from it:
 *
 *     $buyer = Buyer::with([new EvmExact($signer)], Budget::usd('eip155:84532', '0.05'))->events($dispatcher);
 *     $http = $buyer->http($psr18);            // PayingHttpClient: pays HTTP 402s
 *     $mcp = $buyer->mcp($psr18, $psr17);      // PayingMcpClient: pays MCP tools
 *
 * The same options as the clients' constructors (which stay as they are). Each method returns a copy. Both
 * clients built from one Buyer share its budget.
 *
 * The wrapped PSR-18 client must not follow redirects: one to another host would hand it the signed payment.
 */
final class Buyer
{
    private ?EventDispatcherInterface $events = null;
    private ?\Closure $onPaid = null;
    private ?\Closure $onRefused = null;
    private ?OfacSdn $ofac = null;
    private ?Stop $stop = null;

    /**
     * @param Scheme[] $schemes
     */
    private function __construct(
        private readonly array $schemes,
        private readonly Budget $budget,
    ) {
    }

    /**
     * @param Scheme[] $schemes the schemes this buyer can pay with, each holding its key
     * @param Budget   $budget  refuses whatever would exceed the spending cap
     */
    public static function with(array $schemes, Budget $budget): self
    {
        return new self($schemes, $budget);
    }

    /** Where the AddressCheck on each payTo, PurchaseIntent, PurchaseOutcome and BudgetReclaim go. */
    public function events(EventDispatcherInterface $events): self
    {
        return $this->set('events', $events);
    }

    /**
     * Called with each receipt: fn(array $req, array $receipt) for http(), fn(string $tool, array $req, array
     * $receipt) for mcp().
     */
    public function onPaid(\Closure $onPaid): self
    {
        return $this->set('onPaid', $onPaid);
    }

    /** Called with each offer not paid, and why: fn(array $req, string $reason). */
    public function onRefused(\Closure $onRefused): self
    {
        return $this->set('onRefused', $onRefused);
    }

    /** The OFAC list to screen each payTo with, in place of the one shipped with the library. */
    public function ofac(OfacSdn $ofac): self
    {
        return $this->set('ofac', $ofac);
    }

    /** The stop switch: while its file exists, nothing is signed. */
    public function stop(Stop $stop): self
    {
        return $this->set('stop', $stop);
    }

    public function http(ClientInterface $client): PayingHttpClient
    {
        return new PayingHttpClient($client, $this->schemes, $this->budget, $this->onPaid, $this->events, $this->onRefused, $this->ofac, $this->stop);
    }

    public function mcp(ClientInterface $client, StreamFactoryInterface $streams): PayingMcpClient
    {
        return new PayingMcpClient($client, $this->schemes, $this->budget, $streams, $this->onPaid, $this->events, $this->onRefused, $this->ofac, $this->stop);
    }

    private function set(string $option, mixed $value): self
    {
        $copy = clone $this;
        $copy->$option = $value;

        return $copy;
    }
}
