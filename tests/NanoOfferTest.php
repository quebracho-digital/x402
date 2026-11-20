<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\NanoExact;
use Quebracho\X402\Networks;

/**
 * A seller's Nano offer, as Networks builds it: priced in XNO, amounts in raw (1 XNO = 10^30 raw).
 */
final class NanoOfferTest extends TestCase
{
    protected function tearDown(): void
    {
        Networks::reset();
    }

    public function testAnOfferIsBuiltInRawAndVerifiesItsOwnPayment(): void
    {
        $network = Networks::addNano();
        $req = Networks::requirementsInToken($network, '0.001', 'xrb_'.substr(NanoExactTest::req()['payTo'], 5));

        // payTo comes out in its one spelling, the price in raw, the flow upfront.
        $this->assertSame(NanoExactTest::req(), $req);
        $this->assertSame(null, (new NanoExact())->verify(NanoExactTest::payment(), $req));
    }

    public function testAPriceInDollarsIsRefused(): void
    {
        Networks::addNano();

        $this->expectException(\InvalidArgumentException::class);
        Networks::requirements(NanoExact::NETWORK, '0.01', NanoExactTest::req()['payTo']);
    }

    public function testAMistypedPayToIsRefusedWhenTheOfferIsBuilt(): void
    {
        Networks::addNano();

        $this->expectException(\InvalidArgumentException::class);
        Networks::requirementsInToken(NanoExact::NETWORK, '0.001', substr(NanoExactTest::req()['payTo'], 0, -1).'4');
    }

    public function testAPriceFinerThanARawIsRefused(): void
    {
        Networks::addNano();

        $this->expectException(\InvalidArgumentException::class);
        Networks::requirementsInToken(NanoExact::NETWORK, '0.'.str_repeat('0', 30).'1', NanoExactTest::req()['payTo']);
    }
}
