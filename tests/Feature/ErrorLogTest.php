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
    // PHP's getTraceAsString() inlines scalar args (truncated to 15 chars, NOT redacted) — a password/
    // token passed up the stack would be stored. The rebuilt trace keeps the call chain but drops all args.
    // (A short secret so it appears whole in the raw trace — PHP truncates longer ones to 15 chars.)
    $boom = function (string $secret) {
        throw new RuntimeException('kaboom');
    };
    try {
        $boom('SEKRETPASS'); // 10 chars < PHP's 15-char trace-arg truncation → would appear verbatim raw
    } catch (RuntimeException $e) {
        // Sanity: the RAW trace really would have leaked it (guards against a no-longer-discriminating test).
        expect($e->getTraceAsString())->toContain('SEKRETPASS');
        ErrorLog::capture($e);
    }

    $trace = ErrorLog::first()->trace;
    expect($trace)->not->toBeEmpty()
        ->not->toContain('SEKRETPASS') // the arg value never appears in the STORED trace
        ->toContain('{main}');         // still a well-formed trace
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
