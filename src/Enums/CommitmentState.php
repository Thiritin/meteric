<?php

declare(strict_types=1);

namespace Meteric\Enums;

enum CommitmentState: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Terminated = 'terminated';
}
