<?php

declare(strict_types=1);

namespace Daraja\Status;

enum IdentifierType: string
{
    case Msisdn = '1';
    case TillNumber = '2';
    case ShortCode = '4';
}
