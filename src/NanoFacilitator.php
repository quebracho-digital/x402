<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Verifies and settles `nano:mainnet` payments (NanoExact) against a Nano node the seller chooses: one
 * `block_info` call, no key, nothing broadcast. A Nano send has already moved when the buyer pays, so settle()
 * is the same reading again and returns the block hash as the transaction.
 *
 * A payment is good when its block is confirmed, a state send of exactly `amount` raw to `payTo`, from the account
 * the payment names, with the representative NanoExact::representative() derives from the payment's order id,
 * and when the node received it no more than NanoExact::MAX_TIMEOUT seconds ago. Each block is claimed once
 * (NanoExact::spent, through the middlewares' claim): give them a claim that outlives a restart (FileClaims,
 * RedisLedger), and the age limit keeps a block from being sold again once its claim is gone.
 *
 * An unreachable node or one that answers no JSON throws FacilitatorException; a node error such as
 * "Block not found" is a refusal.
 */
final class NanoFacilitator implements Facilitator
{
    public const string REASON_NOT_FOUND = 'invalid_exact_nano_block_not_found';
    public const string REASON_UNCONFIRMED = 'invalid_exact_nano_block_unconfirmed';
    public const string REASON_NOT_A_SEND = 'invalid_exact_nano_block_not_a_send';
    public const string REASON_PAYER = 'invalid_exact_nano_payer_mismatch';
    public const string REASON_RECIPIENT = 'invalid_exact_nano_recipient_mismatch';
    public const string REASON_AMOUNT = 'invalid_exact_nano_amount_mismatch';
    public const string REASON_BINDING = 'invalid_exact_nano_representative_mismatch';
    public const string REASON_TIME = 'invalid_exact_nano_block_time';
    public const string REASON_ANSWER = 'invalid_exact_nano_node_answer';

    /**
     * @param string                       $node  the node's RPC URL (a node of your own, or one you trust)
     * @param ClockInterface|\Closure|null $clock Returns the current Unix time; tests pin it
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly string $node,
        private readonly ClockInterface|\Closure|null $clock = null,
    ) {
        if (!str_starts_with($node, 'https://') && !str_starts_with($node, 'http://')) {
            throw new \InvalidArgumentException('A Nano node is an http(s) URL.');
        }
    }

    public function verify(array $payment, array $req): array
    {
        $reason = $this->check($payment, $req);

        return null === $reason
            ? ['isValid' => true, 'payer' => (new NanoExact())->payer($payment)]
            : ['isValid' => false, 'payer' => null, 'invalidReason' => $reason];
    }

    public function settle(array $payment, array $req): array
    {
        $reason = $this->check($payment, $req);
        $network = (string) ($req['network'] ?? '');

        return null === $reason
            ? ['success' => true, 'transaction' => (string) $payment['payload']['block'], 'network' => $network, 'payer' => (new NanoExact())->payer($payment)]
            : ['success' => false, 'transaction' => '', 'network' => $network, 'payer' => null, 'errorReason' => $reason];
    }

    /**
     * Why the block does not pay $req, or NULL when it does.
     *
     * @throws FacilitatorException
     */
    private function check(array $payment, array $req): ?string
    {
        $scheme = new NanoExact();
        $offline = $scheme->verify($payment, $req);
        if (null !== $offline) {
            return $offline;
        }
        $payload = $payment['payload'];
        $info = $this->blockInfo($payload['block']);
        if (isset($info['error'])) {
            return self::REASON_NOT_FOUND;
        }
        $contents = $info['contents'] ?? null;
        if (!\is_array($contents)) {
            return self::REASON_ANSWER;
        }
        $now = Clock::now($this->clock);
        $received = $info['local_timestamp'] ?? null;

        // Every field read by type, in the one spelling a node writes it.
        return match (true) {
            'true' !== ($info['confirmed'] ?? null) => self::REASON_UNCONFIRMED,
            'state' !== ($contents['type'] ?? null) || 'send' !== ($info['subtype'] ?? null) => self::REASON_NOT_A_SEND,
            !\is_string($info['block_account'] ?? null) || !$scheme->same($info['block_account'], $payload['account']) => self::REASON_PAYER,
            !\is_string($contents['link_as_account'] ?? null) || !$scheme->same($contents['link_as_account'], $req['payTo']) => self::REASON_RECIPIENT,
            // Exact: a send of more is not this offer either (it would be refunded, never credited twice).
            ($info['amount'] ?? null) !== $req['amount'] => self::REASON_AMOUNT,
            !\is_string($contents['representative'] ?? null)
              || !$scheme->same($contents['representative'], NanoExact::representative($payload['orderId'], $req['payTo'], $req['amount'])) => self::REASON_BINDING,
            // A node that does not know when it saw the block (0, bootstrapped) cannot date it: refused.
            !\is_string($received) || 1 !== preg_match('/\A[1-9][0-9]{0,18}\z/', $received) => self::REASON_TIME,
            (int) $received > $now + NanoExact::SKEW || (int) $received < $now - NanoExact::MAX_TIMEOUT => self::REASON_TIME,
            default => null,
        };
    }

    /**
     * @throws FacilitatorException
     */
    private function blockInfo(string $hash): array
    {
        $request = $this->requests->createRequest('POST', $this->node)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream(json_encode(['action' => 'block_info', 'json_block' => 'true', 'hash' => $hash], \JSON_THROW_ON_ERROR)));
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new FacilitatorException('block_info: '.$e->getMessage(), 0, $e);
        }
        $answer = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() >= 300 || !\is_array($answer)) {
            throw new FacilitatorException('block_info: HTTP '.$response->getStatusCode().', not a node answer');
        }

        return $answer;
    }
}
