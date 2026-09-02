<?php

declare(strict_types=1);

namespace Meteric\Exceptions;

/**
 * A frozen order line whose base price cannot be renewed exactly as a
 * subscription item: an item bills its own periods through amountFor(),
 * which knows nothing of relative pricing, allowances, blocks or caps.
 */
final class LineNotMaterializable extends \LogicException {}
