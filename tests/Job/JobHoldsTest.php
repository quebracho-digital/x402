<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests\Job;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\FileLedger;
use Quebracho\X402\HoldOperator;
use Quebracho\X402\Job\FileJobStore;
use Quebracho\X402\Job\Job;
use Quebracho\X402\Job\JobFailed;
use Quebracho\X402\Job\JobHolds;
use Quebracho\X402\Job\JobPayments;
use Quebracho\X402\Job\JobReconciler;
use Quebracho\X402\Job\JobRunner;
use Quebracho\X402\Job\JobWork;

/**
 * A job paid with an `auth-capture` hold, against an escrow kept in memory that enforces what the real one
 * does: capture and void need a hold, take all of it here, and exclude each other.
 */
final class JobHoldsTest extends TestCase
{
    private const NETWORK = 'eip155:84532';
    private const PAYER = '0x2c7536e3605d9c16a7a3d7b1898e529396a65c23';

    private string $dir;
    private FileJobStore $jobs;
    private int $now = 1_800_000_000;
    private object $escrow;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/jobholds-'.bin2hex(random_bytes(4));
        $this->jobs = new FileJobStore($this->dir);
        $this->escrow = new class(self::NETWORK) implements HoldOperator {
            public bool $collected = true;
            public string $capturable = '20000';
            public string $refundable = '0';
            /** @var list<string> */
            public array $sent = [];
            /** What the next send answers when it does not land: a reason, and whether a hash went out first. */
            public ?array $fail = null;
            public bool $readable = true;

            public function __construct(private string $network)
            {
            }

            public function network(): string
            {
                return $this->network;
            }

            public function state(array $info): array
            {
                if (!$this->readable) {
                    throw new \RuntimeException('node down');
                }

                return ['collected' => $this->collected, 'capturable' => $this->capturable, 'refundable' => $this->refundable];
            }

            public function capture(array $info, ?string $amount = null, ?\Closure $reference = null): array
            {
                return $this->send('capture', $reference, function () {
                    if ('0' === $this->capturable) {
                        return false; // InsufficientAuthorization
                    }
                    [$this->refundable, $this->capturable] = [$this->capturable, '0'];

                    return true;
                });
            }

            public function void(array $info, ?\Closure $reference = null): array
            {
                return $this->send('void', $reference, function () {
                    if ('0' === $this->capturable) {
                        return false; // ZeroAuthorization
                    }
                    $this->capturable = '0';

                    return true;
                });
            }

            private function send(string $call, ?\Closure $reference, \Closure $effect): array
            {
                if (null !== $this->fail) {
                    [$reason, $hashed] = $this->fail;
                    if ($hashed && null !== $reference) {
                        $reference('0xfeed');
                    }

                    return ['success' => false, 'transaction' => $hashed ? '0xfeed' : '', 'errorReason' => $reason];
                }
                if (null !== $reference) {
                    $reference('0x'.\count($this->sent));
                }
                $this->sent[] = $call;

                return $effect() ? ['success' => true, 'transaction' => '0x1'] : ['success' => false, 'transaction' => '0x1', 'errorReason' => 'transaction_reverted'];
            }
        };
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir, $this->dir.'-ledger'] as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }

    private function holds(): JobHolds
    {
        return new JobHolds($this->jobs, [$this->escrow], fn (): int => $this->now);
    }

    private function hold(): array
    {
        return ['network' => self::NETWORK, 'info' => ['operator' => '0x1', 'maxAmount' => '20000', 'preApprovalExpiry' => $this->now + 60, 'authorizationExpiry' => JobHolds::captureDeadline($this->now)], 'end' => null, 'transaction' => null, 'sentAt' => null];
    }

    private function queued(): Job
    {
        $job = $this->jobs->create(Job::settling(self::PAYER, 'key-'.bin2hex(random_bytes(4)), 'demo_start', ['text' => 'x'], $this->now, hold: $this->hold()));

        return $this->jobs->update($job->id, fn (Job $j) => $j->to(Job::QUEUED, $this->now));
    }

    private function runner(?\Throwable $fails = null): JobRunner
    {
        $work = new class($fails) implements JobWork {
            public function __construct(private ?\Throwable $fails)
            {
            }

            public static function start(): string
            {
                return 'demo_start';
            }

            public function check(array $args): ?string
            {
                return null;
            }

            public function refuse(): ?string
            {
                return null;
            }

            public function run(array $params): array
            {
                return null === $this->fails ? ['answer' => 42] : throw $this->fails;
            }
        };

        return new JobRunner($this->jobs, [$work], fn (): int => $this->now, $this->holds());
    }

    public function testDoneWorkIsCapturedThenReady(): void
    {
        $job = $this->runner()->run($this->queued()->id);

        self::assertSame(Job::READY, $job->state);
        self::assertSame(['answer' => 42], $job->result);
        self::assertSame('captured', $job->hold['end'] ?? null);
        self::assertSame(['capture'], $this->escrow->sent);
        self::assertSame('20000', $this->escrow->refundable);
    }

    public function testOurFailureVoidsTheWholeHold(): void
    {
        $job = $this->runner(new JobFailed('provider_error'))->run($this->queued()->id);

        self::assertSame(Job::FAILED_OURS, $job->state);
        self::assertSame('released', $job->hold['end'] ?? null);
        self::assertSame(['void'], $this->escrow->sent);
        self::assertSame(['0', '0'], [$this->escrow->capturable, $this->escrow->refundable]);
    }

    public function testAnUnclearCaptureWaitsThenTheEscrowDecides(): void
    {
        // The capture went out and its answer was lost: not ready (we cannot know we were paid), not retried at once.
        $this->escrow->fail = ['settlement_pending', true];
        $job = $this->runner()->run($this->queued()->id);
        self::assertSame(Job::CAPTURING, $job->state);
        self::assertSame('0xfeed', $job->hold['transaction'] ?? null);
        self::assertSame($job, $this->holds()->reconcile($job));

        // It landed after all: the escrow says captured, the job is ready, nothing is sent again.
        $this->escrow->fail = null;
        $this->escrow->refundable = '20000';
        $this->escrow->capturable = '0';
        $this->now += JobHolds::MIN_AGE;
        $job = $this->holds()->reconcile($job);

        self::assertSame(Job::READY, $job->state);
        self::assertSame([], $this->escrow->sent);
    }

    public function testACaptureThatNeverLeftIsSentAgainByTheCron(): void
    {
        $this->escrow->fail = ['gas_too_expensive', false];
        $job = $this->runner()->run($this->queued()->id);
        self::assertSame(Job::CAPTURING, $job->state);

        $this->escrow->fail = null;
        $rows = $this->holds()->sweep();

        self::assertSame([['job' => $job->id, 'was' => Job::CAPTURING, 'result' => 'ready/captured']], $rows);
        self::assertSame(['capture'], $this->escrow->sent);
    }

    public function testAHoldThatWentBackBeforeWeCapturedFailsTheJobAsOurs(): void
    {
        $this->escrow->fail = ['gas_too_expensive', false];
        $job = $this->runner()->run($this->queued()->id);
        // The buyer reclaimed after the deadline (or anything else emptied the hold).
        $this->escrow->fail = null;
        $this->escrow->capturable = '0';
        $job = $this->holds()->reconcile($job);

        self::assertSame(Job::FAILED_OURS, $job->state);
        self::assertSame('capture_missed', $job->reason);
        self::assertSame('released', $job->hold['end'] ?? null);
        self::assertNull($job->result);
    }

    public function testNoCaptureIsSentAtTheDeadline(): void
    {
        $this->escrow->fail = ['gas_too_expensive', false];
        $job = $this->runner()->run($this->queued()->id);
        $this->escrow->fail = null;
        $this->now = JobHolds::captureDeadline(1_800_000_000) - 5;

        self::assertSame(Job::CAPTURING, $this->holds()->reconcile($job)->state);
        self::assertSame([], $this->escrow->sent);
    }

    public function testANodeThatCannotAnswerChangesNothing(): void
    {
        $this->escrow->fail = ['gas_too_expensive', false];
        $job = $this->runner()->run($this->queued()->id);
        $this->escrow->fail = null;
        $this->escrow->readable = false;

        self::assertSame(Job::CAPTURING, $this->holds()->reconcile($job)->state);
    }

    public function testTheReconcilerAsksTheEscrowAboutADeposit(): void
    {
        $reconciler = fn () => new JobReconciler($this->jobs, new JobPayments($ledger = new FileLedger($this->dir.'-ledger')), $ledger, $this->holds());
        $held = $this->jobs->create(Job::settling(self::PAYER, 'held', 'demo_start', ['text' => 'x'], $this->now, hold: $this->hold()));
        $this->now += JobReconciler::MIN_AGE;

        // Collected: the money is in the escrow for us, whatever the ledger forgot.
        self::assertSame([['job' => $held->id, 'was' => Job::SETTLING, 'result' => 'queued']], $reconciler()->reconcile($this->now));

        // Never collected, and the signature expired: it can never be, the row goes.
        $this->escrow->collected = false;
        $lost = $this->jobs->create(Job::settling(self::PAYER, 'lost', 'demo_start', ['text' => 'x'], $this->now, hold: $this->hold()));
        $this->now += 3600;
        self::assertSame([['job' => $lost->id, 'was' => Job::SETTLING, 'result' => 'discarded']], $reconciler()->reconcile($this->now));
    }

    public function testATwoPaymentJobIsNotTouched(): void
    {
        $job = $this->jobs->create(Job::settling(self::PAYER, 'plain', 'demo_start', ['text' => 'x'], $this->now));
        $job = $this->jobs->update($job->id, fn (Job $j) => $j->to(Job::QUEUED, $this->now));

        self::assertSame(Job::READY, $this->runner()->run($job->id)->state);
        self::assertSame([], $this->escrow->sent);
        self::assertSame([], $this->holds()->sweep());
    }
}
