<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The x402 v2 HTTP headers: standard padded base64 of a JSON object.
 *
 * @see https://github.com/x402-foundation/x402/blob/main/specs/transports-v2/http.md
 */
final class PaymentHeaders
{
    public const REQUIRED = 'PAYMENT-REQUIRED';
    public const SIGNATURE = 'PAYMENT-SIGNATURE';
    public const RESPONSE = 'PAYMENT-RESPONSE';

    /**
     * Encodes a header value: JSON, then padded base64.
     */
    public static function encode(array $data): string
    {
        return base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * Decodes a header value.
     *
     * @throws InvalidPaymentException
     *                                 When it is not padded base64 of a JSON object
     */
    public static function decode(string $header): array
    {
        $json = base64_decode($header, true);
        $data = false === $json ? null : json_decode($json, true);
        if (!is_array($data)) {
            throw new InvalidPaymentException('The payment header is not base64-encoded JSON.');
        }

        return $data;
    }
}
