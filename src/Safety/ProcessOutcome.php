<?php

declare(strict_types=1);

namespace Daraja\Safety;

enum ProcessOutcome: string
{
    /** Your handler ran for the first time. */
    case Handled = 'handled';

    /** The callback was seen before; your handler was NOT called again. */
    case Duplicate = 'duplicate';

    /** Another worker is handling the same callback right now; your handler was NOT called. */
    case InProgress = 'in_progress';
}
