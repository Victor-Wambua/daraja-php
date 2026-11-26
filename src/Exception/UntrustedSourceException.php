<?php

declare(strict_types=1);

namespace Daraja\Exception;

/** A callback arrived from an address that is not on the configured allow-list. */
final class UntrustedSourceException extends DarajaException
{
}
