<?php

declare(strict_types=1);

namespace Meteric\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Half-open time range [start, end) mapped to a Postgres tstzrange.
 * Immutable value object.
 */
final class Period
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {
        if ($end <= $start) {
            throw new InvalidArgumentException('Period end must be after start.');
        }
    }

    public static function of(CarbonImmutable $start, CarbonImmutable $end): self
    {
        return new self($start, $end);
    }

    /** Parse a Postgres tstzrange literal: ["2026-06-01 00:00+00","2026-07-01 00:00+00"). */
    public static function fromRange(string $range): ?self
    {
        if (! preg_match('/[\[\(]\s*"?([^",]+)"?\s*,\s*"?([^",\)\]]+)"?\s*[\)\]]/', $range, $m)) {
            return null;
        }

        return new self(CarbonImmutable::parse($m[1]), CarbonImmutable::parse($m[2]));
    }

    public function toRange(): string
    {
        return sprintf('["%s","%s")', $this->start->toIso8601String(), $this->end->toIso8601String());
    }

    public function totalSeconds(): int
    {
        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }

    public function totalDays(): float
    {
        return $this->totalSeconds() / 86400;
    }

    /** Seconds of this period that fall on/after $at (the "remaining" portion). */
    public function remainingSecondsFrom(CarbonImmutable $at): int
    {
        if ($at <= $this->start) {
            return $this->totalSeconds();
        }
        if ($at >= $this->end) {
            return 0;
        }

        return $this->end->getTimestamp() - $at->getTimestamp();
    }

    public function contains(CarbonImmutable $at): bool
    {
        return $at >= $this->start && $at < $this->end;
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    /** @return array{0:string,1:string} ISO start/end — for Quote JSON. */
    public function toArray(): array
    {
        return [$this->start->toIso8601String(), $this->end->toIso8601String()];
    }
}
