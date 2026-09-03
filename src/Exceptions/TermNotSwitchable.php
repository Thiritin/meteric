<?php

declare(strict_types=1);

namespace Meteric\Exceptions;

/** An item's term cannot be switched mid-period: not active, not recurring, no running period, an instant outside it, or a later period already billed. */
final class TermNotSwitchable extends \LogicException {}
