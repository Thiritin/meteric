<?php

declare(strict_types=1);

use Meteric\Tests\TestCase;

// Feature tests need a real PostgreSQL (tstzrange, btree_gist, enums).
uses(TestCase::class)->in('Feature');
