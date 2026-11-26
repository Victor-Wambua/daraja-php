<?php

declare(strict_types=1);

namespace Daraja\C2b;

/** Codes a validation endpoint can answer with to reject a payment, as listed in Safaricom's C2B docs. */
enum C2bRejectCode: string
{
    case InvalidMsisdn = 'C2B00011';
    case InvalidAccountNumber = 'C2B00012';
    case InvalidAmount = 'C2B00013';
    case InvalidKycDetails = 'C2B00014';
    case InvalidShortCode = 'C2B00015';
    case Other = 'C2B00016';
}
