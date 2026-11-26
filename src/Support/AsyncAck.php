<?php

declare(strict_types=1);

namespace Daraja\Support;

/**
 * Daraja's immediate acknowledgement for asynchronous APIs (B2C, Transaction Status, Reversal).
 * It only means "request accepted". The real outcome arrives later as a Result callback.
 */
final readonly class AsyncAck
{
    public function __construct(
        public string $conversationId,
        public string $originatorConversationId,
        public string $responseCode,
        public string $responseDescription,
    ) {
    }

    public static function fromPayload(Payload $payload): self
    {
        return new self(
            conversationId: $payload->looseString('ConversationID'),
            originatorConversationId: $payload->looseString('OriginatorConversationID'),
            responseCode: $payload->string('ResponseCode'),
            responseDescription: $payload->looseString('ResponseDescription'),
        );
    }
}
