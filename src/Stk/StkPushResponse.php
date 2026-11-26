<?php

declare(strict_types=1);

namespace Daraja\Stk;

/** Daraja accepted the request and sent the prompt to the phone. The payment result arrives later by callback. */
final readonly class StkPushResponse
{
    public function __construct(
        public string $merchantRequestId,
        public string $checkoutRequestId,
        public string $responseCode,
        public string $responseDescription,
        public string $customerMessage,
    ) {
    }
}
