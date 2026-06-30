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
        // The 3 = retry the transaction on a deadlock (DB::transaction's built-in concurrency-error retry). The
        // first-create unique(key, period) race is recovered inside firstOrCreate()/createOrFirst() itself.
        return DB::transaction(function () use ($key, $period) {
            // lockForUpdate serialises concurrent transactions on the existing row (a no-op on SQLite, which is
            // single-writer anyway), so the increment is never lost.
            $row = NumberSequence::query()->lockForUpdate()->firstOrCreate(
                ['key' => $key, 'period' => $period],
                ['value' => 0],
            );
            $row->increment('value');

            return (int) $row->value;
        }, 3);
    }
}
