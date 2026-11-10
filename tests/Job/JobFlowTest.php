<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests\Job;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\Authorization;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileLedger;
use Quebracho\X402\Job\FileJobSlots;
use Quebracho\X402\Job\FileJobStore;
use Quebracho\X402\Job\IllegalTransition;
use Quebracho\X402\Job\Job;
use Quebracho\X402\Job\JobDelivery;
use Quebracho\X402\Job\JobDeposit;
use Quebracho\X402\Job\JobDesk;
use Quebracho\X402\Job\JobFailed;
use Quebracho\X402\Job\JobIdOnRefusal;
use Quebracho\X402\Job\JobJanitor;
use Quebracho\X402\Job\JobPayments;
use Quebracho\X402\Job\JobReconciler;
use Quebracho\X402\Job\JobRunner;
use Quebracho\X402\Job\JobSlot;
use Quebracho\X402\Job\JobSlots;
use Quebracho\X402\Job\JobStatus;
use Quebracho\X402\Job\JobWork;
use Quebracho\X402\PriceList;
use Quebracho\X402\Reconciler;
use Quebracho\X402\SensitiveRules;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\X402McpMiddleware;

/**
 * Job mode end to end, through the real X402McpMiddleware (EIP-3009 signatures checked for real, a file
 * ledger), with JobDesk's hooks wired as an application wires them and three thin job tools (`_start`,
 * `_result`, `_status`) as an MCP server would serve them. Only the facilitator and the work are fakes:
 * nothing leaves the host. Each request() is one HTTP request, afterResponse() included.
 */
final class JobFlowTest extends TestCase
{
    public const START = 'analyze_terms_start';
    public const RESULT = 'analyze_terms_result';
    public const STATUS = 'analyze_terms_status';
    /** The job tools, as an application declares them. */
    private const ROLES = [
        self::START => ['role' => 'start', 'pairedWith' => self::RESULT, 'price' => '0.01'],
        self::RESULT => ['role' => 'result', 'pairedWith' => self::START, 'price' => '0.01'],
    ];
    private const PAY_TO = '0xcb2d7f50D917FeD02b82ef08cEfBF9827463B794';
    private const BUYER = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';
    private const BUYER_ADDRESS = '0x2c7536E3605D9C16a7a3D7b1898e529396a65c23';
    private const OTHER = '0x59c6995e998f97a5a0044966f0945389dc9e86dae88c7a8412f4603b6b78690d';
    private const TEXT = 'We may suspend your account at any time.';

