<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Every exception the library defines implements this, so an application can catch "something of x402's"
 * in one place: InvalidPaymentException, FacilitatorException, LedgerUnavailableException, and job mode's
 * IllegalTransition and JobFailed.
 *
 * A mistake in how the library is called or configured still throws PHP's own \InvalidArgumentException
 * or \LogicException: those are for the developer to fix, not for the application to handle.
 */
interface X402Exception extends \Throwable
{
}
