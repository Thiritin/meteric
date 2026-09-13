<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

use Meteric\Enums\ChargeReason;
use Meteric\Enums\LineKind;
use Meteric\Models\SubscriptionItem;
use Meteric\Support\Period;

/**
 * The charge meteric is about to write, handed to a
 * `Meteric\Contracts\LineLabeller` before it is written.
 *
 * `$attributes` is the whole row as it will be created, so a labeller can read
 * a column the accessors below do not name. `$title` and `$description` are
 * what meteric would have said.
 */
final readonly class LineContext
{
    /** @param  array<string,mixed>  $attributes */
    public function __construct(
        public SubscriptionItem $item,
        public ChargeReason $reason,
        public string $title,
        public ?string $description,
        public array $attributes,
    ) {}

    public function kind(): ?LineKind
    {
        $kind = $this->attributes['kind'] ?? null;

        return $kind instanceof LineKind ? $kind : LineKind::tryFrom((string) $kind);
    }

    /** The service period, where the charge names one as a Period rather than a raw range. */
    public function covers(): ?Period
    {
        $covers = $this->attributes['covers'] ?? null;

        return $covers instanceof Period ? $covers : null;
    }

    /** The usage dimension this line bills, on a usage charge. */
    public function dimensionId(): ?string
    {
        $id = $this->attributes['dimension_id'] ?? null;

        return $id === null ? null : (string) $id;
    }

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        $metadata = $this->attributes['metadata'] ?? [];

        return is_array($metadata) ? $metadata : [];
    }
}
