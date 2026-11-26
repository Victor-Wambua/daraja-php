<?php

declare(strict_types=1);

namespace Daraja\Callback;

/**
 * The asynchronous "Result" envelope used by B2C, Transaction Status and Reversal.
 */
final readonly class ResultCallback
{
    /**
     * @param array<string, int|float|string|bool|null> $parameters ResultParameters by Key
     */
    public function __construct(
        public int $resultCode,
        public string $resultDesc,
        public string $originatorConversationId,
        public string $conversationId,
        public string $transactionId,
        public ?int $resultType = null,
        public array $parameters = [],
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->resultCode === 0;
    }

    public function parameter(string $key): int|float|string|bool|null
    {
        return $this->parameters[$key] ?? null;
    }
}
