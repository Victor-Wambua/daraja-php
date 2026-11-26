<?php

declare(strict_types=1);

namespace Daraja\Stk;

enum TransactionType: string
{
    /** Paybill: PartyB is the paybill number. */
    case PayBill = 'CustomerPayBillOnline';

    /** Till: BusinessShortCode is the store number and PartyB is the till number. */
    case BuyGoods = 'CustomerBuyGoodsOnline';
}
