<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\Job\Job;
use Quebracho\X402\PriceList;

final class PriceListTest extends TestCase
{
    private const PAY_TO = '0x000000000000000000000000000000000000dEaD';
    private const SERVED = ['wisdom', 'echo', 'analyze_terms', 'j_start', 'j_result'];
    private const JOBS = [
        'j_start' => ['role' => 'start', 'pairedWith' => 'j_result', 'price' => '0.03'],
        'j_result' => ['role' => 'result', 'pairedWith' => 'j_start'],
    ];

    public function testPaidToolsHaveAPriceAndTheRestAreFree(): void
    {
        $list = new PriceList('eip155:84532', self::PAY_TO, '0.01', ['wisdom', 'analyze_terms'], ['analyze_terms' => '0.02'], ['analyze_terms' => 120], ['analyze_terms' => 60], served: self::SERVED);

        self::assertNull($list(['name' => 'echo']));
        self::assertFalse($list->isPaid('echo'));
        $wisdom = $list(['name' => 'wisdom'])[0];
        self::assertSame(['10000', 60, self::PAY_TO], [$wisdom['amount'], $wisdom['maxTimeoutSeconds'], $wisdom['payTo']]);
        self::assertArrayNotHasKey('paymentFlow', $wisdom['extra'], 'a fast tool keeps the default flow');
        $slow = $list(['name' => 'analyze_terms'])[0];
        self::assertSame(['20000', 120], [$slow['amount'], $slow['maxTimeoutSeconds']]);
        self::assertSame(60, $list->duration(['name' => 'analyze_terms']));
        self::assertSame(0, $list->duration(['name' => 'wisdom']));
    }

    public function testAJobToolIsAlwaysPaidAndItsStartIsUpfront(): void
    {
        $list = new PriceList('eip155:84532', self::PAY_TO, '0.01', jobs: self::JOBS, served: self::SERVED);

        $start = $list(['name' => 'j_start'])[0];
        self::assertSame('30000', $start['amount'], 'its own price');
        self::assertSame('upfront', $start['extra']['paymentFlow']);
        self::assertSame(['deposit' => 'non_refundable', 'refund' => 'none; if the job fails by our fault (failed_ours), j_result is free', 'result' => 'j_result', 'resultTtl' => Job::RESULT_TTL], $start['extra']['job']);
        $result = $list(['name' => 'j_result'])[0];
        self::assertSame('10000', $result['amount'], 'the default price');
        self::assertArrayNotHasKey('paymentFlow', $result['extra']);
        self::assertTrue($list->isPaid('j_result'), 'never free, listed as paid or not');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function mistakes(): iterable
    {
        yield 'a paid tool that is not served' => [['paid' => ['wisdon']], 'paid names "wisdon", which is no tool this server serves'];
        yield 'a price for a tool that is not served' => [['paid' => ['wisdom'], 'prices' => ['wisdon' => '0.02']], 'prices names "wisdon"'];
        yield 'a timeout typo is reported before the duration it breaks' => [['paid' => ['analyze_terms'], 'timeouts' => ['analyze_term' => 120], 'durations' => ['analyze_terms' => 60]], 'timeouts names "analyze_term"'];
        yield 'a price for a free tool' => [['paid' => ['wisdom'], 'prices' => ['echo' => '0.01']], 'prices prices "echo", which is free'];
        yield 'a timeout no buyer signs' => [['paid' => ['wisdom'], 'timeouts' => ['wisdom' => 601]], 'timeouts[wisdom] must be an integer from 1 to 600'];
        yield 'a timeout that is no integer' => [['paid' => ['wisdom'], 'timeouts' => ['wisdom' => '120']], 'timeouts[wisdom] must be an integer'];
        yield 'a duration no signature covers' => [['paid' => ['wisdom'], 'durations' => ['wisdom' => 54]], 'durations[wisdom] must be an integer under its timeout'];
        yield 'a default price the token cannot represent' => [['paid' => ['wisdom'], 'price' => '0.0000001'], 'price: Not a price'];
        yield 'a tool price that is no number' => [['paid' => ['wisdom'], 'prices' => ['wisdom' => 'abc']], 'prices[wisdom]: Not a price'];
        yield 'a job price of zero' => [['jobs' => ['j_start' => ['price' => '0.00'] + self::JOBS['j_start']] + self::JOBS], 'the job price of j_start: A price must be above zero'];
        yield 'a job without its pair' => [['jobs' => ['j_start' => self::JOBS['j_start']]], 'jobs[j_start]'];
        yield 'two starts' => [['jobs' => ['j_start' => self::JOBS['j_start'], 'j_result' => ['role' => 'start', 'pairedWith' => 'j_start']]], 'jobs[j_start]'];
        yield 'an unknown role' => [['jobs' => ['j_start' => ['role' => 'deposit', 'pairedWith' => 'j_result'], 'j_result' => self::JOBS['j_result']]], 'jobs[j_start]'];
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('mistakes')]
    public function testAMistakeFailsWhenTheListIsBuilt(array $settings, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new PriceList('eip155:84532', self::PAY_TO, ...$settings + ['price' => '0.01', 'served' => self::SERVED]);
    }

    public function testTheLargestDurationThatFits(): void
    {
        $list = new PriceList('eip155:84532', self::PAY_TO, '0.01', ['analyze_terms', 'wisdom'], timeouts: ['analyze_terms' => 600], durations: ['analyze_terms' => 593, 'wisdom' => 53]);

        self::assertSame(593, $list->duration(['name' => 'analyze_terms']));
    }

    public function testAnUnusedDefaultPriceIsNotChecked(): void
    {
        self::assertNull((new PriceList('eip155:84532', self::PAY_TO, 'abc'))(['name' => 'wisdom']));
    }

    public function testAnUnknownNetworkOrPayToFailsWhenTheListIsBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PriceList('eip155:84532', 'not-an-address', '0.01', ['wisdom']);
    }
}
