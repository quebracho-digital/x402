<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\Signer;

final class SignerTest extends TestCase
{
    public function testAddressFromKnownKey(): void
    {
        // Well-known test vector from the web3.js docs; never funded.
        $signer = new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318');
        self::assertSame('0x2c7536E3605D9C16a7a3D7b1898e529396a65c23', $signer->address());
    }
}
