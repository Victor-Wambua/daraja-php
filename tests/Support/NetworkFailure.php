<?php

declare(strict_types=1);

namespace Daraja\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;

final class NetworkFailure extends \RuntimeException implements ClientExceptionInterface
{
}
