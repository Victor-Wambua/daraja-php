<?php

declare(strict_types=1);

namespace Daraja\Exception;

/** An inbound callback was not valid JSON or did not match the documented shape. */
final class InvalidCallbackPayloadException extends MalformedPayloadException
{
}