    private string $dir;
    private FileJobStore $jobs;
    private FileLedger $ledger;
    private PriceList $pricing;
    private JobSlots $slots;
    private JobDesk $desk;
    private \Closure $facilitator;
    /** @var list<array{payment: array, req: array}> what the facilitator was asked to settle */
    private array $settled = [];
    /** @var list<array> the receipts the facilitator answers with, in turn; success when it runs out */
    private array $receipts = [];
    /** @var ?\Closure(array): void sees each payment as the facilitator receives it */
    private ?\Closure $onSettle = null;
    /** @var ?\Closure(): array the work; default: one category found */
    private ?\Closure $work = null;
    private ?string $refuse = null;
    /** @var ?\Closure(): void runs in the MCP server just before the tool: a test kills the process here */
    private ?\Closure $beforeTool = null;
    /** @var ?\Closure(array, array): ?string the facilitator's online verify; none by default */
    private ?\Closure $verify = null;
    /** What the token's authorizationState answers the reconciler: NULL is a node that cannot answer. */
    private ?bool $chainUsed = null;
    /** @var list<array{0: string, 1: string, 2: string}> what the reconciler asked the chain */
    private array $chainAsked = [];
    /** The clock of the desk, the runner and the reconciler; NULL is the real time. */
    private ?int $now = null;
    private int $workRuns = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/job-flow-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $this->jobs = new FileJobStore($this->dir.'/jobs');
        $this->ledger = new FileLedger($this->dir.'/ledger');
        $this->pricing = new PriceList('eip155:84532', self::PAY_TO, '0.01', ['wisdom'], jobs: self::ROLES);
        $this->slots = new FileJobSlots($this->dir.'/slots', 1);
        $this->facilitator = function (array $payment, array $req): array {
            $this->onSettle && ($this->onSettle)($payment);
            $this->settled[] = ['payment' => $payment, 'req' => $req];

            return array_shift($this->receipts) ?? Settlement::mock()($payment, $req);
        };
        $this->desk = $this->desk();
    }

    protected function tearDown(): void
    {
        unset($this->desk);
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function desk(): JobDesk
    {
        $clock = fn (): int => $this->now ?? time();
        $rules = (new SensitiveRules([['kind' => 'argument', 'name' => 'sensitive', 'value' => true]]))(...);

        return new JobDesk(
            new JobDeposit($this->jobs, $this->runner(), $this->slots, $this->pricing->__invoke(...), self::ROLES, $rules, $clock),
            new JobDelivery($this->jobs, self::ROLES, $this->reconciler(), new JobJanitor($this->jobs), $rules, $clock),
            $this->facilitator, self::ROLES, $this->validate(...), $rules,
        );
    }

    /** The application's own check of a call, before any payment is read: here, what the tools' schemas ask. */
    private function validate(array $params): ?string
    {
        $args = $params['arguments'] ?? [];

        return match ($params['name']) {
            self::START => \is_string($args['text'] ?? null) ? null : 'text: a string is required',
            self::RESULT => \is_string($args['jobId'] ?? null) ? null : 'jobId: a string is required',
            default => null,
        };
    }

    private function runner(): JobRunner
    {
        $flow = $this;
        $work = new class($flow) implements JobWork {
            public function __construct(private readonly JobFlowTest $flow)
            {
            }

            public static function start(): string
            {
                return JobFlowTest::START;
            }

            public function check(array $args): ?string
            {
                return '' === trim((string) ($args['text'] ?? '')) ? 'Send some text.' : null;
            }

            public function refuse(): ?string
            {
                return $this->flow->refusal();
            }

            public function run(array $args): array
            {
                return $this->flow->runWork($args);
            }
        };

        return new JobRunner($this->jobs, [$work], fn (): int => $this->now ?? time());
    }

    /** The reconciler as wired: this ledger, and a fake chain (nothing leaves the host). */
    private function reconciler(): JobReconciler
    {
        return new JobReconciler($this->jobs, new JobPayments($this->ledger, function (string $network, string $from, string $nonce): bool {
            $this->chainAsked[] = [$network, $from, $nonce];

            return $this->chainUsed ?? throw new \RuntimeException('node down');
        }), $this->ledger);
    }

    /** @internal for the fake work */
    public function refusal(): ?string
    {
        return $this->refuse;
    }

    /** @internal for the fake work */
    public function runWork(array $args): array
    {
        ++$this->workRuns;
        if (null !== $this->work) {
            return ($this->work)($args);
        }

        return ['model' => 'fake', 'categories' => ['unilateral_termination' => [self::TEXT]], 'quotes_dropped' => 0];
    }

    private function middleware(): X402McpMiddleware
    {
        $f = new Psr17Factory();

        // The seller's clock is the test's: a signature expires when $this->now passes its validBefore.
        return new X402McpMiddleware([new EvmExact(null, $this->clock(...))], $this->pricing->__invoke(...), static fn () => true, $f, $f, $this->desk->settle(...),
            validate: $this->desk->validate(...), payer: $this->desk->payer(...), ledger: $this->ledger, verify: $this->verify, sensitive: $this->desk->sensitive(...));
    }

    private function clock(): int
    {
        return $this->now ?? time();
    }

    /** The MCP server: three thin job tools, as an application writes them over the desk. */
    private function server(): RequestHandlerInterface
    {
        return new class($this->desk, $this->beforeTool) implements RequestHandlerInterface {
            public function __construct(private readonly JobDesk $desk, private readonly ?\Closure $before)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $message = json_decode((string) $request->getBody(), true);
                $args = $message['params']['arguments'];
                $this->before && ($this->before)();
                try {
                    $answer = match ($message['params']['name']) {
                        JobFlowTest::START => $this->start($args, $message['params']['_meta'][X402McpMiddleware::PAYMENT] ?? null),
                        JobFlowTest::RESULT => $this->result($args['jobId']),
                        JobFlowTest::STATUS => $this->desk->delivery->status($args['jobId'], $args['payer']),
                    };
                    $result = ['content' => [['type' => 'text', 'text' => json_encode($answer, \JSON_THROW_ON_ERROR)]]];
                } catch (\DomainException $e) {
                    $result = ['isError' => true, 'content' => [['type' => 'text', 'text' => $e->getMessage()]]];
                }

                return new Response(200, ['Content-Type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => $message['id'], 'result' => $result], \JSON_THROW_ON_ERROR));
            }

            private function start(array $args, mixed $payment): array
            {
                try {
                    $job = $this->desk->deposit->queue(JobFlowTest::START, ['text' => $args['text'], 'sensitive' => $args['sensitive'] ?? false], \is_array($payment) ? $payment : null);
                } catch (\Throwable $e) {
                    throw new \DomainException('The job could not be queued; your deposit is on record for a refund by hand.', 0, $e);
                }

                return ['jobId' => $job->id, 'status' => JobFlowTest::STATUS, 'result' => JobFlowTest::RESULT] + JobStatus::of($job->id, $job, $job->payer);
            }

            private function result(string $jobId): array
            {
                try {
                    $job = $this->desk->delivery->deliver($jobId);
                } catch (IllegalTransition|\OutOfBoundsException $e) {
                    throw new \DomainException('This job is not ready for this payment. Nothing was charged.', 0, $e);
                }

                return ['jobId' => $job->id] + ($job->result ?? []);
            }
        };
    }

    /** One HTTP request to /mcp, then afterResponse() (the runner): the tool's JSON-RPC result. */
    private function request(string $tool, array $args, ?array $payment = null): array
    {
        $params = ['name' => $tool, 'arguments' => $args];
        if (null !== $payment) {
            $params['_meta'][X402McpMiddleware::PAYMENT] = $payment;
        }
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => $params], \JSON_THROW_ON_ERROR);
        try {
            // As McpController stacks them: JobIdOnRefusal around the x402 middleware, around the MCP server.
            $x402 = new class($this->middleware(), $this->server()) implements RequestHandlerInterface {
                public function __construct(private readonly X402McpMiddleware $x402, private readonly RequestHandlerInterface $server)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->x402->process($request, $this->server);
                }
            };
            $request = new ServerRequest('POST', 'https://example.test/mcp', ['Content-Type' => 'application/json'], $body);
            $out = json_decode((string) (new JobIdOnRefusal($this->desk->deposit, new Psr17Factory()))->process($request, $x402)->getBody(), true);
        } finally {
            $this->desk->afterResponse();
        }

        return $out['result'] ?? ['error' => $out['error']];
    }

    /** The buyer signs the offer the unpaid call returns. */
    private function sign(string $tool, array $args, string $key = self::BUYER): array
    {
        $required = $this->request($tool, $args)['structuredContent'];

        return (new EvmExact(new Signer($key), $this->clock(...)))->pay($required, $required['accepts'][0]);
    }

    private static function answer(array $result): array
    {
        return json_decode($result['content'][0]['text'], true);
    }

    /** @return array{0: array, 1: array} the `_start` answer and the deposit */
    private function start(array $args = ['text' => self::TEXT]): array
    {
        $deposit = $this->sign(JobFlowTest::START, $args);

        return [$this->request(JobFlowTest::START, $args, $deposit), $deposit];
    }

    /** The tools/call params of a signed `_result`, as the middleware hands them to the payer hook. */
    private function resultParams(string $jobId): array
    {
        return ['name' => JobFlowTest::RESULT, 'arguments' => ['jobId' => $jobId], '_meta' => [X402McpMiddleware::PAYMENT => $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId])]];
    }

    private function jobStatus(string $jobId, string $payer = self::BUYER_ADDRESS): array
    {
        return self::answer($this->request(JobFlowTest::STATUS, ['jobId' => $jobId, 'payer' => $payer]));
    }

    public function testAJobPaidInTwoParts(): void
    {
        [$started, $deposit] = $this->start();
        self::assertTrue($started['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success'], 'the deposit settled before the answer');
        $jobId = self::answer($started)['jobId'];
        self::assertSame('queued', self::answer($started)['state']);
        self::assertSame(1, $this->workRuns, 'afterResponse() ran it');

        self::assertSame(['jobId' => $jobId, 'state' => 'ready', 'reason' => 'result_ready', 'retryable' => false, 'retryAfter' => null], $this->jobStatus($jobId));
        $job = $this->jobs->get($jobId);
        self::assertNull($job?->params, 'the buyer text left the disk once the work ran');
        self::assertSame('upfront', $this->settled[0]['req']['extra']['paymentFlow']);

        $result = $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]));
        self::assertTrue($result['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
        self::assertSame([self::TEXT], self::answer($result)['categories']['unilateral_termination']);
        self::assertCount(2, $this->settled, 'a deposit and the rest');
        self::assertSame('delivered', $this->jobStatus($jobId)['state']);
        self::assertNotNull($this->jobs->get($jobId)?->result, 'kept Job::RESULT_TTL for a re-read');
        $this->now = time() + Job::RESULT_TTL;
        (new JobJanitor($this->jobs))->forget($this->now);
        self::assertNull($this->jobs->get($jobId)?->result, 'then it leaves the job');
        $this->now = null;

        // The same deposit again: the same job, never a second one nor a duplicate_settlement (7c).
        self::assertSame($jobId, self::answer($this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit))['jobId']);
        self::assertCount(1, iterator_to_array($this->jobs->all(), false));
        self::assertCount(2, $this->settled);
    }

    public function testTheJobRowIsWrittenBeforeTheDepositSettles(): void
    {
        $seen = null;
        $this->onSettle = function (array $payment) use (&$seen): void {
            $seen = $this->jobs->bySettlement(Settlement::key($payment));
        };
        $this->start();

        self::assertSame(Job::SETTLING, $seen?->state, 'the facilitator was asked only once the row existed');
        self::assertSame(['text' => self::TEXT], $seen->params, 'with what the job needs to resume');
        self::assertTrue($seen->paidBy(self::BUYER_ADDRESS));
    }

    /**
     * Where a SIGKILL or an OOM leaves the disk: the job store and the ledger exactly as they are at this
     * instant. A thrown \Error would not do: the middleware catches it and writes 'settled' with
     * handler_ran false, which a dead process never gets to write. restoreTheKill() puts this copy back
     * once the request (which in a test still runs its catch and finally blocks) is over.
     */
    private function killHere(): never
    {
        mkdir($this->dir.'/killed');
        foreach (['jobs', 'ledger'] as $sub) {
            is_dir("{$this->dir}/$sub") && exec('cp -a '.escapeshellarg("{$this->dir}/$sub").' '.escapeshellarg("{$this->dir}/killed/$sub"));
        }

        throw new \RuntimeException('killed');
    }

    /** The request that was killed: what it wrote after killHere() never happened. */
    private function restoreTheKill(\Closure $request): void
    {
        try {
            // Killed inside the settle, the library turns the throw into a failed receipt; inside the tool, it goes on.
            $request();
        } catch (\RuntimeException $e) {
            self::assertSame('killed', $e->getMessage());
        }
        self::assertDirectoryExists($this->dir.'/killed', 'the kill happened');
        foreach (['jobs', 'ledger'] as $sub) {
            exec('rm -rf '.escapeshellarg("{$this->dir}/$sub"));
            is_dir("{$this->dir}/killed/$sub") && rename("{$this->dir}/killed/$sub", "{$this->dir}/$sub");
        }
        exec('rm -rf '.escapeshellarg($this->dir.'/killed'));
        $this->beforeTool = $this->onSettle = null;
        // A new process.
        $this->desk = $this->desk();
    }

    /**
     * The case a real clock shows: the deposit's signature lives Networks::TIMEOUT (60 s), the reconciler looks at
     * a `settling` row only after JobReconciler::MIN_AGE (60 s), and the ledger's state dies with the signature.
     * A buyer who waited for a retry would find its signature expired and never learn the job; every `_start`
     * refusal after the row exists carries the jobId instead, and the buyer polls `_status`.
     */
    public function testAKillAfterTheDepositSettledLeavesTheBuyerAJobToPollEvenAfterItsSignatureExpired(): void
    {
        $deposit = $this->sign(JobFlowTest::START, ['text' => self::TEXT]);
        // Killed after the settle, before the tool queued the job: the ledger says 'handling', the buyer got nothing.
        $this->beforeTool = fn () => $this->killHere();
        $this->restoreTheKill(fn () => $this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit));
        $key = Settlement::key($deposit);
        $row = $this->jobs->bySettlement($key);
        self::assertSame([Job::SETTLING, 'handling'], [$row?->state, $this->ledger->get($key)['status'] ?? null]);
        self::assertSame(0, $this->workRuns, 'no job runs until its settle is confirmed');

        // A retry at once: the library will not run an upfront tool twice, but the refusal names the job.
        $retry = $this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit);
        self::assertSame(['needs_review', $row->id], [$retry['structuredContent']['error'], $retry['structuredContent']['jobId'] ?? null]);
        self::assertSame($retry['structuredContent'], json_decode($retry['content'][0]['text'], true), 'the same in the text');
        self::assertSame('settling', $this->jobStatus($row->id)['state']);

        // When the reconciler first looks (MIN_AGE later) the signature has expired, and the ledger's state with it
        // (its TTL ends at validBefore: released here, as FileLedger's real-time TTL would).
        $validBefore = (int) $deposit['payload']['authorization']['validBefore'];
        $this->now = time() + JobReconciler::MIN_AGE + Reconciler::GRACE;
        self::assertLessThan($this->now + EvmExact::SLACK, $validBefore, 'expired for the seller');
        $this->ledger->release($key);
        $late = $this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit)['structuredContent'];
        self::assertSame(['invalid_exact_evm_payload_authorization_valid_before', $row->id], [$late['error'], $late['jobId'] ?? null], 'a late retry still learns its job');

        // Only the chain can tell now: the authorization was used, the deposit moved, the job is queued.
        $this->chainUsed = true;
        self::assertSame([['job' => $row->id, 'was' => 'settling', 'result' => 'queued']], $this->reconciler()->reconcile($this->now));
        self::assertSame('queued', $this->jobStatus($row->id)['state']);
        self::assertSame([$row->id], (new JobJanitor($this->jobs))->orphans($this->now + JobJanitor::ORPHAN_AFTER), 'the cron runs it');
        self::assertSame(Job::READY, $this->runner()->run($row->id)->state);
        self::assertSame('ready', $this->jobStatus($row->id)['state']);

        $result = $this->request(JobFlowTest::RESULT, ['jobId' => $row->id], $this->sign(JobFlowTest::RESULT, ['jobId' => $row->id]));
        self::assertSame([self::TEXT], self::answer($result)['categories']['unilateral_termination']);
        self::assertCount(2, $this->settled, 'the deposit settled once, then the rest');
        self::assertSame(1, $this->workRuns);
    }

    public function testOnlyTheDepositsSignerLearnsTheJobIdFromARefusal(): void
    {
        $deposit = $this->sign(JobFlowTest::START, ['text' => self::TEXT]);
        $this->beforeTool = fn () => $this->killHere();
        $this->restoreTheKill(fn () => $this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit));

        // The payer and the nonce are public once the deposit is on chain: a copy signed by another key gets nothing.
        $forged = $deposit;
        $forged['payload']['signature'] = (new Signer(self::OTHER))->sign(Authorization::digest($this->settled[0]['req'], $deposit['payload']['authorization']));
        $refused = $this->request(JobFlowTest::START, ['text' => self::TEXT], $forged)['structuredContent'];
        self::assertSame('invalid_exact_evm_payload_signature', $refused['error']);
        self::assertArrayNotHasKey('jobId', $refused);
    }

    public function testAKillDuringTheDepositSettleIsReconciledFromTheChain(): void
    {
        $deposit = $this->sign(JobFlowTest::START, ['text' => self::TEXT]);
        $this->onSettle = fn () => $this->killHere();
        $this->restoreTheKill(fn () => $this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit));
        $key = Settlement::key($deposit);
        $row = $this->jobs->bySettlement($key);
        self::assertSame([Job::SETTLING, 'settling'], [$row?->state, $this->ledger->get($key)['status'] ?? null]);

        // The buyer's retry cannot be told how the settle ended, but it is told the job.
        self::assertSame(['settlement_unknown', $row->id], array_values(array_intersect_key($this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit)['structuredContent'], ['error' => 1, 'jobId' => 1])));

        // The ledger's 'settling' state is the payment reconciler's (app:x402:reconcile): nothing is decided here.
        $this->now = time() + JobReconciler::MIN_AGE;
        self::assertSame('unknown', $this->reconciler()->reconcile($this->now)[0]['result']);

        // Its state expired with the signature: only the chain can tell. A node that cannot answer changes nothing.
        $this->ledger->release($key);
        $this->now = (int) $deposit['payload']['authorization']['validBefore'] + 120;
        self::assertSame('unknown', $this->reconciler()->reconcile($this->now)[0]['result']);
        self::assertSame([['eip155:84532', strtolower(self::BUYER_ADDRESS), $deposit['payload']['authorization']['nonce']]], $this->chainAsked);

        // The authorization was used: the deposit moved, the job is queued and the janitor's run starts it.
        $this->chainUsed = true;
        self::assertSame('queued', $this->reconciler()->reconcile($this->now)[0]['result']);
        $janitor = new JobJanitor($this->jobs);
        self::assertSame([], $janitor->orphans($this->now), 'not yet: its own request may still run it');
        self::assertSame([$row->id], $janitor->orphans($this->now + JobJanitor::ORPHAN_AFTER));
        self::assertSame(Job::READY, $this->runner()->run($row->id)->state);
    }

    public function testADepositThatNeverMovedIsDiscardedWithItsText(): void
    {
        $deposit = $this->sign(JobFlowTest::START, ['text' => self::TEXT]);
        $this->onSettle = fn () => $this->killHere();
        $this->restoreTheKill(fn () => $this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit));
        $this->ledger->release(Settlement::key($deposit));

        // Unused and expired: it can never move. Still valid, anyone holding it could submit it: left alone.
        $this->chainUsed = false;
        $validBefore = (int) $deposit['payload']['authorization']['validBefore'];
        $this->now = max($validBefore, time() + JobReconciler::MIN_AGE);
        self::assertSame('unknown', $this->reconciler()->reconcile($this->now)[0]['result']);
        $this->now = $validBefore + 120;
        self::assertSame('discarded', $this->reconciler()->reconcile($this->now)[0]['result']);
        self::assertSame([], iterator_to_array($this->jobs->all(), false));
    }

    public function testAnUnresolvedSettlingRowLosesItsTextAfterADayAndFailsAsOursIfPaid(): void
    {
        $deposit = $this->sign(JobFlowTest::START, ['text' => self::TEXT]);
        $this->onSettle = fn () => $this->killHere();
        $this->restoreTheKill(fn () => $this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit));
        $id = $this->jobs->bySettlement(Settlement::key($deposit))?->id;
        $created = $this->jobs->get((string) $id)?->createdAt;

        $janitor = new JobJanitor($this->jobs);
        self::assertSame([], $janitor->forget($created + Job::PARAMS_TTL - 1));
        self::assertSame([$id], $janitor->forget($created + Job::PARAMS_TTL));
        self::assertNull($this->jobs->get((string) $id)?->params, 'the buyer text is off disk');
        self::assertSame(Job::SETTLING, $this->jobs->get((string) $id)?->state, 'the row stays: the deposit may have moved');

        // Found paid later: nothing to run on, so ours (the result is free), never run on an empty text.
        $this->ledger->release(Settlement::key($deposit));
        $this->chainUsed = true;
        $this->reconciler()->reconcile($created + Job::PARAMS_TTL + 60);
        self::assertSame([Job::FAILED_OURS, 'our_timeout'], [$this->jobs->get((string) $id)?->state, $this->jobs->get((string) $id)?->reason]);
        self::assertSame(0, $this->workRuns);
    }

    public function testAClearlyRefusedDepositLeavesNoJobAndTheSamePaymentMayBeRetried(): void
    {
        $this->receipts = [['success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '']];
        $deposit = $this->sign(JobFlowTest::START, ['text' => self::TEXT]);
        $refused = $this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit);

        self::assertSame('insufficient_funds', $refused['structuredContent']['error']);
        self::assertArrayNotHasKey('jobId', $refused['structuredContent'], 'no job to name');
        self::assertSame([], iterator_to_array($this->jobs->all(), false), 'no row, and no buyer text on disk');

        $retry = $this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit);
        self::assertSame('queued', self::answer($retry)['state']);
    }

    /** @return iterable<string, array{0: array}> */
    public static function depositMayHaveMoved(): iterable
    {
        yield 'settlement_pending' => [['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => '0x'.str_repeat('ab', 32), 'network' => 'eip155:84532']];
        yield 'facilitator_unavailable' => [['success' => false, 'errorReason' => 'facilitator_unavailable', 'transaction' => '', 'network' => 'eip155:84532']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('depositMayHaveMoved')]
    public function testADepositThatMayHaveMovedKeepsItsSettlingRowAndNamesIt(array $receipt): void
    {
        $this->receipts = [$receipt];
        [$started, $deposit] = $this->start();
        $row = $this->jobs->bySettlement(Settlement::key($deposit));

        self::assertSame(Job::SETTLING, $row?->state, 'the money may move: the reconciler decides');
        self::assertSame([$receipt['errorReason'], $row->id], [$started['structuredContent']['error'], $started['structuredContent']['jobId'] ?? null], 'the buyer polls it instead of paying again');
        self::assertSame('settling', $this->jobStatus($row->id)['state']);
        self::assertSame(0, $this->workRuns);
    }

    public function testAJobRowThatCannotBeWrittenSendsNothing(): void
    {
        // The job directory is a file: no row can be written, so no settle may be sent.
        touch($this->dir.'/jobs');
        [$refused] = $this->start();

        self::assertSame('jobs_unavailable', $refused['structuredContent']['error']);
        self::assertSame([], $this->settled);
    }

    public function testABusyServerRefusesTheDepositBeforeItSettles(): void
    {
        $held = $this->slots->take();
        self::assertNotNull($held, 'JOB_SLOTS=1, taken by another job');
        [$refused] = $this->start();

        self::assertSame('job_slots_busy', $refused['structuredContent']['error']);
        self::assertSame([], $this->settled);
        self::assertSame([], iterator_to_array($this->jobs->all(), false));

        $held->release();
        [$started] = $this->start();
        self::assertSame('queued', self::answer($started)['state'], 'the slot is free again');
        self::assertNotNull($this->slots->take(), 'and freed once the job ran');
    }

    public function testABrokenLockStoreRefusesTheDepositAndFreesThePayment(): void
    {
        $working = $this->slots;
        $this->slots = new class implements JobSlots {
            public function take(): ?JobSlot
            {
                throw new \RuntimeException('lock store down');
            }
        };
        $this->desk = $this->desk();
        [$refused, $deposit] = $this->start();

        self::assertSame('job_slots_unavailable', $refused['structuredContent']['error'], 'a clean refusal, not unexpected_settle_error');
        self::assertSame([], $this->settled);
        self::assertSame([], iterator_to_array($this->jobs->all(), false));

        // A clear refusal freed the claim: the same deposit goes through once the store is back.
        $this->slots = $working;
        $this->desk = $this->desk();
        self::assertSame('queued', self::answer($this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit))['state']);
    }

    public function testAReadyResultNobodyBuysIsDeletedAfterADay(): void
    {
        $jobId = self::answer($this->start()[0])['jobId'];
        $ready = $this->jobs->get($jobId);
        $janitor = new JobJanitor($this->jobs);

        self::assertSame([], $janitor->forget($ready->updatedAt + Job::READY_TTL - 1));
        self::assertSame([$jobId], $janitor->forget($ready->updatedAt + Job::READY_TTL));
        self::assertNull($this->jobs->get($jobId)?->result, 'the analysis, which quotes the buyer, is off disk');
        self::assertSame(['expired', 'result_expired', false], array_values(array_intersect_key($this->jobStatus($jobId), ['state' => 1, 'reason' => 1, 'retryable' => 1])));

        $result = $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]));
        self::assertSame('job_expired', $result['structuredContent']['error']);
        self::assertCount(1, $this->settled, 'nothing sold for it');
    }

    public function testInMockModeTheChainIsNeverAsked(): void
    {
        $asked = false;
        $payments = new JobPayments($this->ledger, static function () use (&$asked): bool {
            $asked = true;

            return true;
        }, 'mock');
        $ref = ['key' => 'k', 'network' => 'eip155:84532', 'nonce' => '0x'.str_repeat('1', 64), 'validBefore' => 1000];

        self::assertSame(JobPayments::UNKNOWN, $payments->fate($ref, self::BUYER_ADDRESS, 0, 5000, true));
        self::assertFalse($asked, 'nothing moves on chain in mock mode: "unused" would discard a job whose deposit "settled"');
    }

    public function testOurKnownDailyCapRefusesTheDepositBeforeItSettles(): void
    {
        $this->refuse = 'our_daily_cap';
        [$refused] = $this->start();

        self::assertSame('our_daily_cap', $refused['structuredContent']['error']);
        self::assertSame([], $this->settled);
    }

    public function testATextTheWorkWouldRefuseIsNeverPaidFor(): void
    {
        $refused = $this->request(JobFlowTest::START, ['text' => '  ']);

        self::assertSame([-32602, 'invalid_params'], [$refused['error']['code'], $refused['error']['data']['reason']]);
        self::assertSame('Send some text.', $refused['error']['message']);
    }

    public function testOnlyTheDepositsPayerCanBuyTheResultAndOthersLearnNothing(): void
    {
        $jobId = self::answer($this->start()[0])['jobId'];
        $other = $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId], self::OTHER));
        $none = $this->request(JobFlowTest::RESULT, ['jobId' => str_repeat('0', 32)], $this->sign(JobFlowTest::RESULT, ['jobId' => str_repeat('0', 32)]));

        self::assertSame('unknown_job', $other['structuredContent']['error']);
        self::assertSame('unknown_job', $none['structuredContent']['error']);
        self::assertCount(1, $this->settled, 'neither was charged');
        self::assertSame(Job::READY, $this->jobs->get($jobId)?->state);

        // The free status: a wrong payer gets exactly what an unknown id gets.
        $wrong = $this->jobStatus($jobId, '0x0000000000000000000000000000000000000001');
        $unknown = $this->jobStatus(str_repeat('0', 32));
        self::assertSame(['state' => 'unknown', 'reason' => 'unknown_job'], ['state' => $wrong['state'], 'reason' => $wrong['reason']]);
        self::assertSame(array_diff_key($unknown, ['jobId' => 1]), array_diff_key($wrong, ['jobId' => 1]));
        self::assertSame('ready', $this->jobStatus($jobId, strtolower(self::BUYER_ADDRESS))['state'], 'the payer, whatever the case of its address');
    }

    public function testASecondResultPaymentIsRefusedWhileOneIsPending(): void
    {
        $jobId = self::answer($this->start()[0])['jobId'];
        $this->receipts = [['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => '0x'.str_repeat('cd', 32), 'network' => 'eip155:84532']];
        $first = $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]));
        self::assertSame('settlement_pending', $first['structuredContent']['error']);
        self::assertSame(Job::DELIVERING, $this->jobs->get($jobId)?->state, 'its money may be on chain');

        $second = $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]));
        self::assertSame('result_pending', $second['structuredContent']['error']);
        self::assertCount(2, $this->settled, 'the second payment was never sent to settle');
        self::assertSame(['delivering', 'result_settling', true, 10], array_values(array_diff_key($this->jobStatus($jobId), ['jobId' => 1])));
    }

    /** @return array{0: string, 1: array} a job left delivering by a `_result` settle that ended with $receipt, and that payment */
    private function deliveringAfter(array $receipt): array
    {
        $jobId = self::answer($this->start()[0])['jobId'];
        $this->receipts = [$receipt + ['transaction' => '', 'network' => 'eip155:84532']];
        $payment = $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]);
        $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $payment);
        self::assertSame(Job::DELIVERING, $this->jobs->get($jobId)?->state);
        self::assertSame(Settlement::key($payment), $this->jobs->get($jobId)?->resultRef['key'] ?? null, 'the job knows which payment to look up');

        return [$jobId, $payment];
    }

    /** @return array<string, array{0: array}> */
    public static function ambiguous(): iterable
    {
        foreach (['settlement_pending', 'facilitator_unavailable', 'unexpected_settle_error', 'invalid_settle_response'] as $reason) {
            yield $reason => [['success' => false, 'errorReason' => $reason]];
        }
        yield 'a failure with a transaction' => [['success' => false, 'errorReason' => 'transaction_failed', 'transaction' => '0x'.str_repeat('ef', 32)]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ambiguous')]
    public function testAnAmbiguousResultSettleIsNotStuckOnceTheLedgerKnows(array $receipt): void
    {
        [$jobId, $payment] = $this->deliveringAfter($receipt);
        $key = Settlement::key($payment);
        $retry = fn () => $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]));

        // Still in flight for the ledger: a second payment is refused, nothing more is charged.
        self::assertSame('result_pending', $retry()['structuredContent']['error']);
        self::assertSame('unknown', $this->reconciler()->reconcile(time())[0]['result']);

        // The payment reconciler finds it settled (what Reconciler::settle writes): the buyer's next call
        // delivers the job and answers it as a free re-read, with no second settle.
        $this->ledger->put($key, ['status' => 'settled', 'receipt' => ['success' => true, 'transaction' => '0x'.str_repeat('ef', 32), 'network' => 'eip155:84532']], 60);
        $settles = \count($this->settled);
        $reread = $retry();
        self::assertSame([self::TEXT], self::answer($reread)['categories']['unilateral_termination']);
        self::assertSame(['reread' => true, 'charged' => false], $reread['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['extensions'][JobDesk::META]);
        self::assertCount($settles, $this->settled, 'nothing sent to the facilitator');
        self::assertSame(Job::DELIVERED, $this->jobs->get($jobId)?->state);
    }

    public function testAnAmbiguousResultSettleThatTheLedgerReleasedIsReadyAgain(): void
    {
        [$jobId, $payment] = $this->deliveringAfter(['success' => false, 'errorReason' => 'facilitator_unavailable']);
        // The payment reconciler found it unused and freed it (or a lookup refuted it): no state.
        $this->ledger->release(Settlement::key($payment));

        self::assertSame([['job' => $jobId, 'was' => 'delivering', 'result' => 'ready']], $this->reconciler()->reconcile(time()));
        self::assertSame([], $this->chainAsked, 'a released state, inside its lifetime, says enough');
        $paid = $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]));
        self::assertTrue($paid['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success'], 'the buyer pays once more');
    }

    public function testAnAmbiguousResultSettleTheLedgerForgotIsAskedOfTheChain(): void
    {
        [$jobId, $payment] = $this->deliveringAfter(['success' => false, 'errorReason' => 'settlement_pending']);
        $this->ledger->release(Settlement::key($payment));
        // Long after: the ledger forgets every state by then, so no state proves nothing; the chain says it moved.
        $this->now = (int) $payment['payload']['authorization']['validBefore'] + 120;
        self::assertSame('result_pending', $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]))['structuredContent']['error'] ?? null, 'a request does not ask the chain');
        $this->chainUsed = true;

        self::assertSame('delivered', $this->reconciler()->reconcile($this->now)[0]['result']);
        self::assertTrue($this->jobs->get($jobId)?->rereadable($this->now), 'its payer may read it once more');
    }

    public function testAPaidResultWhoseAnswerWasLostIsReadAgainForFreeByItsPayerOnly(): void
    {
        // Sensitive: the ledger keeps no copy, so a retry of the same payment cannot replay the answer.
        $jobId = self::answer($this->start(['text' => self::TEXT, 'sensitive' => true])[0])['jobId'];
        $paid = $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]);
        $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $paid);
        self::assertSame('already_settled', $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $paid)['structuredContent']['error']);
        $settles = \count($this->settled);

        $other = $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId], self::OTHER));
        self::assertSame('unknown_job', $other['structuredContent']['error']);
        $again = $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]));
        self::assertSame([self::TEXT], self::answer($again)['categories']['unilateral_termination'], 'a fresh signature proves the payer');
        self::assertCount($settles, $this->settled, 'and nothing is settled');

        // After Job::RESULT_TTL the result is gone, and so is the re-read.
        $this->now = time() + Job::RESULT_TTL;
        self::assertSame('already_delivered', $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]))['structuredContent']['error']);
        self::assertSame([$jobId], (new JobJanitor($this->jobs))->forget($this->now));
        self::assertNull($this->jobs->get($jobId)?->result);
    }

    public function testASignatureTheOnlineVerifyRefusesNeverHoldsASlot(): void
    {
        $free = [];
        $this->verify = function () use (&$free): ?string {
            // JOB_SLOTS=1: while the facilitator verifies, the slot must still be free for a good payment.
            $slot = $this->slots->take();
            $free[] = null !== $slot;
            $slot?->release();

            return 'insufficient_funds';
        };
        [$refused] = $this->start();

        self::assertSame('insufficient_funds', $refused['structuredContent']['error']);
        self::assertSame([true], $free);
        self::assertSame([], $this->settled);
    }

    public function testAResultOnAJobNobodyRunsDoesNotWaitForever(): void
    {
        $job = $this->jobs->create(Job::settling(self::BUYER_ADDRESS, 'k', JobFlowTest::START, ['text' => self::TEXT], time())->to(Job::QUEUED, time()));
        // Even with no cron: once stale, the buyer's own call gives up on it (ours: the result is free).
        $this->now = time() + JobJanitor::STALE_AFTER;
        self::assertSame(['failed_ours', 'our_timeout'], [$this->jobStatus($job->id)['state'], $this->jobStatus($job->id)['reason']]);
        $result = $this->request(JobFlowTest::RESULT, ['jobId' => $job->id], $this->sign(JobFlowTest::RESULT, ['jobId' => $job->id]));
        self::assertSame('job_failed_ours', $result['structuredContent']['error']);
        self::assertSame([], $this->settled);
    }

    public function testARetryThatFoundNoSlotLeavesItsJobToTheCron(): void
    {
        $deposit = $this->sign(JobFlowTest::START, ['text' => self::TEXT]);
        // The tool fails once after the settle: the retry runs it (no second settle) while another job holds the slot.
        $this->beforeTool = function (): void {
            $this->beforeTool = null;
            throw new \RuntimeException('tool broke');
        };
        try {
            $this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit);
        } catch (\RuntimeException) {
        }
        $held = $this->slots->take();
        $jobId = self::answer($this->request(JobFlowTest::START, ['text' => self::TEXT], $deposit))['jobId'];
        self::assertSame([Job::QUEUED, 0], [$this->jobs->get($jobId)?->state, $this->workRuns], 'no slot: not run');

        $held?->release();
        self::assertSame([$jobId], (new JobJanitor($this->jobs))->orphans(time() + JobJanitor::ORPHAN_AFTER), 'the cron finds it before it is stale');
    }

    public function testARefusedResultSettleMakesTheJobReadyAgain(): void
    {
        $jobId = self::answer($this->start()[0])['jobId'];
        $this->receipts = [['success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '']];
        $refused = $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]));

        self::assertSame('insufficient_funds', $refused['structuredContent']['error'], 'the answer is withheld');
        self::assertSame(Job::READY, $this->jobs->get($jobId)?->state);
        $paid = $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]));
        self::assertTrue($paid['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
    }

    public function testAFailureOfOursMakesTheResultFree(): void
    {
        $this->work = static fn () => throw new JobFailed('provider_error');
        $jobId = self::answer($this->start()[0])['jobId'];

        self::assertSame(['failed_ours', 'provider_error', false], [$this->jobStatus($jobId)['state'], $this->jobStatus($jobId)['reason'], $this->jobStatus($jobId)['retryable']]);
        self::assertNull($this->jobs->get($jobId)?->params);
        $result = $this->request(JobFlowTest::RESULT, ['jobId' => $jobId], $this->sign(JobFlowTest::RESULT, ['jobId' => $jobId]));
        self::assertSame('job_failed_ours', $result['structuredContent']['error']);
        self::assertCount(1, $this->settled, 'only the deposit was ever charged');
    }

    public function testAResultIsNotSoldBeforeTheJobIsReady(): void
    {
        // The work outlives the request: here it is simply never run (no slot when afterResponse() came).
        $job = $this->jobs->create(Job::settling(self::BUYER_ADDRESS, 'k', JobFlowTest::START, ['text' => self::TEXT], time())->to(Job::QUEUED, time()));
        $result = $this->request(JobFlowTest::RESULT, ['jobId' => $job->id], $this->sign(JobFlowTest::RESULT, ['jobId' => $job->id]));

        self::assertSame('job_not_ready', $result['structuredContent']['error']);
        self::assertSame([], $this->settled);
    }

    public function testOfTwoResultPaymentsRacingForOneJobOnlyOneDelivers(): void
    {
        $jobId = self::answer($this->start()[0])['jobId'];
        // Two processes, both past the payer check while the job was ready.
        $a = $this->desk();
        $b = $this->desk();
        $params = $this->resultParams($jobId);
        self::assertNull($a->payer($params, self::BUYER_ADDRESS));
        self::assertNull($b->payer($params, self::BUYER_ADDRESS));

        self::assertSame(Job::DELIVERING, $a->delivery->deliver($jobId)->state);
        $this->expectException(IllegalTransition::class);
        $b->delivery->deliver($jobId);
    }

    public function testAResultHandedOverButNeverSettledIsReadyAgain(): void
    {
        $jobId = self::answer($this->start()[0])['jobId'];
        // The tool delivered, then the middleware withheld the answer without settling (its ledger refused the write).
        self::assertNull($this->desk->payer($this->resultParams($jobId), self::BUYER_ADDRESS));
        $this->desk->delivery->deliver($jobId);
        $this->desk->afterResponse();

        self::assertSame(Job::READY, $this->jobs->get($jobId)?->state);
    }

    public function testASensitiveDepositKeepsTheResultOutOfTheLedgerButNotTheJobId(): void
    {
        [$started, $deposit] = $this->start(['text' => self::TEXT, 'sensitive' => true]);
        $jobId = self::answer($started)['jobId'];
        self::assertTrue($this->jobs->get($jobId)?->sensitive);

        self::assertFalse($this->desk->sensitive(['name' => JobFlowTest::START, 'arguments' => ['text' => self::TEXT, 'sensitive' => true]]), 'a job id carries no buyer text');
        self::assertSame($jobId, self::answer($this->request(JobFlowTest::START, ['text' => self::TEXT, 'sensitive' => true], $deposit))['jobId'], 'so its retry is replayed');
        self::assertTrue($this->desk->sensitive(['name' => JobFlowTest::RESULT, 'arguments' => ['jobId' => $jobId]]));
        self::assertTrue($this->desk->sensitive(['name' => JobFlowTest::RESULT, 'arguments' => ['jobId' => str_repeat('0', 32)]]), 'unknown: the safe side');

        $plain = self::answer($this->start()[0])['jobId'];
        self::assertFalse($this->desk->sensitive(['name' => JobFlowTest::RESULT, 'arguments' => ['jobId' => $plain]]));
        self::assertFalse($this->desk->sensitive(['name' => 'wisdom', 'arguments' => []]));
        self::assertTrue($this->desk->sensitive(['name' => 'wisdom', 'arguments' => ['sensitive' => true]]), 'other tools: the rules as before');
    }
}
