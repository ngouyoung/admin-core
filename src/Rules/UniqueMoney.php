<?php

namespace Ngos\AdminCore\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Ngos\AdminCore\Support\Money;

/**
 * Uniqueness check for a `money` column. A money column stores exact MINOR units (1500), but the form posts the
 * MAJOR amount ("15.00") and validation runs BEFORE the MoneyCast — so a plain `unique:` / `Rule::unique` rule
 * compares "15.00" against the stored 1500 and is wrong by the currency's scale: it misses a real duplicate
 * (which then hits the DB unique index as a 500) and can wrongly reject a genuinely unique value. This rule
 * converts the posted amount to minor units with the column's currency first, then checks the DB.
 *
 * The generator emits `new UniqueMoney($table, $column, $currency, $ignore, $ignoreColumn, $withoutTrashed)`
 * for a single-column money unique (`price:money^`). Lives in src/ so a fix reaches every install via the
 * package, not a frozen stub. (A per-record `@currency` money column, or a money column inside a *composite*
 * unique, is rejected at generation — uniqueness across mixed currencies is undefined and a composite ->where
 * can't convert the value cleanly.)
 */
class UniqueMoney implements ValidationRule
{
    public function __construct(
        private string $table,
        private string $column,
        private ?string $currency = null,
        private int|string|null $ignore = null,
        private string $ignoreColumn = 'id',
        private bool $withoutTrashed = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return; // 'nullable' owns the empty case
        }

        $minor = Money::fromMajor((string) $value, $this->currency)->minor; // compare in the stored minor units

        $query = DB::table($this->table)->where($this->column, $minor);
        if ($this->ignore !== null) {
            $query->where($this->ignoreColumn, '!=', $this->ignore);
        }
        if ($this->withoutTrashed) {
            $query->whereNull('deleted_at');
        }

        if ($query->exists()) {
            $fail('The :attribute has already been taken.');
        }
    }
}
