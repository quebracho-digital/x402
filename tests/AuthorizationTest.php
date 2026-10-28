<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\Authorization;
use Quebracho\X402\Networks;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\Signer;

final class AuthorizationTest extends TestCase
{
    public function testPaysExactlyTheRequirementAndSurvivesTheHeader(): void
    {
        $signer = new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318');
        $req = Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
        $required = ['x402Version' => 2, 'resource' => ['url' => 'https://example.test/mcp'], 'accepts' => [$req]];
        $nonce = '0x' . str_repeat('ab', 32);

        $payment = PaymentHeaders::decode(PaymentHeaders::encode(Authorization::pay($signer, $required, $req, 1_000, $nonce)));
        $auth = $payment['payload']['authorization'];

        self::assertSame('10000', $auth['value']);
        self::assertSame($signer->address(), $auth['from']);
        self::assertSame($req['payTo'], $auth['to']);
        self::assertSame((string) (1_000 + $req['maxTimeoutSeconds']), $auth['validBefore']);
        self::assertSame('eip3009', $payment['accepted']['extra']['assetTransferMethod']);
        // RFC 6979 signatures are deterministic: the payload signs this exact digest.
        self::assertSame($signer->sign(Authorization::digest($req, $auth)), $payment['payload']['signature']);
    }
}
