<?php

namespace Ngos\AdminCore\Notifications\Platform;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * Resolves the raw `$to` target(s) a product passes to the platform into {@see Recipient} value objects — a user
 * model, an email string, a phone string, an explicit channel-address map, or an iterable of any of these. Pure
 * orchestration input handling; no business logic, no persistence.
 *
 * API tier: INTERNAL (a dispatcher collaborator).
 *
 * @internal
 */
final class RecipientResolver
{
    /**
     * @param  mixed  $to  a Notifiable model, an email/phone string, ['channel' => …, 'address' => …], or an iterable of them
     * @return list<Recipient>
     */
    public function resolve(mixed $to): array
    {
        // An explicit channel-address map is a single recipient, not a list to iterate.
        if (is_array($to) && isset($to['channel'], $to['address'])) {
            return [Recipient::forAddress((string) $to['channel'], (string) $to['address'])];
        }

        // A list / collection of targets → flatten.
        if (is_iterable($to) && ! $to instanceof Authenticatable) {
            $recipients = [];
            foreach ($to as $one) {
                foreach ($this->resolve($one) as $recipient) {
                    $recipients[] = $recipient;
                }
            }

            return $recipients;
        }

        return [$this->one($to)];
    }

    private function one(mixed $to): Recipient
    {
        if ($to instanceof Authenticatable) {
            // The recipient's guard is not expressible through the frozen send() surface; it defaults to null
            // (the row's guard is nullable) — see the WP-N2 deviation report's note on recipient-guard resolution.
            return Recipient::forUser($to);
        }

        if (is_string($to) && str_contains($to, '@')) {
            return Recipient::forAddress('mail', $to);
        }

        if (is_string($to) && preg_match('/^\+?[0-9][0-9\s\-()]{4,}$/', $to) === 1) {
            return Recipient::forAddress('sms', $to);
        }

        throw new InvalidArgumentException('Unresolvable notification recipient — pass a Notifiable model, an email, a phone, or [channel => …, address => …].');
    }
}
