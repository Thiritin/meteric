<?php

declare(strict_types=1);

namespace Meteric\Enums;

/**
 * Why a charge was raised. `kind` says what the line is; this says what meteric
 * was doing when it wrote it, which nothing on the row itself records: the
 * first period of an item and its fourth renewal are both `recurring`. A
 * `Meteric\Contracts\LineLabeller` reads it to word the line.
 */
enum ChargeReason: string
{
    /** The item's first charges: an order being materialized, or a subscription being built. */
    case Initial = 'initial';

    /** A period accrued because the previous one ended. */
    case Renewal = 'renewal';

    /** A plan change, or an item, addon or option booked into a running cycle. */
    case Change = 'change';

    /** A metered dimension rolled up at the end of its cycle. */
    case Usage = 'usage';

    /** A caller that did not say. */
    case Other = 'other';
}
