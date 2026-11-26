<?php

declare(strict_types=1);

namespace Daraja\C2b;

use Daraja\Support\Json;

/** The JSON your validation or confirmation endpoint returns to Daraja. */
final readonly class C2bValidationResponse
{
    private function __construct(
        public string $resultCode,
        public string $resultDesc,
    ) {
    }

    public static function accept(): self
    {
        return new self('0', 'Accepted');
    }

    public static function reject(C2bRejectCode $code = C2bRejectCode::Other, string $description = 'Rejected'): self
    {
        return new self($code->value, $description);
    }

    /** @return array{ResultCode: string, ResultDesc: string} */
    public function toArray(): array
    {
        return ['ResultCode' => $this->resultCode, 'ResultDesc' => $this->resultDesc];
    }

    public function toJson(): string
    {
        return Json::encode($this->toArray());
    }
}
