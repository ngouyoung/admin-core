<?php

namespace Ngos\AdminCore\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Reject a number that wouldn't fit a `decimal(precision, scale)` column — i.e. more than
 * (precision − scale) digits before the point, or more than `scale` digits after it. Without this the
 * database silently truncates/rounds an over-long value on save (e.g. posting 999999999999.9999 into a
 * decimal(12,4) column). Lives in src/ so a fix reaches every install via the package, not a frozen stub.
 *
 * The generator emits `new DecimalPrecision($precision, $scale)` for each `decimal:p|s` field. Pair it with
 * the `numeric` rule (which reports a non-numeric value); this rule only checks the magnitude/scale.
 */
class DecimalPrecision implements ValidationRule
{
    public function __construct(private int $precision = 10, private int $scale = 2) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return; // 'nullable' / 'numeric' own these cases
        }

        $str = ltrim($this->plain((string) $value), '+-');
        [$int, $frac] = array_pad(explode('.', $str, 2), 2, '');
        $intDigits = strlen(ltrim($int, '0'));   // "007" → 1 digit; "0"/"" → 0 (value < 1)
        $fracDigits = strlen(rtrim($frac, '0')); // trailing zeros don't count ("1.50" → 1)

        $maxInt = max($this->precision - $this->scale, 0);
        if ($intDigits > $maxInt || $fracDigits > $this->scale) {
            $fail("The :attribute must have at most {$maxInt} digit(s) before and {$this->scale} after the decimal point.");
        }
    }

    /** Expand scientific notation (1.5e3) to a plain decimal string so the digit counts are accurate. */
    private function plain(string $n): string
    {
        if (stripos($n, 'e') === false) {
            return $n;
        }
        $s = sprintf('%.20F', (float) $n);

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
