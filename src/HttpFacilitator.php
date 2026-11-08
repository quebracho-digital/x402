<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Any x402 facilitator by URL (/verify, /settle, /supported), e.g.
 * https://x402.org/facilitator, with an optional bearer key. A facilitator
 * with its own routes or auth (Circle) wraps this class: CircleFacilitator.
 *
 * A settle counts only when it is consistent: a 2xx, success === true, a
 * transaction (0x+64 hex on EVM), our network and, when named, the payer who
 * signed (a facilitator that names no payer is not checked on it).
 *
 * @see https://github.com/coinbase/x402
 */
final class HttpFacilitator implements Facilitator
{
    /**
     * @deprecated since 0.2: use CircleFacilitator::URL with CircleFacilitator. Kept so 0.1.x callers still compile.
     */
    public const CIRCLE = CircleFacilitator::URL;
    // Casper (casper:casper, casper:casper-test); /verify and /settle take a CSPR.cloud key as $bearer.
    public const CSPR_CLOUD = 'https://x402-facilitator.cspr.cloud';

    private readonly string $url;

    /**
     *                        @deprecated removed in 1.0: keyless Circle is CircleFacilitator, which sends the seller proof.
     *                        Setting it triggers E_USER_DEPRECATED.
     *                        @deprecated removed in 1.0, with $seller: CircleFacilitator polls /status.
     *                        @deprecated removed in 1.0, with $seller: CircleFacilitator polls /status.
     *
     * @param ?\Closure $headers
     *                           fn (string $purpose, string $method, string $body, string $network): array<string, string>,
     *                           the extra headers of each call (a wrapper's). It may throw
     *                           \InvalidArgumentException before anything is sent.
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        string $url,
        #[\SensitiveParameter] private readonly ?string $bearer = null,
        #[\SensitiveParameter] private readonly ?Signer $seller = null,
        private readonly int $pollDelaySeconds = 1,
        private readonly int $pollAttempts = 5,
        private readonly ?\Closure $headers = null,
    ) {
        if (null !== $seller) {
            trigger_error('HttpFacilitator with a $seller is deprecated and will be removed in the next major version: use CircleFacilitator instead.', \E_USER_DEPRECATED);
        }
        if ('https' !== parse_url($url, \PHP_URL_SCHEME) || !parse_url($url, \PHP_URL_HOST)) {
            throw new \InvalidArgumentException("Not an https facilitator URL: $url");
        }
        $this->url = rtrim($url, '/');
    }

    public function verify(array $payment, array $req): array
    {
        $out = $this->call('POST', '/verify', 'verify', $req['network'], $this->body($payment, $req));
        if (!is_bool($out['isValid'] ?? null)) {
            throw new FacilitatorException('verify: not an x402 answer');
        }

        return $out;
    }

    public function settle(array $payment, array $req): array
    {
        try {
            return $this->settleWithPoll($payment, $req);
        } catch (\InvalidArgumentException) {
            // A header hook refused before anything was sent (a seller proof that cannot be built): a refusal, so the claim is freed.
            return ['success' => false, 'errorReason' => 'seller_proof_failed', 'transaction' => '', 'network' => $req['network']];
        }
    }

    /**
     * As v0.2.1 settled, for every caller: a pending settle with a paymentId is polled at /status, with or without
     * $seller or $bearer. The results are v0.2.1's (DECISIONS.md D15); the timing is not: the first query goes out
     * at once and the delay sits only between queries (DECISIONS.md D16, "corrige el orden del polling").
     * ponytail: a copy of CircleFacilitator::settle's poll, kept here because HttpFacilitator cannot build CircleFacilitator
     * (it composes this class). Delete with the 0.2.x shim in 1.0.
     */
    private function settleWithPoll(array $payment, array $req): array
    {
        $out = $this->settleRaw($payment, $req);
        $id = $out['extensions']['settlement-status']['paymentId'] ?? null;
        for ($i = 0; true !== ($out['success'] ?? null) && is_string($id) && preg_match('/^[\w-]+$/', $id) && $i < $this->pollAttempts; ++$i) {
            if ($i > 0) {
                sleep($this->pollDelaySeconds);
            }
            try {
                $status = $this->get("/status/$id", 'status', $req['network']);
            } catch (FacilitatorException) {
                // Settled or not, Circle knows it by this id.
                break;
            }
            if ('completed' === ($status['status'] ?? null)) {
                $out = ['success' => true, 'transaction' => $status['transaction'] ?? null, 'network' => $status['network'] ?? $req['network'], 'payer' => $status['payer'] ?? $out['payer'] ?? null];
            } elseif ('failed' === ($status['status'] ?? null)) {
                return ['success' => false, 'errorReason' => is_string($status['reason'] ?? null) ? $status['reason'] : 'settlement_failed', 'transaction' => '', 'network' => $req['network']];
            }
        }
        if (true !== ($out['success'] ?? null) && is_string($id)) {
            return ['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => is_string($out['transaction'] ?? null) ? $out['transaction'] : '', 'network' => $req['network'], 'paymentId' => $id];
        }

        return $this->checkSettle($out, $payment, $req);
    }

    /**
     * The settle answer as it came, a refusal included: nothing is checked.
     *
     * @throws \InvalidArgumentException when a header hook refuses, before anything is sent
     */
    public function settleRaw(array $payment, array $req): array
    {
        return $this->call('POST', '/settle', 'settle', $req['network'], $this->body($payment, $req));
    }

    /**
     * The consistency rule for a settle's answer. A refusal (success not true) comes back as it is.
     *
     * Public for a wrapper that got its settle answer another way (Circle's /status poll).
     */
    public function checkSettle(array $out, array $payment, array $req): array
    {
        if (true !== ($out['success'] ?? null)) {
            return $out;
        }
        $tx = $out['transaction'] ?? null;
        $from = $payment['payload']['authorization']['from'] ?? null;
        $consistent = is_string($tx) && (str_starts_with($req['network'], 'eip155:') ? 1 === preg_match('/^0x[0-9a-fA-F]{64}$/', $tx) : '' !== $tx)
            && ($out['network'] ?? null) === $req['network']
            && (!isset($out['payer'], $from) || 0 === strcasecmp((string) $out['payer'], (string) $from));

        return $consistent ? $out : ['success' => false, 'errorReason' => 'invalid_settle_response', 'transaction' => is_string($tx) ? $tx : '', 'network' => $req['network']];
    }

    /**
     * The "kinds" of /supported: scheme, network, extra per entry.
     */
    public function supported(): array
    {
        $kinds = $this->call('GET', '/supported', 'supported', '', '')['kinds'] ?? null;

        return is_array($kinds) ? $kinds : throw new FacilitatorException('supported: not an x402 answer');
    }

    /**
     * Solana: the facilitator's fee payer for this network (extra.feePayer).
     */
    public function feePayer(string $network): ?string
    {
        foreach ($this->supported() as $kind) {
            if (($kind['network'] ?? null) === $network && is_string($kind['extra']['feePayer'] ?? null)) {
                return $kind['extra']['feePayer'];
            }
        }

        return null;
    }

    /**
     * A GET under the facilitator's URL, for a route beyond the standard (Circle's /status). Same headers and errors as the rest.
     */
    public function get(string $path, string $purpose, string $network): array
    {
        return $this->call('GET', $path, $purpose, $network, '');
    }

    private function body(array $payment, array $req): string
    {
        return json_encode(['x402Version' => 2, 'paymentPayload' => $payment, 'paymentRequirements' => $req], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    /**
     * The facilitator's JSON. A non-2xx is an answer only when it says
     * isValid/success false (a refusal); anything else throws.
     */
    private function call(string $method, string $path, string $purpose, string $network, string $body): array
    {
        $request = $this->requests->createRequest($method, $this->url.$path);
        if (null !== $this->bearer) {
            $request = $request->withHeader('Authorization', "Bearer $this->bearer");
        } elseif (null !== $this->seller && 'supported' !== $purpose) {
            // ponytail: deprecated $seller path; delete with it in 1.0.
            $request = $request->withHeader('Facilitator-Seller-Proof', SellerProof::header($this->seller, $purpose, $method, $body, $network));
        }
        if (null !== $this->headers) {
            foreach (($this->headers)($purpose, $method, $body, $network) as $name => $value) {
                $request = $request->withHeader($name, $value);
            }
        }
        if ('' !== $body) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($this->streams->createStream($body));
        }
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new FacilitatorException("$purpose: ".$e->getMessage(), 0, $e);
        }
        $status = $response->getStatusCode();
        $out = json_decode((string) $response->getBody(), true);
        if ($status >= 200 && $status < 300 && is_array($out)) {
            return $out;
        }
        if (is_array($out) && (false === ($out['isValid'] ?? null) || false === ($out['success'] ?? null))) {
            return $out;
        }
        $reason = is_array($out) ? ($out['errors'][0]['reason'] ?? $out['message'] ?? null) : null;

        throw new FacilitatorException(is_string($reason) ? $reason : "$purpose: http_$status", $status);
    }
}
