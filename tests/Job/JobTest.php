<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests\Job;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\Job\IllegalTransition;
use Quebracho\X402\Job\Job;

final class JobTest extends TestCase
{
    private const ALLOWED = [
        'settling>queued',
        'queued>running', 'queued>failed_ours',
        'running>ready', 'running>failed_ours',
        'ready>delivering',
        'delivering>delivered', 'delivering>ready',
        // Unreachable without a hold (nothing enters capturing), harmless in the table.
        'capturing>ready', 'capturing>failed_ours',
    ];

    /** A job paid with a hold: one payment, captured before the result is handed out for free. */
    private const ALLOWED_HELD = [
        'settling>queued',
        'queued>running', 'queued>failed_ours',
        'running>capturing', 'running>failed_ours',
        'capturing>ready', 'capturing>failed_ours',
        'ready>delivered',
    ];

    private const HOLD = ['network' => 'eip155:84532', 'info' => ['operator' => '0x1'], 'end' => null, 'transaction' => null, 'sentAt' => null];

    private function in(string $state, ?array $hold = null): Job
    {
        return new Job(str_repeat('a', 32), '0xPayer', 'key', 'demo_start', $state, 100, 100, ['text' => 'x'], \in_array($state, [Job::READY, Job::DELIVERING, Job::CAPTURING], true) ? ['ok' => true] : null, hold: $hold);
    }

    /** Every state to every state: the table is written out here by hand, so a change to Job::TRANSITIONS shows up. */
    public static function pairs(): iterable
    {
        $states = array_keys(Job::TRANSITIONS);
        foreach ($states as $from) {
            foreach ($states as $to) {
                yield "$from>$to" => [$from, $to, \in_array("$from>$to", self::ALLOWED, true)];
                yield "held $from>$to" => [$from, $to, \in_array("$from>$to", self::ALLOWED_HELD, true), self::HOLD];
            }
        }
    }

    #[DataProvider('pairs')]
    public function testTheTransitionTable(string $from, string $to, bool $allowed, ?array $hold = null): void
    {
        if (!$allowed) {
            $this->expectException(IllegalTransition::class);
        }
        $next = $this->in($from, $hold)->to($to, 200, ['ok' => true], 'provider_error');

        self::assertSame($to, $next->state);
        self::assertSame(200, $next->updatedAt);
        self::assertSame(100, $next->createdAt);
    }

    public function testTheStatesAreThePlansSixPlusSettling(): void
    {
        self::assertSame(['settling', 'queued', 'running', 'capturing', 'ready', 'delivering', 'delivered', 'failed_ours'], array_keys(Job::TRANSITIONS));
    }

    public function testReadyNeedsAResult(): void
    {
        $this->expectException(IllegalTransition::class);
        $this->in(Job::RUNNING)->to(Job::READY, 200);
    }

    public function testFailedOursNeedsAReason(): void
    {
        $this->expectException(IllegalTransition::class);
        $this->in(Job::RUNNING)->to(Job::FAILED_OURS, 200);
    }

    public function testFailedOursTakesOnlyAReasonFromTheFixedList(): void
    {
        // A buyer's mistake filed as ours would make its _result free.
        $this->expectException(IllegalTransition::class);
        $this->in(Job::RUNNING)->to(Job::FAILED_OURS, 200, reason: 'bad_text');
    }

    public function testDeliveringKeepsTheResult(): void
    {
        $job = $this->in(Job::READY)->to(Job::DELIVERING, 200)->to(Job::READY, 300);

        self::assertSame(['ok' => true], $job->result);
    }

    public function testTheBuyersInputIsKeptOnlyUntilTheWorkRan(): void
    {
        $queued = Job::settling('0xPayer', 'key', 'demo_start', ['text' => 'secret'], 100)->to(Job::QUEUED, 110);
        $running = $queued->to(Job::RUNNING, 120);
        self::assertSame(['text' => 'secret'], $running->params, 'kept to resume after a crash');

        $ready = $running->to(Job::READY, 130, ['ok' => true]);
        self::assertNull($ready->params);
        self::assertNull($queued->to(Job::FAILED_OURS, 130, reason: 'our_timeout')->params);
    }

    public function testADeliveredResultStaysForTheRereadThenIsForgotten(): void
    {
        $delivered = $this->in(Job::DELIVERING)->to(Job::DELIVERED, 200);

        self::assertNotNull($delivered->result, 'its payer may read it again for free (7e)');
        self::assertNull($delivered->params);
        self::assertTrue($delivered->rereadable(200 + Job::RESULT_TTL - 1));
        self::assertFalse($delivered->rereadable(200 + Job::RESULT_TTL));

        $forgotten = $delivered->forget();
        self::assertNull($forgotten->result);
        self::assertSame([Job::DELIVERED, 200], [$forgotten->state, $forgotten->updatedAt], 'not a transition: the state and its time stay');
        self::assertFalse($forgotten->rereadable(201));
    }

