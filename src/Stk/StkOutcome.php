<?php

declare(strict_types=1);

namespace Daraja\Stk;

/** What a result code means for your order, independent of the exact Daraja code. */
enum StkOutcome: string
{
    case Success = 'success';
    case Cancelled = 'cancelled';
    case TimedOut = 'timed_out';
    case InsufficientFunds = 'insufficient_funds';
    case WrongPin = 'wrong_pin';
    case Failed = 'failed';

    /** Can the customer sensibly be offered another attempt straight away? */
    public function canRetry(): bool
    {
        return $this !== self::Success;
    }
}
