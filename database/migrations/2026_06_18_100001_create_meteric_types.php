<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extensions only. Enum *values* are enforced with CHECK constraints on string
 * columns (see Meteric\Support\Db::enumCheck) rather than native pg enum types —
 * CHECKs are trivially alterable, native enum types are not (ALTER TYPE cannot
 * run in a transaction and values cannot be removed).
 */
return new class extends Migration
{
    public function up(): void
    {
        // gen_random_uuid() is core since PG 13, so pgcrypto is not needed.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist'); // EXCLUDE: scalar = + range &&
    }

    public function down(): void
    {
        // Extensions left in place; they are harmless and may be shared.
    }
};
