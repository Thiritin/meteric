<?php

declare(strict_types=1);

namespace Meteric\Contracts;

use Meteric\Invoicing\LineContext;
use Meteric\Invoicing\LineLabel;

/**
 * Words a charge, and so the invoice line it becomes, in the site's own
 * vocabulary. Meteric titles a line after the product it sells and the item's
 * label; a site that sells one product as several different events (a domain
 * registered, renewed or transferred) says so here instead.
 *
 * Bind one through `meteric.line_labeller`. Returning null leaves meteric's own
 * wording, so a labeller answers for the lines it knows and nothing else. The
 * answer is written onto the charge, which is why the invoice, the rendered
 * document and any e-invoice built from it cannot disagree about it.
 */
interface LineLabeller
{
    public function label(LineContext $context): ?LineLabel;
}
