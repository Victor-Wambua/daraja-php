<?php

declare(strict_types=1);

namespace Daraja\Safety;

use Daraja\Support\Json;

final readonly class ProcessedCallback
{
    public function __construct(public ProcessOutcome $outcome, public string $key)
    {
    }

    /**
     * The body to return to Daraja. Always an acceptance: Daraja only needs to know the callback arrived.
     *
     * @return array{ResultCode: int, ResultDesc: string}
     */
    public function acknowledgement(): array
    {
        return ['ResultCode' => 0, 'ResultDesc' => 'Accepted'];
    }

    public function acknowledgementJson(): string
    {
        return Json::encode($this->acknowledgement());
    }
}
