<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\Eip712;
use Quebracho\X402\Signer;

final class Eip712Test extends TestCase
{
    // The "Mail" example from the EIP-712 specification.
    public function testSpecMailExample(): void
    {
        $person = [['name' => 'name', 'type' => 'string'], ['name' => 'wallet', 'type' => 'address']];
        $digest = Eip712::hash(
            ['name' => 'Ether Mail', 'version' => '1', 'chainId' => 1, 'verifyingContract' => '0xCcCCccccCCCCcCCCCCCcCcCccCcCCCcCcccccccC'],
            ['Person' => $person, 'Mail' => [['name' => 'from', 'type' => 'Person'], ['name' => 'to', 'type' => 'Person'], ['name' => 'contents', 'type' => 'string']]],
            'Mail',
            [
                'from' => ['name' => 'Cow', 'wallet' => '0xCD2a3d9F938E13CD947Ec05AbC7FE734Df8DD826'],
                'to' => ['name' => 'Bob', 'wallet' => '0xbBbBBBBbbBBBbbbBbbBbbbbBBbBbbbbBbBbbBBbB'],
                'contents' => 'Hello, Bob!',
            ],
        );
        // Spec key is keccak256('cow'); expected r ‖ s ‖ v from the spec.
        self::assertSame(
            '0x4355c47d63924e8a72e509b65029052eb6c299d53a04e167c5775fd466751c9d07299936d304c153f6443dfa05f40ff007d72911b6f72307f996231605b915621c',
            (new Signer(Eip712::keccak('cow')))->sign($digest),
        );
        self::assertSame('0xbe609aee343fb3c4b28e1df9e632fca64fcfaede20f02e86244efddf30957bd2', $digest);
    }
}
