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
