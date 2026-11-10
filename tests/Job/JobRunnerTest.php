<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests\Job;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\Job\FileJobSlots;
use Quebracho\X402\Job\FileJobStore;
use Quebracho\X402\Job\Job;
use Quebracho\X402\Job\JobFailed;
use Quebracho\X402\Job\JobJanitor;
use Quebracho\X402\Job\JobRunner;
use Quebracho\X402\Job\JobWork;

final class JobRunnerTest extends TestCase
{
    private string $dir;
    private FileJobStore $jobs;
    /** @var \Closure(array): array */
    private \Closure $does;
    private int $runs = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/job-runner-'.bin2hex(random_bytes(4));
        $this->jobs = new FileJobStore($this->dir);
        $this->does = static fn (array $args) => ['echo' => $args['text']];
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function runner(): JobRunner
    {
        $work = new class($this) implements JobWork {
            public function __construct(private readonly JobRunnerTest $test)
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

            public function run(array $args): array
            {
                return $this->test->work($args);
            }
        };

        return new JobRunner($this->jobs, [$work], static fn () => 500);
    }

    /** @internal for the fake work */
    public function work(array $args): array
    {
        ++$this->runs;

        return ($this->does)($args);
    }

    private function job(string $state = Job::QUEUED, int $at = 100, string $tool = 'demo_start'): Job
    {
        $job = Job::settling('0xPayer', bin2hex(random_bytes(4)), $tool, ['text' => 'hi'], $at);

        return $this->jobs->create(Job::SETTLING === $state ? $job : $job->to(Job::QUEUED, $at));
    }

    public function testAQueuedJobRunsToReadyWithTheWorksAnswer(): void
    {
        $end = $this->runner()->run($this->job()->id);

        self::assertSame([Job::READY, ['echo' => 'hi'], 500], [$end->state, $end->result, $end->updatedAt]);
        self::assertSame(Job::READY, $this->jobs->get($end->id)?->state, 'written');
    }

    public function testAFailureFromTheListIsFiledWithItsReason(): void
    {
        $this->does = static fn () => throw new JobFailed('invalid_reply');

        self::assertSame([Job::FAILED_OURS, 'invalid_reply'], [($e = $this->runner()->run($this->job()->id))->state, $e->reason]);
    }

    public function testAnyOtherThrowIsOursToo(): void
    {
        // The input was checked before the deposit: a bug or a dead host is not the buyer's fault.
        $this->does = static fn () => throw new \TypeError('boom');

        self::assertSame('provider_error', $this->runner()->run($this->job()->id)->reason);
    }

    public function testASettlingJobNeverRuns(): void
    {
        // Its deposit is not confirmed (decision 7c): nothing runs from settling.
        $job = $this->job(Job::SETTLING);

        self::assertSame(Job::SETTLING, $this->runner()->run($job->id)->state);
        self::assertSame(0, $this->runs);
    }

    public function testAJobRunsOnce(): void
    {
        $id = $this->job()->id;
        $this->runner()->run($id);
        $this->runner()->run($id);

        self::assertSame(1, $this->runs, 'the second runner found it ready, not queued');
    }

    public function testAJobWithNoWorkFailsOurs(): void
    {
        self::assertSame([Job::FAILED_OURS, 'provider_error'], [($e = $this->runner()->run($this->job(tool: 'gone_start')->id))->state, $e->reason]);
    }

    public function testALateAnswerAfterTheJanitorGaveUpIsDropped(): void
    {
        $id = $this->job()->id;
        $this->does = function (): array {
            // While the work runs, the janitor finds it stale.
            (new JobJanitor($this->jobs))->sweep(10_000, 60);

            return ['late' => true];
        };

        $end = $this->runner()->run($id);
        self::assertSame([Job::FAILED_OURS, 'our_timeout', null], [$end->state, $end->reason, $end->result]);
    }

    public function testTheJanitorFailsOnlyStaleQueuedOrRunningJobs(): void
    {
        $stale = [$this->job(at: 100), $this->jobs->update($this->job(at: 100)->id, static fn (Job $j) => $j->to(Job::RUNNING, 100))];
        $fresh = $this->job(at: 950);
        $settling = $this->job(Job::SETTLING, 100);
        $delivering = $this->jobs->update($this->job(at: 100)->id, static fn (Job $j) => $j->to(Job::RUNNING, 100)->to(Job::READY, 100, ['x' => 1])->to(Job::DELIVERING, 100));

        $failed = (new JobJanitor($this->jobs))->sweep(1000, 300);

        self::assertEqualsCanonicalizing([$stale[0]->id, $stale[1]->id], $failed);
        foreach ($stale as $job) {
            self::assertSame([Job::FAILED_OURS, 'our_timeout'], [$this->jobs->get($job->id)?->state, $this->jobs->get($job->id)?->reason]);
        }
        self::assertSame(Job::QUEUED, $this->jobs->get($fresh->id)?->state);
        self::assertSame(Job::SETTLING, $this->jobs->get($settling->id)?->state, "the reconciler's");
        self::assertSame(Job::DELIVERING, $this->jobs->get($delivering->id)?->state, 'its payment may be on chain');
        self::assertSame([], (new JobJanitor($this->jobs))->sweep(1000, 300), 'a second sweep has nothing to do');
    }

    public function testACronRunsAQueuedJobNoRequestRanInAFreeSlotAndCleansUp(): void
    {
        $orphan = $this->job(at: time() - JobJanitor::ORPHAN_AFTER);
        $fresh = $this->job(at: time());
        $delivered = $this->jobs->update($this->job(at: 100)->id, static fn (Job $j) => $j->to(Job::RUNNING, 100)->to(Job::READY, 100, ['x' => 1])->to(Job::DELIVERING, 100)->to(Job::DELIVERED, 100));
        $janitor = new JobJanitor($this->jobs);
        $slots = new FileJobSlots($this->dir.'-slots', 1);
        try {
            // What an application's cron does each minute: the orphans, each in a free slot, then the TTLs.
            foreach ($janitor->orphans(time()) as $id) {
                $slot = $slots->take();
                self::assertNotNull($slot);
                $this->runner()->run($id);
                $slot->release();
            }
            $janitor->forget(time());
        } finally {
            exec('rm -rf '.escapeshellarg($this->dir.'-slots'));
        }

        self::assertSame(Job::READY, $this->jobs->get($orphan->id)?->state, 'run by the cron, in the free slot');
        self::assertSame(Job::QUEUED, $this->jobs->get($fresh->id)?->state, 'its own request may still run it');
        self::assertNull($this->jobs->get($delivered->id)?->result, 'past Job::RESULT_TTL');
    }

    public function testASlotIsTakenOnceAndFreedByReleaseOrByItsHolderGoingAway(): void
    {
        $slots = new FileJobSlots($this->dir.'-slots', 2);
        try {
            $one = $slots->take();
            $two = $slots->take();
            self::assertNotNull($one);
            self::assertNotNull($two);
            self::assertNull($slots->take(), 'both busy: never waits');
            $one->release();
            $one->release();
            $again = $slots->take();
            self::assertNotNull($again, 'a released slot is free');
            unset($two);
            self::assertNotNull($slots->take(), 'a slot whose holder is gone is free');
        } finally {
            exec('rm -rf '.escapeshellarg($this->dir.'-slots'));
        }
    }

    public function testThereMustBeAtLeastOneSlot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileJobSlots($this->dir.'-slots', 0);
    }
}
