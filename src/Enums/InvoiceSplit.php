<?php

declare(strict_types=1);

namespace Meteric\Enums;

/**
 * Whether an account's billable pool becomes one document or several.
 *
 * `Pooled` is the engine's original behaviour and the default: everything
 * pending in one currency is issued on one invoice, itemized per product.
 *
 * `PerSubscription` writes one invoice per subscription instead. Nothing about
 * the charges changes and nothing about *when* the document is written changes;
 * only how many documents the same pool becomes does. Charges that belong to no
 * subscription - an account-level one-off, a manual charge - are one document of
 * their own, because they belong to no subscription to be split by.
 *
 * It exists for a customer whose accounts payable department needs one invoice
 * per contract, which is a common request from a business billing several
 * services on one account and cannot be answered by the schedule.
 */
enum InvoiceSplit: string
{
    case Pooled = 'pooled';
    case PerSubscription = 'per_subscription';
}
