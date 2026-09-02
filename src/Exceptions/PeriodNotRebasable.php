<?php

declare(strict_types=1);

namespace Meteric\Exceptions;

/** An item's period cannot be moved: not active, not recurring, no period, or an end that is not after the start. */
final class PeriodNotRebasable extends \LogicException {}
