<?php

declare(strict_types=1);

namespace Meteric\Subscriptions;

use Brick\Money\Money;
use Meteric\Support\Period;

/**
 * What switchTerm() would do: the running period closed at the switch instant,
 * the period that opens on the new term, and the three figures that settle
 * between them. `unused` is what the closing window was billed and will not
 * deliver, written as a positive figure and charged as a credit. `usage` is the
 * closing window's metered usage, rated but not yet billed. `recurring` is the
 * new period billed whole. `total` is what all three come to: what the switch
 * puts on the customer's next invoice.
 */
final readonly class TermSwitchPreview
{
    /** @param list<array{dimension:string,used:float,unit:?string,amount_minor:int}> $usageLines */
    public function __construct(
        public Period $closing,
        public Period $opening,
        public Money $unused,
        public Money $usage,
        public Money $recurring,
        public array $usageLines = [],
    ) {}

    /** Net of the settlement and the new period: positive is owed, negative is credit. */
    public function total(): Money
    {
        return $this->recurring->plus($this->usage)->minus($this->unused);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'closing' => $this->closing->toArray(),
            'opening' => $this->opening->toArray(),
            'unused_minor' => $this->unused->getMinorAmount()->toInt(),
            'usage_minor' => $this->usage->getMinorAmount()->toInt(),
            'usage_lines' => $this->usageLines,
            'recurring_minor' => $this->recurring->getMinorAmount()->toInt(),
            'total_minor' => $this->total()->getMinorAmount()->toInt(),
            'currency' => $this->recurring->getCurrency()->getCurrencyCode(),
        ];
    }
}
