<?php

declare(strict_types=1);

namespace Daraja\Exception;

/**
 * Base type for everything this library throws on purpose.
 * Catch this to handle any Daraja-related failure in one place.
 */
class DarajaException extends \RuntimeException
{
}
