<?php

namespace Ngos\AdminCore\Models;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * A captured application error. Rows are written by the reportable callback the
 * AdminCoreServiceProvider registers — see {@see capture()}. Read in the admin via the
 * Error Log screen (published by `admin-core:install --access`).
 */
class ErrorLog extends Model
{
    protected $guarded = [];

    /**
     * Record a reported exception. Fully defensive — it must never raise a second error:
     *  - skips expected exceptions (4xx HTTP, validation, auth, model-not-found) so the table
     *    isn't flooded with non-errors;
     *  - no-ops when the table isn't installed (a non-`--access` app, or a fresh one pre-migrate);
     *  - swallows any failure (e.g. the DB being the thing that's down).
     */
    public static function capture(Throwable $e): void
    {
        if (static::isIgnorable($e)) {
            return;
        }

        try {
            if (! Schema::hasTable((new self)->getTable())) {
                return;
            }

            static::create([
                'type' => $e::class,
                'message' => Str::limit($e->getMessage(), 2000, ''),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => Str::limit($e->getTraceAsString(), 20000, ''),
                'url' => self::safe(fn () => request()->fullUrl()),
                'method' => self::safe(fn () => request()->method()),
                'user_id' => self::safe(fn () => Auth::id() !== null ? (string) Auth::id() : null),
            ]);
        } catch (Throwable) {
            // Never let logging an error cause another error.
        }
    }

    /** Expected exceptions we don't store: client (4xx) HTTP errors, validation, auth, not-found. */
    protected static function isIgnorable(Throwable $e): bool
    {
        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode() < 500; // keep 5xx, drop 4xx
        }

        return $e instanceof ValidationException
            || $e instanceof AuthenticationException
            || $e instanceof AuthorizationException
            || $e instanceof ModelNotFoundException;
    }

    /** Read a request/auth value without risking a nested failure during error handling. */
    private static function safe(Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return null;
        }
    }
}
