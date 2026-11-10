<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Mock mode as a Facilitator, opted into by name: the middlewares still verify every signature offline, and
 * nothing moves on chain. Its receipt has no transaction and says `mock` (Settlement::mock()).
 *
 * For a demo or a test. A seller that settles with it delivers for free.
 */
final class MockFacilitator implements Facilitator
{
    public function verify(array $payment, array $req): array
    {
        return ['isValid' => true];
    }

    public function settle(array $payment, array $req): array
    {
        return Settlement::mock()($payment, $req);
    }
}
