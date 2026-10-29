<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The facilitator could not be reached, or did not answer in x402.
 * The message carries the facilitator's reason code when there is one.
 */
final class FacilitatorException extends \RuntimeException
{
}
