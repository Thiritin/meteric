<?php

declare(strict_types=1);

namespace Meteric\Exceptions;

/**
 * A billing account's records cannot be moved to another account: the same
 * account twice, an account that has not been saved, two currencies, or a payer
 * that still has sub-accounts hanging off it.
 */
final class AccountNotTransferable extends \LogicException
{
    public static function sameAccount(): self
    {
        return new self('meteric: an account cannot be transferred to itself.');
    }

    public static function notPersisted(): self
    {
        return new self('meteric: both accounts must exist before a transfer.');
    }

    public static function currencyMismatch(string $from, string $to): self
    {
        return new self("meteric: cannot transfer {$from} records onto a {$to} account.");
    }

    public static function hasChildren(): self
    {
        return new self('meteric: an account with sub-accounts cannot be transferred.');
    }
}
