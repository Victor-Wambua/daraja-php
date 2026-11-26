<?php

declare(strict_types=1);

namespace Daraja\Exception;

/** A callback does not match the payment request it claims to answer. */
final class PaymentMismatchException extends DarajaException
{
    /**
     * @param list<string> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('Callback does not match the originating request: ' . implode('; ', $problems));
    }
}
