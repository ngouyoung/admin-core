<?php

use Illuminate\Http\Request;
use Ngos\AdminCore\Http\Middleware\AuthorizeApiPermission;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

/*
 * AuthorizeApiPermission gates an API route on the permission guard where the admin's permissions live
 * (web by default), NOT the request's auth guard — so a web admin's Passport/Sanctum token is authorized.
 * A stock `permission:` check resolves on the active (api) guard and 403s; this is the fix.
 */

/** A user stub that records which (permission, guard) it was asked about. */
function permUser(bool $grant): object
{
    return new class($grant)
    {
        public array $checked = [];

        public function __construct(private bool $grant) {}

        public function hasPermissionTo(string $permission, ?string $guard = null): bool
        {
            $this->checked = [$permission, $guard];

            return $this->grant;
        }
    };
}

function runApiGate(object $user, string $permission, ?string $guard = null): Response
{
    $request = Request::create('/x');
    $request->setUserResolver(fn () => $user);

    return (new AuthorizeApiPermission)->handle($request, fn () => new Response('ok'), $permission, $guard);
}

it('checks the configured permission guard, not the active auth guard', function () {
    config(['admin-core.permission.guard' => 'web']);
    config(['auth.defaults.guard' => 'api']); // the API request runs under a different active guard

    $user = permUser(grant: true);
    $response = runApiGate($user, 'list-thing');

    expect($response->getContent())->toBe('ok')
        ->and($user->checked)->toBe(['list-thing', 'web']); // resolved on web, ignoring the active api guard
});

it('forbids when the user lacks the permission', function () {
    expect(fn () => runApiGate(permUser(grant: false), 'list-thing'))
        ->toThrow(UnauthorizedException::class);
});

it('passes an explicit guard through (a --guard=api resource keeps the multi-portal model)', function () {
    $user = permUser(grant: true);
    runApiGate($user, 'list-thing', 'api');

    expect($user->checked)->toBe(['list-thing', 'api']);
});
