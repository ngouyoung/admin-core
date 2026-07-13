<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Ngos\AdminCore\Models\ErrorLog;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    Schema::create('error_logs', function (Blueprint $t) {
        $t->id();
        $t->string('type');
        $t->text('message');
        $t->string('file')->nullable();
        $t->longText('trace')->nullable();
        $t->string('url')->nullable();
        $t->string('method', 10)->nullable();
        $t->string('user_id')->nullable();
        $t->timestamps();
    });
});

afterEach(fn () => Schema::dropIfExists('error_logs'));

it('captures a real exception with its type, message and file:line', function () {
    ErrorLog::capture(new RuntimeException('boom'));

    $row = ErrorLog::first();
    expect($row)->not->toBeNull()
        ->and($row->type)->toBe(RuntimeException::class)
        ->and($row->message)->toBe('boom')
        ->and($row->file)->toContain(':')        // file:line
        ->and($row->trace)->not->toBeEmpty();
});

it('redacts secret query params from the stored URL (a reset token / email is not persisted)', function () {
    // A 500 while rendering a URL that carries a secret (a password-reset link, an api token) must not
    // land that secret in the admin-viewable error_logs table.
    $this->get('/x?email=victim@example.com&token=SUPERSECRETtoken123&keep=ok'); // establishes request()
    ErrorLog::capture(new RuntimeException('boom'));

    $url = ErrorLog::first()->url;
    expect($url)->toContain('email=[redacted]')
        ->toContain('token=[redacted]')
        ->not->toContain('victim@example.com')
        ->not->toContain('SUPERSECRETtoken123')
        ->toContain('keep=ok'); // a non-sensitive param is left readable for debugging
});

it('stores an ARGUMENT-FREE stack trace (a string arg like a password is never inlined)', function () {
    // A scalar arg (a password/token/OTP) passed up the call chain lives in the exception's *structured* trace
    // frames (`getTrace()[$i]['args']`). The stored trace is rebuilt from those frames keeping the call chain but
    // dropping every 'args' value — see ErrorLog::argFreeTrace(), which reads getTrace() and never the string form.
    //
    // Whether the frames carry args at all is governed by the `zend.exception_ignore_args` INI: OFF (the dev
    // default) captures them; ON (php.ini-production, and GitHub Actions' setup-php default) strips them at throw
    // time. So we pin the INI OFF *before* the throw (trace args are captured at construction) — a runtime ini_set
    // is enough to restore the structured getTrace() args on every runtime. The precondition is asserted on the
    // structured frames, NOT on getTraceAsString(): hardened builds set `zend.exception_string_param_max_len=0`
    // (setup-php's default), which renders every string arg in the *string* form as '...' regardless of the args
    // INI, so the raw value never appears there — but the feature consumes getTrace(), which is where it must (and
    // does) appear. The STORED-trace redaction below is the real guarantee.
    $ignoreArgs = ini_get('zend.exception_ignore_args');
    ini_set('zend.exception_ignore_args', '0');

    try {
        $boom = function (string $secret) {
            throw new RuntimeException('kaboom');
        };
        try {
            $boom('SEKRETPASS');
        } catch (RuntimeException $e) {
            // Sanity: the secret really is present in the structured trace the feature consumes (getTrace()), so
            // the arg-dropping below is genuinely exercised (guards against a no-longer-discriminating test).
            $capturedArgs = collect($e->getTrace())->flatMap(fn ($frame) => $frame['args'] ?? [])->all();
            expect($capturedArgs)->toContain('SEKRETPASS');
            ErrorLog::capture($e);
        }

        $trace = ErrorLog::first()->trace;
        expect($trace)->not->toBeEmpty()
            ->not->toContain('SEKRETPASS') // the arg value never appears in the STORED trace
            ->toContain('{main}');         // still a well-formed trace
    } finally {
        ini_set('zend.exception_ignore_args', $ignoreArgs === false ? '0' : $ignoreArgs);
    }
});

it('ignores expected exceptions (4xx, validation, auth) so the log is not flooded', function () {
    ErrorLog::capture(new NotFoundHttpException);                       // 404
    ErrorLog::capture(new HttpException(403, 'forbidden'));             // 4xx
    ErrorLog::capture(ValidationException::withMessages(['x' => 'y']));
    ErrorLog::capture(new AuthenticationException);

    expect(ErrorLog::count())->toBe(0);
});

it('captures a 5xx HttpException but not a 4xx', function () {
    ErrorLog::capture(new HttpException(500, 'server blew up'));
    ErrorLog::capture(new HttpException(404, 'missing'));

    expect(ErrorLog::count())->toBe(1)
        ->and(ErrorLog::first()->message)->toBe('server blew up');
});

it('never throws — no-ops when the table is missing', function () {
    Schema::drop('error_logs');

    ErrorLog::capture(new RuntimeException('boom')); // must not raise a second error

    expect(Schema::hasTable('error_logs'))->toBeFalse();
});

it('prunes errors older than the retention window, keeping recent ones', function () {
    config()->set('admin-core.error_log.retention_days', 30);

    ErrorLog::create(['type' => 'A', 'message' => 'recent']);
    $old = ErrorLog::create(['type' => 'B', 'message' => 'old']);
    ErrorLog::whereKey($old->id)->update(['created_at' => now()->subDays(40)]);

    (new ErrorLog)->pruneAll();

    expect(ErrorLog::count())->toBe(1)
        ->and(ErrorLog::first()->message)->toBe('recent');
});

it('keeps every error when retention is 0 (pruning disabled)', function () {
    config()->set('admin-core.error_log.retention_days', 0);

    $old = ErrorLog::create(['type' => 'B', 'message' => 'old']);
    ErrorLog::whereKey($old->id)->update(['created_at' => now()->subDays(400)]);

    (new ErrorLog)->pruneAll();

    expect(ErrorLog::count())->toBe(1);
});
