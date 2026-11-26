<?php

declare(strict_types=1);

namespace Daraja\Stk;

/**
 * The state of an STK Push as reported by the query endpoint.
 * While the customer has not answered yet, {@see self::$pending} is true and the result fields are null.
 */
final readonly class StkQueryResult
{
    public function __construct(
        public string $checkoutRequestId,
        public bool $pending,
        public ?int $resultCode = null,
        public ?string $resultDesc = null,
        public ?string $merchantRequestId = null,
    ) {
    }

    public function outcome(): ?StkOutcome
    {
        if ($this->resultCode === null) {
            return null;
        }

        return StkResultCode::tryFromCode($this->resultCode)?->outcome() ?? StkOutcome::Failed;
    }

    public function isSuccessful(): bool
    {
        return $this->resultCode === 0;
    }
}
