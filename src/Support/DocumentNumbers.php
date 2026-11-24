<?php

declare(strict_types=1);

namespace Meteric\Support;

use Illuminate\Support\Facades\DB;

/**
 * Human-facing document numbers: one counter row per prefix and year, handed
 * out by an atomic upsert (INSERT ... ON CONFLICT DO UPDATE ... RETURNING) that
 * takes a row lock, so concurrent callers serialize instead of colliding on a
 * number. The statement runs in the caller's transaction, so a document whose
 * creation rolls back does not consume its number.
 *
 * The counter is the only source. Counting rows in the document table would
 * hand the same number out twice the moment one row is voided or deleted.
 */
final class DocumentNumbers
{
    /** Take the next number for a prefix in a year. */
    public static function next(string $prefix, int $year): string
    {
        $table = Pg::table('document_numbers');

        $row = DB::selectOne(
            "INSERT INTO {$table} (prefix, year, seq) VALUES (?, ?, 1)
             ON CONFLICT (prefix, year) DO UPDATE SET seq = {$table}.seq + 1
             RETURNING seq",
            [$prefix, $year],
        );

        return self::format($prefix, $year, (int) $row->seq);
    }

    /** The configured shape, taking the prefix, the year and the sequence. */
    public static function format(string $prefix, int $year, int $seq): string
    {
        return sprintf((string) config('meteric.documents.number_format', '%s-%d-%06d'), $prefix, $year, $seq);
    }

    /**
     * Number the rows of a table that have none yet, oldest first: `created_at`,
     * then `id` to break a tie. Deterministic, because the ordering is total and
     * each row takes its number off the same counter as a live document - so the
     * same rows produce the same numbers, a second run finds nothing to do, and
     * a run that stopped half way continues where it left off.
     *
     * The year is the row's own `created_at`, so a backfilled number says when
     * the document was written rather than when the backfill ran.
     */
    public static function backfill(string $table, string $prefix): void
    {
        while (true) {
            $rows = DB::table($table)
                ->select('id', 'created_at')
                ->whereNull('number')
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit(500)
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            foreach ($rows as $row) {
                $year = (int) substr((string) ($row->created_at ?? now()->toDateTimeString()), 0, 4);

                DB::table($table)->where('id', $row->id)->update([
                    'number' => self::next($prefix, $year),
                ]);
            }
        }
    }

    /** The configured prefix for a document kind. */
    public static function prefix(string $kind, string $default): string
    {
        return (string) config('meteric.documents.prefixes.'.$kind, $default);
    }
}
