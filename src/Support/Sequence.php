<?php

namespace Ngos\AdminCore\Support;

use Illuminate\Support\Facades\DB;
use Ngos\AdminCore\Models\NumberSequence;

/**
 * Hands out the next number in a named sequence — the engine behind a `sequence` field (auto-assigned in the
 * model's creating hook). The counter is read with `lockForUpdate` so two simultaneous creates serialise and
 * never get the same number; the value is formatted with a prefix + zero-padding and an optional period that
 * resets the counter (per year / month).
 *
 *   Sequence::next('invoices.invoice_no', 'INV-')              => "INV-0001"
 *   Sequence::next('invoices.invoice_no', 'INV-', 5, 'year')   => "INV-2026-00001"  (restarts each year)
 *
 * The number is allocated INSIDE the calling create's transaction (the BaseService wraps create() in one), so
 * a rolled-back create RELEASES its number for the next create — committed rows are therefore unique, sequential
 * and gap-free, with no number ever burned by a failed attempt. (If you instead need a number that is never
 * reused even across rolled-back attempts, allocate it on a separate connection — which then leaves gaps.)
 */
final class Sequence
{
    public static function next(string $key, string $prefix = '', int $pad = 4, ?string $reset = null): string
    {
        $period = match ($reset) {
            'year' => now()->format('Y'),
            'month' => now()->format('Ym'),
            default => '',
        };

        $value = self::increment($key, $period);
        $number = str_pad((string) $value, max($pad, 1), '0', STR_PAD_LEFT);

        return $prefix . ($period !== '' ? $period . '-' : '') . $number;
    }

    /** Atomically bump and return the counter for (key, period). */
    private static function increment(string $key, string $period): int
    {
        // NO retry argument here: this runs inside the CALLING create's transaction (see the class docblock),
        // so it's nested — and Laravel disables DB::transaction()'s attempts loop once nested, throwing a
        // deadlock straight out instead of retrying (a `, 3` here was dead code in the real call path). Retry
        // on contention belongs at the OUTERMOST transaction — WebController/ApiController::store() wrap
        // create() in `DB::transaction(..., N)`, where a rollback releases this number and the whole create
        // re-runs, keeping the gap-free guarantee. The inner transaction remains so lockForUpdate holds a lock
        // when Sequence::next() is instead called standalone (outside any surrounding transaction).
        return DB::transaction(function () use ($key, $period) {
            // lockForUpdate serialises concurrent transactions on the existing row (a no-op on SQLite, which is
            // single-writer anyway), so the increment is never lost.
            $row = NumberSequence::query()->lockForUpdate()->firstOrCreate(
                ['key' => $key, 'period' => $period],
                ['value' => 0],
            );
            $row->increment('value');

            return (int) $row->value;
        });
    }
}
