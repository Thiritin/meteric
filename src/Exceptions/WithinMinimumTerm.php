<?php

declare(strict_types=1);

namespace Meteric\Exceptions;

use Carbon\CarbonImmutable;

/**
 * A cancellation or a cheaper plan was asked for before the minimum term the
 * subscription was sold on has expired. `earliest()` is the date that would be
 * allowed, which is what a caller states rather than the raw term end.
 */
final class WithinMinimumTerm extends \InvalidArgumentException
{
    public function __construct(string $message, private readonly ?CarbonImmutable $earliest = null)
    {
        parent::__construct($message);
    }

    public function earliest(): ?CarbonImmutable
    {
        return $this->earliest;
    }
}
