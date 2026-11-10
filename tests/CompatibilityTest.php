<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\Budget;
use Quebracho\X402\ChainFacilitator;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileClaims;
use Quebracho\X402\HttpFacilitator;
use Quebracho\X402\Networks;
use Quebracho\X402\PayingHttpClient;
use Quebracho\X402\PayingMcpClient;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\Rpc;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\X402McpMiddleware;
use Quebracho\X402\X402Middleware;

/**
 * Public API compatibility with the published releases of quebracho/x402:
 * v0.3.0 (commit 1a5246000a7ca7b01b5c289a3c3f85c3cf0977ab) and v0.4.1
 * (commit 07ee42a593834746ac70be4c6782ed4cb791cd56).
 *
 * compat/<tag>-api.json is the reflection inventory of each tag, written by
 * `bin/api-inventory.php` against a checkout of it. Rules:
 * - a class, constant, method, property or constructor of a release must still exist;
 * - a parameter keeps its position and name; its type may widen, not narrow;
 * - a new required parameter is a break; a new optional one at the end is an addition;
 * - a return type may narrow (covariance), not widen.
 *
 * The README examples of 0.3.0 run here against fakes (no network).
 */
final class CompatibilityTest extends TestCase
{
    use TempClaims {
        tearDown as private removeClaims;
    }

    /** @var array<string, string> release tag => its inventory */
    private const FIXTURES = [
        '0.3.0' => __DIR__.'/compat/v0.3.0-api.json',
        '0.4.1' => __DIR__.'/compat/v0.4.1-api.json',
    ];

    private const BUYER_KEY = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';
    private const GAS_KEY = '0x59c6995e998f97a5a0044966f0945389dc9e86dae88c7a8412f4603b6b78690d';
    private const PAY_TO = '0x000000000000000000000000000000000000dEaD';

    private const BUILTIN = ['int', 'float', 'string', 'bool', 'array', 'null', 'mixed', 'void', 'never', 'object', 'iterable', 'callable', 'false', 'true', 'self', 'static', 'parent'];

    protected function tearDown(): void
    {
        Networks::reset();
        $this->removeClaims();
        parent::tearDown();
    }

