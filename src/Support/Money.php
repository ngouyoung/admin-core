<?php

namespace Ngos\AdminCore\Support;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * An exact money amount: an integer count of MINOR units (cents, sen, …) plus a currency code.
 *
 * Money is stored and added as an integer because binary floats can't represent decimal money exactly
 * (0.1 + 0.2 !== 0.3) — keeping the amount in minor units makes every value and every sum exact. Display,
 * parsing and rounding use the per-currency config (decimals, symbol, position, separators), so a 2-decimal
 * currency (USD: $15.00 stored as 1500) and a 0-decimal one (KHR: ៛15,000 stored as 15000) both behave
 * correctly without any float maths in the storage/arithmetic path.
 *
 * @implements Arrayable<string, int|string>
 */
final class Money implements Arrayable, JsonSerializable, Stringable
{
    public function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {}

    /** Build from a raw minor-unit integer (what the database column stores). */
    public static function fromMinor(int $minor, ?string $currency = null): self
    {
        return new self($minor, self::currencyCode($currency));
    }

    /**
     * Parse a human / major amount ("15.00", 15.5, "1,234.50", "$15", -5.05) into exact minor units for its
     * currency. The amount is parsed in integer/string space — NOT via `(float) * 10^decimals` — so it stays
     * exact: "1.005" with 2 decimals is 101, not 100 (the float `1.005 * 100` is 100.4999…). Grouping symbols
     * and the currency sign are stripped. Input is expected to be DOT-decimal (HTML number inputs and
     * Money::major() are locale-neutral); a comma is treated as a thousands separator, so a comma-DECIMAL
     * locale string (e.g. EUR "1.234,50") must be normalised to "1234.50" first — which the form, CSV export
     * and import paths already are.
     */
    public static function fromMajor(int|float|string $major, ?string $currency = null): self
    {
        $code = self::currencyCode($currency);
        $decimals = self::config($code)['decimals'];

        // Coerce floats / ints — and any scientific notation a number input may submit ("1e3") — to a plain
        // fixed-point string first, so the integer parse below sees only digits + a dot.
        $string = (string) $major;
        if (is_float($major) || is_int($major) || preg_match('/e/i', $string)) {
            $numeric = preg_replace('/[^0-9eE.+\-]/', '', $string) ?? '';
            $string = is_numeric($numeric) ? sprintf('%.10F', (float) $numeric) : $string;
        }

        // Sign: a minus anywhere before the first digit ("-5", "$-5", "- 5").
        $firstDigit = strcspn($string, '0123456789');
        $negative = str_contains(substr($string, 0, $firstDigit), '-');

        // Keep digits + dots, split on the first dot (the integer part vs the fraction).
        $digits = preg_replace('/[^0-9.]/', '', $string) ?? '';
        $dot = strpos($digits, '.');
        $int = $dot === false ? $digits : substr($digits, 0, $dot);
        $frac = $dot === false ? '' : str_replace('.', '', substr($digits, $dot + 1));
        $int = ($int === '' ? '0' : $int);

        // Round to `decimals` fractional digits by the next digit, with integer carry — no float.
        $kept = substr(str_pad($frac, $decimals, '0'), 0, $decimals);
        $roundUp = (int) ($frac[$decimals] ?? '0') >= 5;
        $magnitude = (int) ($int . $kept) + ($roundUp ? 1 : 0);

        return new self($negative ? -$magnitude : $magnitude, $code);
    }

    /** The stored amount in minor units (e.g. 1500 for $15.00). */
    public function minor(): int
    {
        return $this->minor;
    }

    /** The ISO currency code this amount is in (e.g. "USD", "KHR"). */
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * The amount as a plain decimal string with no symbol or grouping ("15.00", "15000", "-0.05") — exact
     * integer→string maths (no float). This is the value forms edit and exports/imports round-trip on.
     */
    public function major(): string
    {
        $decimals = self::config($this->currency)['decimals'];
        if ($decimals <= 0) {
            return (string) $this->minor;
        }

        $sign = $this->minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($this->minor), $decimals + 1, '0', STR_PAD_LEFT);

        return $sign . substr($digits, 0, -$decimals) . '.' . substr($digits, -$decimals);
    }

    /** The display string with symbol + grouped thousands ("$15.00", "៛15,000", "1.234,50 €"). */
    public function format(): string
    {
        $cfg = self::config($this->currency);
        $major = $this->major();
        $negative = str_starts_with($major, '-');
        [$int, $frac] = array_pad(explode('.', ltrim($major, '-'), 2), 2, '');

        $grouped = $cfg['thousands'] === ''
            ? $int
            : strrev(implode($cfg['thousands'], str_split(strrev($int), 3))); // group in 3s, exact (no float)

        $number = $grouped . ($frac !== '' ? $cfg['decimal'] . $frac : '');
        $sign = $negative ? '-' : '';

        return $cfg['position'] === 'after'
            ? $sign . $number . $cfg['symbol']
            : $sign . $cfg['symbol'] . $number;
    }

    /**
     * Add another amount of the same currency, exactly (integer maths). A null operand yields null — so a
     * computed total over a nullable money column (`subtotal + tax` with tax NULL) is blank, not a crash.
     */
    public function add(?self $other): ?self
    {
        if ($other === null) {
            return null;
        }
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    /** Subtract another amount of the same currency, exactly. A null operand yields null. */
    public function subtract(?self $other): ?self
    {
        if ($other === null) {
            return null;
        }
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /**
     * Scale the amount by a factor (e.g. price × quantity), rounded back to whole minor units. Accepts a
     * string (so a decimal-cast attribute like "2.500" works directly) and a null (which yields null, so a
     * computed total over a nullable operand is blank rather than a TypeError).
     */
    public function multiply(int|float|string|null $factor): ?self
    {
        return $factor === null ? null : new self((int) round($this->minor * (float) $factor), $this->currency);
    }

    /** Divide the amount by a divisor (e.g. split a total), rounded back to whole minor units. Null → null. */
    public function divide(int|float|string|null $divisor): ?self
    {
        return $divisor === null ? null : new self((int) round($this->minor / (float) $divisor), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }

    /**
     * @return array{minor: int, major: string, currency: string, formatted: string}
     */
    public function toArray(): array
    {
        return [
            'minor' => $this->minor,
            'major' => $this->major(),
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    /**
     * @return array{minor: int, major: string, currency: string, formatted: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /** The active currency code: the given one (upper-cased) or the configured default. */
    public static function currencyCode(?string $currency = null): string
    {
        $code = $currency !== null && $currency !== ''
            ? $currency
            : (string) config('admin-core.money.currency', 'USD');

        return strtoupper($code);
    }

    /**
     * Resolve the formatting rules for a currency, falling back to sane USD-like defaults so an
     * unconfigured currency still renders (symbol = the code itself, 2 decimals).
     *
     * @return array{symbol: string, decimals: int, position: string, thousands: string, decimal: string}
     */
    public static function config(?string $currency = null): array
    {
        $code = self::currencyCode($currency);
        /** @var array<string, array<string, mixed>> $currencies */
        $currencies = (array) config('admin-core.money.currencies', []);
        $cfg = (array) ($currencies[$code] ?? []);

        return [
            'symbol' => (string) ($cfg['symbol'] ?? $code),
            'decimals' => (int) ($cfg['decimals'] ?? 2),
            'position' => ($cfg['position'] ?? 'before') === 'after' ? 'after' : 'before',
            'thousands' => (string) ($cfg['thousands'] ?? ','),
            'decimal' => (string) ($cfg['decimal'] ?? '.'),
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency} with {$other->currency} — convert to one currency first.",
            );
        }
    }
}
