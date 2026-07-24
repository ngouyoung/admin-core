<?php

use Ngos\AdminCore\Notifications\Platform\ChannelRouter;
use Ngos\AdminCore\Notifications\Platform\Recipient;
use Ngos\AdminCore\Notifications\Platform\RecipientResolver;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;

/*
 * WP-N2 (partial) — the two dispatcher collaborators that are independent of the reported v2.1 gaps
 * (OutboundNotification↔InApp; the notifications-table transition). The InApp channel, the NotificationDispatcher,
 * the Notification store/migration, and the copy migration are BLOCKED on those gaps (see the WP-N2 deviation
 * report) and are NOT implemented here.
 */

// ---- RecipientResolver -----------------------------------------------------------------------------------------

it('resolves a Notifiable model to a user recipient', function () {
    $user = new NotifiableUser(['name' => 'A']);

    $recipients = (new RecipientResolver)->resolve($user);

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0])->toBeInstanceOf(Recipient::class)
        ->and($recipients[0]->user)->toBe($user)
        ->and($recipients[0]->guard)->toBeNull()   // guard is not expressible through send(); defaults to null
        ->and($recipients[0]->address)->toBeNull();
});

it('resolves an email string to a mail address recipient', function () {
    $recipients = (new RecipientResolver)->resolve('a@b.com');

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0]->channel)->toBe('mail')
        ->and($recipients[0]->address)->toBe('a@b.com')
        ->and($recipients[0]->user)->toBeNull();
});

it('resolves a phone string to an sms address recipient', function () {
    $recipients = (new RecipientResolver)->resolve('+855 12 345 678');

    expect($recipients[0]->channel)->toBe('sms')->and($recipients[0]->address)->toBe('+855 12 345 678');
});

it('resolves an explicit [channel, address] map to that channel address', function () {
    $recipients = (new RecipientResolver)->resolve(['channel' => 'telegram', 'address' => '@handle']);

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0]->channel)->toBe('telegram')->and($recipients[0]->address)->toBe('@handle');
});

it('flattens an iterable of mixed targets into a list of recipients', function () {
    $user = new NotifiableUser(['name' => 'A']);

    $recipients = (new RecipientResolver)->resolve([$user, 'a@b.com', ['channel' => 'sms', 'address' => '+123456']]);

    expect($recipients)->toHaveCount(3)
        ->and($recipients[0]->user)->toBe($user)
        ->and($recipients[1]->channel)->toBe('mail')
        ->and($recipients[2]->channel)->toBe('sms');
});

it('throws on an unresolvable target', function () {
    expect(fn () => (new RecipientResolver)->resolve(new stdClass))
        ->toThrow(InvalidArgumentException::class, 'Unresolvable notification recipient');
});

// ---- ChannelRouter (default + PendingNotification::channel(); no preferences) -----------------------------------

it('routes to the configured default channel when none are requested', function () {
    config(['admin-core.notifications.default_channel' => 'inapp']);

    expect((new ChannelRouter)->route([]))->toBe(['inapp']);
});

it('routes to the requested channels (de-duplicated) when the caller constrains them', function () {
    expect((new ChannelRouter)->route(['mail', 'inapp', 'mail']))->toBe(['mail', 'inapp']);
});

// ---- Architecture boundary -------------------------------------------------------------------------------------

it('the two collaborators reference no product model and no business vocabulary (structural boundary)', function () {
    foreach ([RecipientResolver::class, ChannelRouter::class] as $class) {
        $src = (string) file_get_contents((new ReflectionClass($class))->getFileName());
        expect($src)->not->toContain('App\\Models')
            ->not->toContain('Illuminate\\Database\\Eloquent\\Model');
    }
});