    /** @return array{classes: array<string, array<string, mixed>>} */
    private static function fixture(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{0: string, 1: array<string, mixed>}> */
    public static function apiClasses(): iterable
    {
        foreach (self::FIXTURES as $tag => $path) {
            foreach (self::fixture($path)['classes'] as $fqn => $api) {
                yield "$tag $fqn" => [$fqn, $api];
            }
        }
    }

    // ---------------------------------------------------------------- helpers

    /** Members of a type string: "?A|B" => ['null', 'A', 'B']. */
    private static function members(string $type): array
    {
        $out = [];
        foreach (explode('|', $type) as $m) {
            if (str_starts_with($m, '?')) {
                $out[] = 'null';
                $m = substr($m, 1);
            }
            $m = ltrim($m, '\\');
            $lower = strtolower($m);
            $out[] = in_array($lower, self::BUILTIN, true) ? $lower : $m;
        }

        return array_values(array_unique($out));
    }

    /** Does a value of every member of $narrow fit $wide? A null type means mixed. */
    private static function accepts(?string $wide, ?string $narrow): bool
    {
        if (null === $wide || 'mixed' === $wide || $wide === $narrow) {
            return true;
        }
        if (null === $narrow) {
            return false;
        }
        $w = self::members($wide);
        foreach (self::members($narrow) as $n) {
            if (!self::memberAccepted($w, $n)) {
                return false;
            }
        }

        return true;
    }

    private static function memberAccepted(array $wide, string $n): bool
    {
        if (in_array($n, $wide, true) || in_array('mixed', $wide, true)) {
            return true;
        }
        $alias = match ($n) {
            'true', 'false' => ['bool'],
            'array' => ['iterable'],
            'Closure' => ['callable'],
            default => [],
        };
        if (array_intersect($alias, $wide)) {
            return true;
        }
        if (in_array('object', $wide, true) && (class_exists($n) || interface_exists($n))) {
            return true;
        }
        if (!in_array($n, self::BUILTIN, true)) {
            foreach ($wide as $w) {
                if (class_exists($w) || interface_exists($w)) {
                    if (is_a($n, $w, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** @return list<array{name: string, position: int, type: ?string, optional: bool, variadic: bool, byRef: bool}> */
    private static function reflectParams(?\ReflectionFunctionAbstract $f): array
    {
        if (null === $f) {
            return [];
        }
        $out = [];
        foreach ($f->getParameters() as $p) {
            $out[] = [
                'name' => $p->getName(),
                'position' => $p->getPosition(),
                'type' => self::typeString($p->getType()),
                'optional' => $p->isOptional(),
                'variadic' => $p->isVariadic(),
                'byRef' => $p->isPassedByReference(),
            ];
        }

        return $out;
    }

    private static function typeString(?\ReflectionType $t): ?string
    {
        return null === $t ? null : (string) $t;
    }

    /**
     * @param list<array<string, mixed>> $old parameters of 0.3.0
     * @param list<array<string, mixed>> $new parameters today
     *
     * @return list<string>
     */
    private static function signatureProblems(array $old, array $new): array
    {
        $problems = [];
        foreach ($old as $i => $p) {
            $q = $new[$i] ?? null;
            if (null === $q) {
                $problems[] = "falta \${$p['name']} (posición $i)";
                continue;
            }
            if ($q['name'] !== $p['name']) {
                $problems[] = "posición $i: \${$p['name']} pasó a \${$q['name']}";
            }
            if ($q['variadic'] !== $p['variadic'] || $q['byRef'] !== $p['byRef']) {
                $problems[] = "\${$p['name']}: variádico o por referencia cambió";
            }
            if ($p['optional'] && !$q['optional']) {
                $problems[] = "\${$p['name']} ahora es requerido";
            }
            if (!self::accepts($q['type'], $p['type'])) {
                $problems[] = "\${$p['name']}: tipo estrechado {$p['type']} → {$q['type']}";
            }
        }
        foreach (array_slice($new, count($old)) as $q) {
            if (!$q['optional']) {
                $problems[] = "parámetro requerido nuevo \${$q['name']}";
            }
        }

        return $problems;
    }

    private static function assertNoProblems(array $problems, string $where): void
    {
        self::assertSame([], $problems, $where.': '.implode('; ', $problems));
    }

    private static function route(\Closure $route): ClientInterface
    {
        return new class($route) implements ClientInterface {
            public function __construct(private \Closure $route)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->route)($request);
            }
        };
    }

    /** The PSR-18 request as the server sees it. */
    private static function asServerRequest(RequestInterface $r): ServerRequestInterface
    {
        return new ServerRequest($r->getMethod(), $r->getUri(), $r->getHeaders(), (string) $r->getBody(), $r->getProtocolVersion());
    }

    private static function paidHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'paid content');
            }
        };
    }

    private static function mcpHandler(): RequestHandlerInterface
    {
        $json = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'paid content']]]]);

        return new class($json) implements RequestHandlerInterface {
            public function __construct(private string $json)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], $this->json);
            }
        };
    }

    /** Seller from the README, Seller section. */
    private function readmeSeller(): X402Middleware
    {
        $psr17 = new Psr17Factory();
        $price = fn ($request) => [Networks::requirements('eip155:84532', '0.01', self::PAY_TO)];
        $claim = (new FileClaims($this->claimsDir()))(...);

        return new X402Middleware([new EvmExact()], $price, $claim, $psr17, $psr17, Settlement::mock());
    }

    // ------------------------------------------------------------ inventory

    #[DataProvider('apiClasses')]
    public function testClassStillExistsWithItsKindAndInterfaces(string $fqn, array $api): void
    {
        $exists = match ($api['kind']) {
            'interface' => interface_exists($fqn),
            'enum' => enum_exists($fqn),
            default => class_exists($fqn),
        };
        self::assertTrue($exists, "$fqn no existe hoy como {$api['kind']}");
        foreach ($api['interfaces'] as $iface) {
            self::assertTrue(is_a($fqn, $iface, true), "$fqn ya no implementa $iface");
        }
    }

    #[DataProvider('apiClasses')]
    public function testPublicConstantsStillExistWithTheSameValue(string $fqn, array $api): void
    {
        $rc = new \ReflectionClass($fqn);
        $problems = [];
        foreach ($api['constants'] as $name => $c) {
            if (!$rc->hasConstant($name)) {
                $problems[] = "constante $name eliminada";
            } elseif (Networks::class === $fqn && 'ALL' === $name && \is_array($rc->getConstant($name))) {
                // Networks::ALL may gain entries (a new chain is an addition); every entry of the release must stay as it was. Other tables are strict.
                foreach ($c['value'] as $key => $value) {
                    if (($rc->getConstant($name)[$key] ?? null) !== $value) {
                        $problems[] = "constante $name: la entrada $key cambió o desapareció";
                    }
                }
            } elseif ($rc->getConstant($name) !== $c['value']) {
                $problems[] = "constante $name cambió de valor";
            }
        }
        self::assertNoProblems($problems, $fqn);
    }

    #[DataProvider('apiClasses')]
    public function testPublicMethodsStillExistWithTheSameStaticness(string $fqn, array $api): void
    {
        $rc = new \ReflectionClass($fqn);
        $problems = [];
        foreach ($api['methods'] as $name => $m) {
            if (!$rc->hasMethod($name) || !$rc->getMethod($name)->isPublic()) {
                $problems[] = "método $name eliminado o no público";
            } elseif ($rc->getMethod($name)->isStatic() !== $m['static']) {
                $problems[] = "método $name: static cambió";
            }
        }
        self::assertNoProblems($problems, $fqn);
    }

    #[DataProvider('apiClasses')]
    public function testConstructorAndMethodParametersAndReturnsAreCompatible(string $fqn, array $api): void
    {
        if (!class_exists($fqn) && !interface_exists($fqn)) {
            self::fail("$fqn no existe");
        }
        $rc = new \ReflectionClass($fqn);
        $problems = [];

        $oldCtor = $api['constructor'];
        $newCtor = $rc->getConstructor();
        if (null !== $oldCtor) {
            if (null === $newCtor) {
                $problems[] = '__construct eliminado';
            } else {
                foreach (self::signatureProblems($oldCtor['params'], self::reflectParams($newCtor)) as $p) {
                    $problems[] = "__construct: $p";
                }
            }
        } elseif (null !== $newCtor) {
            foreach (self::signatureProblems([], self::reflectParams($newCtor)) as $p) {
                $problems[] = "__construct (antes sin constructor): $p";
            }
        }

        foreach ($api['methods'] as $name => $m) {
            if (!$rc->hasMethod($name)) {
                continue; // reported by testPublicMethodsStillExistWithTheSameStaticness
            }
            $method = $rc->getMethod($name);
            foreach (self::signatureProblems($m['params'], self::reflectParams($method)) as $p) {
                $problems[] = "$name: $p";
            }
            // Covariance: today's return must fit what 0.3.0 promised.
            $newReturn = self::typeString($method->getReturnType());
            if (!self::accepts($m['returnType'], $newReturn)) {
                $problems[] = "$name: retorno {$m['returnType']} → $newReturn";
            }
        }
        self::assertNoProblems($problems, $fqn);
    }

    #[DataProvider('apiClasses')]
    public function testPublicPropertiesStillExist(string $fqn, array $api): void
    {
        $rc = new \ReflectionClass($fqn);
        $problems = [];
        foreach ($api['properties'] as $name => $p) {
            if (!$rc->hasProperty($name) || !$rc->getProperty($name)->isPublic()) {
                $problems[] = "propiedad \$$name eliminada o no pública";
                continue;
            }
            $now = $rc->getProperty($name);
            if (!$p['readonly'] && $now->isReadOnly()) {
                $problems[] = "propiedad \$$name ahora es readonly";
            }
            if (!self::accepts(self::typeString($now->getType()), $p['type'])) {
                $problems[] = "propiedad \$$name: tipo estrechado {$p['type']} → ".self::typeString($now->getType());
            }
        }
        self::assertNoProblems($problems, $fqn);
    }

    /**
     * 0.2.1 HttpFacilitator constructor, from `git show v0.2.1:src/HttpFacilitator.php`.
     * Today it has one extra optional parameter at the end (addition, not a break).
     */
    public function testHttpFacilitatorKeepsTheV021Constructor(): void
    {
        $v021 = [
            ['name' => 'http', 'type' => 'Psr\\Http\\Client\\ClientInterface', 'optional' => false],
            ['name' => 'requests', 'type' => 'Psr\\Http\\Message\\RequestFactoryInterface', 'optional' => false],
            ['name' => 'streams', 'type' => 'Psr\\Http\\Message\\StreamFactoryInterface', 'optional' => false],
            ['name' => 'url', 'type' => 'string', 'optional' => false],
            ['name' => 'bearer', 'type' => '?string', 'optional' => true],
            ['name' => 'seller', 'type' => '?Quebracho\\X402\\Signer', 'optional' => true],
            ['name' => 'pollDelaySeconds', 'type' => 'int', 'optional' => true],
            ['name' => 'pollAttempts', 'type' => 'int', 'optional' => true],
        ];
        $old = [];
        foreach ($v021 as $i => $p) {
            $old[] = $p + ['position' => $i, 'variadic' => false, 'byRef' => false];
        }
        $new = self::reflectParams((new \ReflectionClass(HttpFacilitator::class))->getConstructor());

        self::assertNoProblems(self::signatureProblems($old, $new), 'HttpFacilitator 0.2.1');
        self::assertSame('https://api.circle.com/v1/facilitator/x402', HttpFacilitator::CIRCLE);
    }

    // ----------------------------------------------- README examples of 0.3.0

    public function testReadmeSellerExampleAnswers402ThenPays(): void
    {
        $middleware = $this->readmeSeller();
        $handler = self::paidHandler();

        $unpaid = $middleware->process(new ServerRequest('GET', 'https://example.test/article'), $handler);
        self::assertSame(402, $unpaid->getStatusCode());
        self::assertTrue($unpaid->hasHeader(PaymentHeaders::REQUIRED));

        $required = PaymentHeaders::decode($unpaid->getHeaderLine(PaymentHeaders::REQUIRED));
        $payment = (new EvmExact(new Signer(self::BUYER_KEY)))->pay($required, $required['accepts'][0]);
        $paid = $middleware->process(new ServerRequest('GET', 'https://example.test/article', [PaymentHeaders::SIGNATURE => PaymentHeaders::encode($payment)]), $handler);

        self::assertSame(200, $paid->getStatusCode());
        self::assertSame('paid content', (string) $paid->getBody());
        self::assertTrue($paid->hasHeader(PaymentHeaders::RESPONSE));
    }

    public function testReadmeFacilitatorExampleSettlesThroughHttpFacilitator(): void
    {
        $psr17 = new Psr17Factory();
        $buyer = (new Signer(self::BUYER_KEY))->address();
        $txHash = '0x'.str_repeat('ab', 32);
        $http = self::route(function (RequestInterface $r) use ($buyer, $txHash): ResponseInterface {
            $json = match (basename($r->getUri()->getPath())) {
                'verify' => ['isValid' => true, 'payer' => $buyer],
                'settle' => ['success' => true, 'transaction' => $txHash, 'network' => 'eip155:84532', 'payer' => $buyer],
                default => null,
            };

            return null === $json ? new Response(404) : new Response(200, ['Content-Type' => 'application/json'], json_encode($json));
        });

        $facilitator = new HttpFacilitator($http, $psr17, $psr17, 'https://x402.org/facilitator');
        $settle = fn ($payment, $req) => $facilitator->settle($payment, $req);

        $unpaid = $this->readmeSeller()->process(new ServerRequest('GET', 'https://example.test/article'), self::paidHandler());
        $required = PaymentHeaders::decode($unpaid->getHeaderLine(PaymentHeaders::REQUIRED));
        $req = $required['accepts'][0];
        $payment = (new EvmExact(new Signer(self::BUYER_KEY)))->pay($required, $req);

        $receipt = $settle($payment, $req);
        self::assertSame($txHash, $receipt['transaction']);
    }

    public function testReadmeFacilitatorConstructorsAcceptTheDocumentedArguments(): void
    {
        $psr17 = new Psr17Factory();
        $http = self::route(fn (RequestInterface $r): ResponseInterface => new Response(404));

        $circle = new HttpFacilitator($http, $psr17, $psr17, HttpFacilitator::CIRCLE, 'test-api-key');
        self::assertInstanceOf(HttpFacilitator::class, $circle);

        $rpc = new Rpc($http, $psr17, $psr17, 'https://rpc.example');
        $chain = new ChainFacilitator($rpc, new Signer(self::GAS_KEY), 'eip155:5042002', fn (string $name, callable $run): mixed => $run());
        self::assertInstanceOf(ChainFacilitator::class, $chain);
    }

    public function testReadmeBuyerExamplePaysThroughTheSeller(): void
    {
        $middleware = $this->readmeSeller();
        $psr17 = new Psr17Factory();
        $psr18 = self::route(fn (RequestInterface $r): ResponseInterface => $middleware->process(self::asServerRequest($r), self::paidHandler()));

        $client = new PayingHttpClient(
            $psr18,
            [new EvmExact(new Signer(self::BUYER_KEY))],
            Budget::usd('eip155:84532', '0.05'),
        );

        $response = $client->sendRequest($psr17->createRequest('GET', 'https://example.test/article'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid content', (string) $response->getBody());
    }

    public function testReadmeMcpExampleChargesTheWisdomToolThroughTheClient(): void
    {
        $psr17 = new Psr17Factory();
        $price = fn (array $params) => 'wisdom' === $params['name'] ? [Networks::requirements('eip155:84532', '0.01', self::PAY_TO)] : null;
        $claim = (new FileClaims($this->claimsDir()))(...);
        $middleware = new X402McpMiddleware([new EvmExact()], $price, $claim, $psr17, $psr17, Settlement::mock());

        $psr18 = self::route(fn (RequestInterface $r): ResponseInterface => $middleware->process(self::asServerRequest($r), self::mcpHandler()));
        $client = new PayingMcpClient(
            $psr18,
            [new EvmExact(new Signer(self::BUYER_KEY))],
            Budget::usd('eip155:84532', '0.05'),
            $psr17,
        );

        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'wisdom', 'arguments' => []]]);
        $response = $client->sendRequest($psr17->createRequest('POST', 'https://example.test/mcp')->withHeader('Content-Type', 'application/json')->withBody($psr17->createStream($body)));

        $result = json_decode((string) $response->getBody(), true)['result'];
        self::assertSame('paid content', $result['content'][0]['text']);
        self::assertArrayNotHasKey('isError', $result);
    }

    public function testReadmeNetworksAddExampleRegistersABlockchain(): void
    {
        Networks::add(
            chainId: 8453,
            asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            name: 'USD Coin',
            version: '2',
            label: 'Base (USDC)',
            rpc: 'https://mainnet.base.org',
            explorer: 'https://basescan.org/tx/',
        );

        self::assertTrue(Networks::has('eip155:8453'));
        $req = Networks::requirements('eip155:8453', '0.01', self::PAY_TO);
        self::assertSame('eip155:8453', $req['network']);
        self::assertSame('0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', $req['asset']);
    }
}
