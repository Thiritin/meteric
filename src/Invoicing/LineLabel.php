<?php

declare(strict_types=1);

namespace Meteric\Invoicing;

/**
 * What a line says, as a `Meteric\Contracts\LineLabeller` wants it said.
 *
 * It replaces both fields rather than patching one: a labeller that only means
 * to retitle a line carries `$context->description` over into the second
 * argument. That is the only reading under which a label can also clear a
 * description meteric would otherwise have written.
 */
final readonly class LineLabel
{
    public function __construct(
        public string $title,
        public ?string $description = null,
    ) {}
}
