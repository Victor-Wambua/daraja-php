<?php

declare(strict_types=1);

namespace Daraja\Mock;

/** @internal A request field failed the mock's validation. */
final class BadField extends \RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('Invalid ' . $field);
    }
}
