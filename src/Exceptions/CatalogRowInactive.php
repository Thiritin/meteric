<?php

declare(strict_types=1);

namespace Meteric\Exceptions;

/** A product option, option value or addon withdrawn from sale was booked. */
final class CatalogRowInactive extends \InvalidArgumentException {}
