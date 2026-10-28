<?php

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that pays x402 402s and retries once, within a budget.
 *
 * Wrap any PSR-18 client with it, e.g. the one an MCP HTTP transport uses,
 * and paid tools just work for the agent behind it.
 */
final class PayingHttpClient implements ClientInterface {

  /**
   * @param Scheme[] $schemes
   *   The schemes this buyer can pay with, each holding its key.
   * @param Budget $budget
   *   Refuses whatever would exceed the spending cap.
   * @param ?\Closure $onPaid
   *   fn(array $req, array $receipt): void — e.g. to log what was spent.
   */
  public function __construct(
    private readonly ClientInterface $client,
    private readonly array $schemes,
    private readonly Budget $budget,
    private readonly ?\Closure $onPaid = NULL,
  ) {}

  public function sendRequest(RequestInterface $request): ResponseInterface {
    $response = $this->client->sendRequest($request);
    $header = $response->getHeaderLine(PaymentHeaders::REQUIRED);
    if ($response->getStatusCode() !== 402 || $header === '') {
      return $response;
    }
    $required = PaymentHeaders::decode($header);

    // The first offer we have a scheme for and the budget allows.
    foreach ($required['accepts'] ?? [] as $req) {
      foreach ($this->schemes as $scheme) {
        if (!$scheme->supports((string) ($req['network'] ?? '')) || !$this->budget->allows($req)) {
          continue;
        }
        $payment = $scheme->pay($required, $req);
        // The same request, paid: the body is read again from the start.
        if ($request->getBody()->isSeekable()) {
          $request->getBody()->rewind();
        }
        $paid = $this->client->sendRequest($request->withHeader(PaymentHeaders::SIGNATURE, PaymentHeaders::encode($payment)));
        $receipt = $paid->getHeaderLine(PaymentHeaders::RESPONSE);
        // Spent once the seller says it settled; a refused payment is not.
        if ($receipt !== '') {
          $receipt = PaymentHeaders::decode($receipt);
          if (!empty($receipt['success'])) {
            $this->budget->spend($req);
            $this->onPaid?->__invoke($req, $receipt);
          }
        }
        return $paid;
      }
    }
    // Nothing we can or may pay: the caller sees the 402 itself.
    return $response;
  }

}