    public function testTheResultPaymentIsKeptWhileItSettlesAndDroppedWhenTheJobIsReadyAgain(): void
    {
        $ref = ['key' => 'k2', 'network' => 'eip155:84532', 'nonce' => '0x01', 'validBefore' => 999];
        $delivering = $this->in(Job::READY)->to(Job::DELIVERING, 200, resultRef: $ref);

        self::assertSame($ref, $delivering->resultRef, 'what a lost settle is looked up by');
        self::assertSame($ref, $delivering->to(Job::DELIVERED, 210)->resultRef);
        self::assertNull($delivering->to(Job::READY, 210)->resultRef, 'the next payment brings its own');
    }

    public function testAReasonIsOnlyOnAFailedJob(): void
    {
        self::assertNull($this->in(Job::RUNNING)->to(Job::READY, 200, ['ok' => true], 'provider_error')->reason);
        self::assertSame('our_daily_cap', $this->in(Job::RUNNING)->to(Job::FAILED_OURS, 200, reason: 'our_daily_cap')->reason);
    }

    public function testAnUnknownStateIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Job(str_repeat('a', 32), '0xPayer', 'key', 'demo_start', 'settled', 1, 1);
    }

    public function testANewJobIsSettlingWithAFreshIdThePayerAndTheTool(): void
    {
        $a = Job::settling('0xPayer', 'key', 'demo_start', ['text' => 'x'], 100, sensitive: true);
        $b = Job::settling('0xPayer', 'key', 'demo_start', ['text' => 'x'], 100);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a->id);
        self::assertNotSame($a->id, $b->id);
        self::assertSame([Job::SETTLING, '0xPayer', 'key', 'demo_start', 100, true], [$a->state, $a->payer, $a->settlementKey, $a->tool, $a->createdAt, $a->sensitive]);
        self::assertTrue($a->to(Job::QUEUED, 110)->to(Job::RUNNING, 120)->to(Job::READY, 130, ['ok' => 1])->sensitive, 'the flag outlives the input that set it');
    }

    public function testAnEvmPayerIsOneWalletWhateverItsCase(): void
    {
        $job = Job::settling('0x2c7536E3605D9C16a7a3D7b1898e529396a65c23', 'key', 'demo_start', [], 100);

        self::assertTrue($job->paidBy('0x2c7536e3605d9c16a7a3d7b1898e529396a65c23'));
        self::assertTrue($job->paidBy('0x2C7536E3605D9C16A7A3D7B1898E529396A65C23'));
        self::assertFalse($job->paidBy('0x2c7536e3605d9c16a7a3d7b1898e529396a65c24'));
        // Base58 has case: a Solana address is compared byte for byte.
        self::assertFalse(Job::settling('So1anaPayer', 'key', 'demo_start', [], 100)->paidBy('so1anapayer'));
    }

    public function testArrayRoundTrip(): void
    {
        $job = Job::settling('0xPayer', 'key', 'demo_start', ['text' => 'x'], 100, true)->to(Job::QUEUED, 120)->to(Job::RUNNING, 150)->to(Job::FAILED_OURS, 200, reason: 'our_timeout');

        self::assertEquals($job, Job::fromArray(json_decode(json_encode($job->toArray(), \JSON_THROW_ON_ERROR), true)));
    }

    public function testCapturingNeedsAResult(): void
    {
        $this->expectException(IllegalTransition::class);
        $this->in(Job::RUNNING, self::HOLD)->to(Job::CAPTURING, 200);
    }

    public function testTheHoldSurvivesTransitionsForgetAndTheStore(): void
    {
        $job = $this->in(Job::RUNNING, self::HOLD)->to(Job::CAPTURING, 200, ['ok' => true])->withHold(['end' => 'captured'])->to(Job::READY, 300)->forget();

        self::assertSame('captured', $job->hold['end'] ?? null);
        self::assertSame(300, $job->updatedAt);
        self::assertEquals($job, Job::fromArray(json_decode(json_encode($job->toArray()), true)));
    }

    public function testOnlyAHoldJobHasHoldBookkeeping(): void
    {
        $this->expectException(IllegalTransition::class);
        $this->in(Job::READY)->withHold(['end' => 'captured']);
    }
}
