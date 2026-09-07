<?php

declare(strict_types=1);

namespace Meteric\Enums;

/**
 * Whether the party a billing account bills contracts as a consumer or in the
 * course of a trade.
 *
 * It is not the VAT question. `tax_profile.b2b` and a VAT id decide reverse
 * charge and are about where the supply is taxed; this one is about which body
 * of contract law the buyer is protected by, and the two disagree often enough
 * to be worth separating: a sole trader acting in trade is not a consumer and
 * may hold no VAT id at all, and a private person who typed a company name is
 * still a consumer.
 *
 * The engine reads it in one place, `consumer_notice_cap`
 * (`/usage/subscriptions#the-consumer-notice-cap`), and nothing derives it. An
 * account that has not been told carries null, and no cap applies to it: the
 * host is the only party that can know the answer, and guessing it either
 * shortens a notice period a business agreed to or holds a consumer to one they
 * cannot be held to.
 */
enum BuyerType: string
{
    case Consumer = 'consumer';
    case Business = 'business';

    public function isConsumer(): bool
    {
        return $this === self::Consumer;
    }

    public function isBusiness(): bool
    {
        return $this === self::Business;
    }
}
