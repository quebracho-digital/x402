<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * A Concordium node over gRPC-web, decoded into what ConcordiumFacilitator
 * checks. No generated protobuf classes: Proto writes and reads the few
 * messages this needs.
 *
 * @see https://github.com/Concordium/concordium-grpc-api
 */
final class GrpcConcordiumNode implements ConcordiumNode
{
    public function __construct(private readonly GrpcWeb $rpc)
    {
    }

    public function accountInfo(string $address): ?array
    {
        try {
            $answer = $this->rpc->call('concordium.v2.Queries', 'GetAccountInfo', $this->accountInfoRequest($address));
        } catch (GrpcException $e) {
            // NOT_FOUND: a payment from an account that does not exist is invalid, not an outage.
            if (5 === $e->status) {
                return null;
            }
            throw new FacilitatorException($e->getMessage(), $e->status, $e);
        }

        return self::decode('GetAccountInfo', static fn (): array => self::accountInfoFrom(Proto::parse($answer)));
    }

    /**
     * @return array{amount: int, available: int, threshold: int, credentials: array<int, array{threshold: int, keys: array<int, string>}>}
     */
    private static function accountInfoFrom(array $info): array
    {
        $amount = self::uint(Proto::nested($info, 2), 1) ?? 0;
        $threshold = self::uint(Proto::nested($info, 5), 1) ?? 1;
        $credentials = [];
        foreach (Proto::map($info, 4) as $index => $credential) {
            $values = Proto::nested($credential, 1) ?: Proto::nested($credential, 2);
            $keys = Proto::nested($values, 1);
            $publicKeys = [];
            foreach (Proto::map($keys, 1) as $keyIndex => $verifyKey) {
                $raw = Proto::first($verifyKey, 1);
                if (is_string($raw)) {
                    $publicKeys[$keyIndex] = bin2hex($raw);
                }
            }
            $credentials[$index] = [
                'threshold' => self::uint(Proto::nested($keys, 2), 1) ?? 1,
                'keys' => $publicKeys,
            ];
        }

        return [
            'amount' => $amount,
            'available' => self::uint(Proto::nested($info, 12), 1) ?? $amount,
            'threshold' => $threshold,
            'credentials' => $credentials,
        ];
    }

    public function nextNonce(string $address): int
    {
        try {
            $answer = $this->rpc->call('concordium.v2.Queries', 'GetNextAccountSequenceNumber', Proto::bytes(1, Concordium::decodeAddress($address)));
        } catch (GrpcException $e) {
            throw new FacilitatorException($e->getMessage(), $e->status, $e);
        }

        return self::decode('GetNextAccountSequenceNumber', static fn (): ?int => self::uint(Proto::nested(Proto::parse($answer), 1), 1))
            ?? throw new FacilitatorException('The node answered without a sequence number.');
    }

    public function send(array $tx): string
    {
        if (1 !== ($tx['version'] ?? null)) {
            throw new FacilitatorException('A Concordium payment is a V1 sponsored transaction.');
        }
        $parts = Concordium::parts($tx);
        $header = Proto::message(1, Proto::bytes(1, $parts['sender']))
            .Proto::message(2, Proto::uint(1, $parts['nonce']))
            .Proto::message(3, Proto::uint(1, $parts['energy']))
            .Proto::message(5, Proto::uint(1, $parts['expiry']))
            .Proto::message(6, Proto::bytes(1, $parts['sponsor']));
        $payload = Proto::message(5, Proto::message(1, Proto::uint(1, $parts['amount'])).Proto::message(2, Proto::bytes(1, $parts['to'])));
        $signatures = Proto::message(1, self::signatures($tx['signatures']['sender'] ?? []));
        $sponsor = self::signatures($tx['signatures']['sponsor'] ?? []);
        if ('' !== $sponsor) {
            $signatures .= Proto::message(2, $sponsor);
        }
        $transaction = Proto::message(1, $signatures).Proto::message(2, $header).Proto::message(3, $payload);
        $answer = $this->rpc->call('concordium.v2.Queries', 'SendBlockItem', Proto::message(4, $transaction));
        // The node accepted it: an unreadable answer is a FacilitatorException, so the claim stays for reconciliation.
        $hash = self::decode('SendBlockItem', static fn (): int|string|null => Proto::first(Proto::parse($answer), 1));
        if (!is_string($hash) || 32 !== strlen($hash)) {
            throw new FacilitatorException('The node answered a transaction hash that is not 32 bytes.');
        }

        return bin2hex($hash);
    }

