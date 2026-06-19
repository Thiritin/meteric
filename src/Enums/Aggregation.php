<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum Aggregation: string
{
    case Sum = 'sum';
    case Max = 'max';
    case Last = 'last';
}
