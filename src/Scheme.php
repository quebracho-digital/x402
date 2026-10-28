<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * One x402 payment scheme on one chain family (eip155, solana, near…).
 *
 * The seam comes from the x402 spec, not from EVM: a buyer turns a payment
 * requirement into a payment payload, and a seller checks that payload
 * before serving. Settling is the facilitator's job and stays out of here.
 *
 * Experimental until 1.0: a new chain family may still reshape it.
 */
interface Scheme {

  /**
   * Whether this scheme handles a CAIP-2 network, e.g. "eip155:84532".
   */
  public function supports(string $network): bool;

  /**
   * The buyer's signed payment for one entry of a 402's `accepts`.
   *
   * @param array $required
   *   The decoded PAYMENT-REQUIRED header.
   * @param array $req
   *   The chosen entry of $required['accepts'].
   */
  public function pay(array $required, array $req): array;

  /**
   * Why a payment does not pay exactly $req, or NULL when it does.
   *
   * Offline checks only: what the chain alone knows (balance, spent nonce)
   * is the facilitator's to verify.
   */
  public function verify(array $payment, array $req): ?string;

  /**
   * Who pays, in the chain's own form, or NULL when unreadable.
   *
   * Trust it only after verify() passed: before that, anyone wrote it.
   */
  public function payer(array $payment): ?string;

}
