<?php

declare(strict_types=1);

namespace Daraja\Exception;

/** The OAuth token could not be obtained (bad consumer key/secret) or was rejected. */
final class AuthenticationException extends ApiException
{
}
