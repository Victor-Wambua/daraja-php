<?php

declare(strict_types=1);

namespace Daraja\Safety;

enum BeginResult: string
{
    /** You own this key now. Do the work, then call complete() (or release() if it failed). */
    case Acquired = 'acquired';

    /** Someone else is doing the work right now. */
    case InProgress = 'in_progress';

    /** The work was already finished. Do nothing. */
    case AlreadyProcessed = 'already_processed';
}