    public function status(string $hash): array
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
            throw new FacilitatorException("Not a transaction hash: $hash");
        }
        try {
            $answer = $this->rpc->call('concordium.v2.Queries', 'GetBlockItemStatus', Proto::bytes(1, hex2bin($hash)));
        } catch (GrpcException $e) {
            throw new FacilitatorException($e->getMessage(), $e->status, $e);
        }

        return self::decode('GetBlockItemStatus', static function () use ($answer, $hash): array {
            $finalized = Proto::nested(Proto::parse($answer), 3);
            if ([] === $finalized) {
                return ['finalized' => false, 'success' => false, 'amount' => null, 'receiver' => null, 'transaction' => $hash];
            }
            $summary = Proto::nested(Proto::nested($finalized, 1), 2);
            $effects = Proto::nested(Proto::nested($summary, 4), 3);
            // A transfer that succeeded has an account_transfer effect; a rejected one has only `none`.
            $transfer = Proto::nested($effects, 5);
            $success = [] !== $transfer;
            $receiver = Proto::first(Proto::nested($transfer, 2), 1);
            $onChain = Proto::first(Proto::nested($summary, 3), 1);

            return [
                'finalized' => true,
                'success' => $success,
                'amount' => $success ? self::uint(Proto::nested($transfer, 1), 1) : null,
                'receiver' => is_string($receiver) && 32 === strlen($receiver) ? Concordium::encodeAddress($receiver) : null,
                'transaction' => is_string($onChain) && 32 === strlen($onChain) ? bin2hex($onChain) : $hash,
            ];
        });
    }

    /**
     * Runs $read over a node's answer. The interface promises FacilitatorException: a truncated or non-protobuf
     * body must not escape as an unreadable error, above all after a transaction was sent.
     *
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     *
     * @throws FacilitatorException
     */
    private static function decode(string $method, \Closure $read): mixed
    {
        try {
            return $read();
        } catch (\InvalidArgumentException $e) {
            throw new FacilitatorException("The node answered an unreadable $method: {$e->getMessage()}", 0, $e);
        }
    }

    private function accountInfoRequest(string $address): string
    {
        // BlockHashInput.last_final = Empty, then AccountIdentifierInput.address = AccountAddress{value}.
        $block = Proto::message(2, '');
        $identifier = Proto::message(1, Proto::bytes(1, Concordium::decodeAddress($address)));

        return Proto::message(1, $block).Proto::message(2, $identifier);
    }

    /**
     * A JSON signature map (credential index => key index => hex) as
     * AccountTransactionSignature, whose map entry values are AccountSignatureMap.
     */
    private static function signatures(array $signatures): string
    {
        $out = '';
        foreach ($signatures as $credential => $keys) {
            if (!is_array($keys) || !preg_match('/^(0|[1-9][0-9]{0,9})$/', (string) $credential)) {
                throw new FacilitatorException('The signature map is not credential => key => hex.');
            }
            $map = '';
            foreach ($keys as $key => $signature) {
                if (!is_string($signature) || !preg_match('/^[0-9a-f]{128}$/', $signature) || !preg_match('/^(0|[1-9][0-9]{0,9})$/', (string) $key)) {
                    throw new FacilitatorException('The signature map is not credential => key => hex.');
                }
                $map .= Proto::message(1, Proto::entry((int) $key, Proto::bytes(1, hex2bin($signature))));
            }
            $out .= Proto::message(1, Proto::entry((int) $credential, $map));
        }

        return $out;
    }

    /**
     * The first value of a field read as a uint64, or NULL when the field is absent.
     */
    private static function uint(array $fields, int $field): ?int
    {
        $value = Proto::first($fields, $field);

        return is_int($value) ? $value : null;
    }
}
