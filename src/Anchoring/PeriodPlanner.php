<?php

declare(strict_types=1);

namespace Meteric\Anchoring;

use Carbon\CarbonImmutable;
use Meteric\Enums\AnchorMode;
use Meteric\Enums\FirstPeriodPolicy;
use Meteric\Enums\LineKind;
use Meteric\Support\Period;
use Meteric\Support\RecurrenceRule;

/**
 * Plans the first cycle's billing from a signup instant, recurrence and anchor
 * settings. Pure + deterministic (no clock) so it's identical in a quote and in
 * the real subscription. See DESIGN §3.4.
 */
final class PeriodPlanner
{
    public function plan(
        CarbonImmutable $signup,
        RecurrenceRule $recurrence,
        AnchorMode $anchorMode = AnchorMode::Signup,
        ?int $anchorDay = null,
        FirstPeriodPolicy $firstPeriod = FirstPeriodPolicy::ProrateOnly,
    ): BillingPlan {
        // Anniversary billing or no calendar anchor → one full period from signup.
        $anchor = $this->anchorDate($signup, $anchorMode, $anchorDay);
        if ($anchor === null || $anchor <= $signup) {
            $period = $recurrence->period($signup);

            return new BillingPlan(
                [new PlannedPeriod($period, LineKind::Recurring)],
                $period,
            );
        }

        $stub = new Period($signup, $anchor);
        $firstFull = $recurrence->period($anchor);

        return match ($firstPeriod) {
            FirstPeriodPolicy::ProrateOnly => new BillingPlan(
                [new PlannedPeriod($stub, LineKind::Prorated, prorated: true)],
                $stub, // renewal at the anchor bills the first full period
            ),
            FirstPeriodPolicy::ProratePlusFull => new BillingPlan(
                [
                    new PlannedPeriod($stub, LineKind::Prorated, prorated: true),
                    new PlannedPeriod($firstFull, LineKind::FullPeriod),
                ],
                $firstFull,
            ),
            FirstPeriodPolicy::FullPeriod => (function () use ($recurrence, $signup) {
                $period = $recurrence->period($signup); // ignore stub, anchor from signup

                return new BillingPlan([new PlannedPeriod($period, LineKind::FullPeriod)], $period);
            })(),
            FirstPeriodPolicy::FreeUntilAnchor => new BillingPlan(
                [new PlannedPeriod($stub, LineKind::Prorated, prorated: true, free: true)],
                $stub,
            ),
        };
    }

    /** Next anchor boundary on/after signup, or null for anniversary mode. */
    private function anchorDate(CarbonImmutable $signup, AnchorMode $mode, ?int $day): ?CarbonImmutable
    {
        return match ($mode) {
            AnchorMode::Signup => null,
            AnchorMode::FixedDay => $this->nextDayOfMonth($signup, $day ?? 1),
            AnchorMode::FixedDow => $this->nextDayOfWeek($signup, $day ?? 1),
        };
    }

    private function nextDayOfMonth(CarbonImmutable $signup, int $day): CarbonImmutable
    {
        $clamp = static fn (CarbonImmutable $month, int $d): CarbonImmutable => $month
            ->setTime(0, 0)
            ->setDay(min($d, (int) $month->daysInMonth));

        $candidate = $clamp($signup, $day);
        if ($candidate < $signup) {
            $candidate = $clamp($signup->addMonthNoOverflow()->startOfMonth(), $day);
        }

        return $candidate;
    }

    private function nextDayOfWeek(CarbonImmutable $signup, int $isoDow): CarbonImmutable
    {
        $candidate = $signup->setTime(0, 0);
        while ((int) $candidate->dayOfWeekIso !== $isoDow || $candidate < $signup) {
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }
}
