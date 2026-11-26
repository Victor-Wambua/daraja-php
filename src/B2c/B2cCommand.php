<?php

declare(strict_types=1);

namespace Daraja\B2c;

enum B2cCommand: string
{
    case SalaryPayment = 'SalaryPayment';
    case BusinessPayment = 'BusinessPayment';
    case PromotionPayment = 'PromotionPayment';
}
