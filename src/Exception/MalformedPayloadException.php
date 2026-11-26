<?php

declare(strict_types=1);

namespace Daraja\Exception;

/** A payload did not have the documented shape (missing field, wrong type, bad value). */
class MalformedPayloadException extends DarajaException
{
}
