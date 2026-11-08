<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\AuthCapture;
use Quebracho\X402\Eip712;

final class AuthCaptureTest extends TestCase
{
    /** The PaymentInfo of the reference SDK's golden tests (go/mechanisms/evm/auth-capture/nonce_test.go). */
    private const INFO = [
        'operator' => '0x1111111111111111111111111111111111111111',
        'payer' => '0x5555555555555555555555555555555555555555',
        'receiver' => '0x2222222222222222222222222222222222222222',
        'token' => '0x3333333333333333333333333333333333333333',
        'maxAmount' => '1000000',
        'preApprovalExpiry' => 281474976710655,
        'authorizationExpiry' => 281474976710655,
        'refundExpiry' => 281474976710655,
        'minFeeBps' => 0,
        'maxFeeBps' => 100,
        'feeReceiver' => '0x4444444444444444444444444444444444444444',
        'salt' => '0x0000000000000000000000000000000000000000000000000000000000000001',
    ];

    public function testTheNonceMatchesTheReferenceSdk(): void
    {
        // The payer is zeroed in the nonce, so any payer gives the SDK's value.
        self::assertSame('0x695990db5fd8f3f541f505b117da619dace4d28a039e35646ce7b660e699ff4b', AuthCapture::nonce(84532, self::INFO));
        self::assertSame('0x37588eff80093203c128c6622608c6e972e41c2fd79da91ef5294a26e647e4e2', AuthCapture::nonce(8453, self::INFO));
        self::assertSame('0x19de8ffcb747e5caadb3dda7435cf54992e87cdf0c90e5315ffa129dbb22461e', AuthCapture::nonce(84532, self::INFO, AuthCapture::V1_0['escrow']));
        self::assertSame('0x198bbfeaab2f8e36302c662ae41bceaef47a1eb4bf2549cb87aa8daa7f7bb43a', AuthCapture::nonce(8453, self::INFO, AuthCapture::V1_0['escrow']));
    }

    public function testTheHashCommitsToThePayer(): void
    {
        self::assertNotSame(AuthCapture::nonce(84532, self::INFO), AuthCapture::hash(84532, self::INFO));
        self::assertSame(AuthCapture::nonce(84532, self::INFO), AuthCapture::hash(84532, ['payer' => AuthCapture::ZERO] + self::INFO));
    }

    public function testDeployments(): void
    {
        self::assertSame(AuthCapture::V1_1, AuthCapture::deployment(null));
        self::assertSame(AuthCapture::V1_1, AuthCapture::deployment(strtolower(AuthCapture::V1_1['escrow'])));
        self::assertSame(AuthCapture::V1_0, AuthCapture::deployment(AuthCapture::V1_0['escrow']));
        self::assertNull(AuthCapture::deployment('0xdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef'));
        self::assertNull(AuthCapture::deployment(42));
    }

    public function testCallsHaveTheEscrowsSelectors(): void
    {
        // Selectors computed independently: keccak of the canonical signature with the tuple expanded.
        $tuple = '(address,address,address,address,uint120,uint48,uint48,uint48,uint16,uint16,address,uint256)';
        $sel = fn (string $sig): string => substr(Eip712::keccak($sig), 0, 10);

        $void = AuthCapture::voidCall(self::INFO);
        self::assertSame($sel("void($tuple)"), substr($void, 0, 10));
        self::assertSame(10 + 12 * 64, \strlen($void));

        $capture = AuthCapture::captureCall(self::INFO, '750000', '0', AuthCapture::ZERO);
        self::assertSame($sel("capture($tuple,uint256,uint256,address)"), substr($capture, 0, 10));
        self::assertSame(str_pad(dechex(750000), 64, '0', \STR_PAD_LEFT), substr($capture, 10 + 12 * 64, 64));

        $state = AuthCapture::stateCall('0x'.str_repeat('ab', 32));
        self::assertSame($sel('paymentState(bytes32)').str_repeat('ab', 32), $state);
    }

    public function testAuthorizeEncodesTheSignatureAsDynamicBytes(): void
    {
        $signature = '0x'.str_repeat('11', 65);
        $call = AuthCapture::authorizeCall(self::INFO, '1000000', AuthCapture::V1_1['eip3009Collector'], $signature);
        $words = str_split(substr($call, 10), 64);

        self::assertCount(12 + 3 + 1 + 3, $words); // tuple, amount + collector + offset, length, 65 bytes in 3 words
        self::assertSame(dechex(15 * 32), ltrim($words[14], '0'));
        self::assertSame(dechex(65), ltrim($words[15], '0'));
        self::assertSame(str_repeat('11', 65).str_repeat('0', 62), implode('', \array_slice($words, 16)));
    }

    public function testTupleFieldOrderAndWidths(): void
    {
        $words = str_split(AuthCapture::tuple(self::INFO), 64);

        self::assertSame(str_pad('1111111111111111111111111111111111111111', 64, '0', \STR_PAD_LEFT), $words[0]);
        self::assertSame(str_pad('f4240', 64, '0', \STR_PAD_LEFT), $words[4]);
        self::assertSame(str_pad('64', 64, '0', \STR_PAD_LEFT), $words[9]);
        self::assertSame(str_pad('1', 64, '0', \STR_PAD_LEFT), $words[11]);
    }

    public function testStateReadsThreeWords(): void
    {
        $answer = '0x'.str_pad('1', 64, '0', \STR_PAD_LEFT).str_pad(dechex(250), 64, '0', \STR_PAD_LEFT).str_pad(dechex(750), 64, '0', \STR_PAD_LEFT);

        self::assertSame(['collected' => true, 'capturable' => '250', 'refundable' => '750'], AuthCapture::state($answer));

        $this->expectException(\UnexpectedValueException::class);
        AuthCapture::state('0x');
    }

    public function testBoundSaltAndFee(): void
    {
        self::assertFalse(AuthCapture::bound(['extra' => ['receiverAuthorizer' => AuthCapture::ZERO]]));
        self::assertTrue(AuthCapture::bound(['extra' => ['policy' => '0x1111111111111111111111111111111111111111']]));
        // The reference SDK's golden value (nonce_test.go, TestSaltBinding).
        self::assertSame('0xd0967e09b6c8fccf96277d95a03e98583e8605ab10858b1349aa50ea6d78132c', AuthCapture::boundSalt('0x1111111111111111111111111111111111111111', AuthCapture::ZERO, '0x0000000000000000000000000000000000000000000000000000000000000abc'));
        self::assertNotSame(
            AuthCapture::boundSalt('0x1111111111111111111111111111111111111111', AuthCapture::ZERO, self::INFO['salt']),
            AuthCapture::boundSalt('0x2222222222222222222222222222222222222222', AuthCapture::ZERO, self::INFO['salt']),
        );

        self::assertSame('7500', AuthCapture::fee(['minFeeBps' => 100] + self::INFO, '750000'));
        self::assertSame('0', AuthCapture::fee(['minFeeBps' => 0] + self::INFO, '750000'));
    }
}
