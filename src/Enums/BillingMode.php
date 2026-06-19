<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum BillingMode: string
{
    case InAdvance = 'in_advance';   // prepaid: charge at period start
    case InArrears = 'in_arrears';   // postpaid: charge at period end

    public function isPrepaid(): bool
    {
        return $this === self::InAdvance;
    }
}
