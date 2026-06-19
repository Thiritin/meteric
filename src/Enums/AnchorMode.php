<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum AnchorMode: string
{
    case Signup = 'signup';       // anniversary of signup
    case FixedDay = 'fixed_day';  // align to calendar day-of-month
    case FixedDow = 'fixed_dow';  // align to day-of-week
}
