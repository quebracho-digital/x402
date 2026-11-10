<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

/** A job state change the table in Job::TRANSITIONS does not allow: a second `_result` while one is delivering, for one. */
final class IllegalTransition extends \LogicException implements \Quebracho\X402\X402Exception
{
}
