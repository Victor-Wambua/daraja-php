<?php

declare(strict_types=1);

namespace Daraja\C2b;

/** What Daraja does when your validation URL is unreachable. */
enum C2bResponseType: string
{
    case Completed = 'Completed';
    case Cancelled = 'Cancelled';
}
