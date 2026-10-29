<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Any x402 facilitator by URL (/verify, /settle, /supported), e.g.
 * https://x402.org/facilitator, and Circle's (self::CIRCLE) with an API key
 * ($bearer) or keyless with the payTo key ($seller signs a seller proof).
 *
 * A settle counts only when it is consistent: a 2xx, success === true, a
 * transaction (0x+64 hex on EVM), our network and, when named, the payer who
 * signed (a facilitator that names no payer is not checked on it). A pending
 * settle with a paymentId (Circle) is polled at /status; still pending, it
 * comes back as settlement_pending with that paymentId.
 *
 * @see https://developers.circle.com/facilitator-service/quickstart
 */
final class HttpFacilitator implements Facilitator
{
    public const CIRCLE = 'https://api.circle.com/v1/facilitator/x402';

    private readonly string $url;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        string $url,
        #[\SensitiveParameter] private readonly ?string $bearer = null,
        private readonly ?Signer $seller = null,
        private readonly int $pollDelaySeconds = 1,
        private readonly int $pollAttempts = 5,
    ) {
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
        $out = $this->call('POST', '/settle', 'settle', $req['network'], $this->body($payment, $req));
        $id = $out['extensions']['settlement-status']['paymentId'] ?? null;
        for ($i = 0; true !== ($out['success'] ?? null) && is_string($id) && preg_match('/^[\w-]+$/', $id) && $i < $this->pollAttempts; ++$i) {
            sleep($this->pollDelaySeconds);
            try {
                $status = $this->call('GET', "/status/$id", 'status', $req['network'], '');
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
            // Still pending after polling: its id is what reconciles it.
            return ['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => is_string($out['transaction'] ?? null) ? $out['transaction'] : '', 'network' => $req['network'], 'paymentId' => $id];
        }
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
            $request = $request->withHeader('Facilitator-Seller-Proof', SellerProof::header($this->seller, $purpose, $method, $body, $network));
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
