<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

use Meteric\Contracts\LineLabeller;

/** Meteric's own wording, unchanged. The default when no labeller is configured. */
final class NullLineLabeller implements LineLabeller
{
    public function label(LineContext $context): ?LineLabel
    {
        return null;
    }
}
