<?php

declare(strict_types=1);

namespace Daraja\Stk;

/**
 * STK result codes as listed in Safaricom's public documentation and common developer references.
 *
 * The list is not exhaustive and Safaricom can add codes. A code that is not here is not an error:
 * {@see StkResultCode::tryFromCode()} returns null and {@see StkCallback::$resultCode} still holds the raw integer.
 */
enum StkResultCode: int
{
    case Success = 0;
    case InsufficientFunds = 1;
    case TransactionInProgress = 1001;
    case TransactionExpired = 1019;
    case PushRequestError = 1025;
    case CancelledByUser = 1032;
    case UserUnreachable = 1037;
    case WrongPin = 2001;

    public static function tryFromCode(int $code): ?self
    {
        return self::tryFrom($code);
    }

    public function outcome(): StkOutcome
    {
        return match ($this) {
            self::Success => StkOutcome::Success,
            self::InsufficientFunds => StkOutcome::InsufficientFunds,
            self::CancelledByUser => StkOutcome::Cancelled,
            self::UserUnreachable, self::TransactionExpired => StkOutcome::TimedOut,
            self::WrongPin => StkOutcome::WrongPin,
            self::TransactionInProgress, self::PushRequestError => StkOutcome::Failed,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Success => 'The service request is processed successfully.',
            self::InsufficientFunds => 'The balance is insufficient for the transaction.',
            self::TransactionInProgress => 'Another transaction is already in progress for this subscriber.',
            self::TransactionExpired => 'The transaction expired before the customer responded.',
            self::PushRequestError => 'An error occurred while sending the push request.',
            self::CancelledByUser => 'The customer cancelled the request.',
            self::UserUnreachable => 'The customer could not be reached (prompt timed out).',
            self::WrongPin => 'The customer entered a wrong PIN.',
        };
    }
}
