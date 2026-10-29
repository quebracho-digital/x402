<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Checks and settles payments for a seller: what goes in the middlewares'
 * $settle closure, as fn ($payment, $req) => $facilitator->settle($payment, $req).
 *
 * A refusal is an answer (isValid / success false with a reason); only an
 * unreachable facilitator or a non-x402 answer throws.
 */
interface Facilitator
{
    /**
     * @return array{isValid: bool, payer?: ?string, invalidReason?: string}
     *
     * @throws FacilitatorException
     */
    public function verify(array $payment, array $req): array;

    /**
     * @return array{success: bool, transaction: string, network: string, payer?: ?string, errorReason?: string}
     *
     * @throws FacilitatorException
     */
    public function settle(array $payment, array $req): array;
}
