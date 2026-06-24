<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\ActivityLog;
use Ngos\AdminCore\Tests\Fixtures\AuditedWidget;

beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('secret')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });

    Schema::dropIfExists('activity_logs');
    Schema::create('activity_logs', function (Blueprint $table) {
        $table->id();
        $table->string('log_name')->nullable();
        $table->string('description');
        $table->string('subject_type')->nullable();
        $table->string('subject_id')->nullable();
        $table->string('causer_type')->nullable();
        $table->string('causer_id')->nullable();
        $table->json('properties')->nullable();
        $table->timestamps();
    });
});

it('logs created, updated, deleted, restored and (distinctly) force-deleted activity', function () {
    $widget = AuditedWidget::create(['name' => 'Alpha']);
    expect(ActivityLog::where('description', 'created')->count())->toBe(1);

    $widget->update(['name' => 'Beta']);
    expect(ActivityLog::where('description', 'updated')->count())->toBe(1);

    $widget->delete();
    expect(ActivityLog::where('description', 'deleted')->count())->toBe(1);

    $widget->restore();
    expect(ActivityLog::where('description', 'restored')->count())->toBe(1); // un-delete is audited too

    $widget->forceDelete();
    // A permanent delete is logged distinctly as force_deleted — not counted again as a soft 'deleted'.
    expect(ActivityLog::where('description', 'force_deleted')->count())->toBe(1)
        ->and(ActivityLog::where('description', 'deleted')->count())->toBe(1);
});

it('never logs a password/hashed column (even when not named "password")', function () {
    $widget = AuditedWidget::create(['name' => 'Alpha', 'secret' => 'topsecret123']);
    $widget->update(['name' => 'Beta', 'secret' => 'changed456']);

    $created = ActivityLog::where('description', 'created')->first();
    $updated = ActivityLog::where('description', 'updated')->first();

    // The hash is excluded from both the create snapshot and the update diff.
    expect($created->properties['attributes'])->not->toHaveKey('secret')
        ->and($updated->properties['attributes'])->not->toHaveKey('secret')
        ->and($updated->properties['old'] ?? [])->not->toHaveKey('secret')
        ->and(json_encode($created->properties))->not->toContain('$2y$'); // no bcrypt hash anywhere
});

it('records the subject, log name and changed attributes', function () {
    $widget = AuditedWidget::create(['name' => 'Alpha']);
    $widget->update(['name' => 'Beta']);

    $log = ActivityLog::where('description', 'updated')->first();

    expect($log->log_name)->toBe('AuditedWidget');
    expect($log->subject_id)->toBe((string) $widget->id);
    expect($log->properties['attributes'])->toHaveKey('name');
    expect($log->properties['old']['name'])->toBe('Alpha');
});

it('does not break the write when the activity_logs table is missing (pre-migrate)', function () {
    Schema::dropIfExists('activity_logs');

    // The model boots LogsActivity; recordActivity must no-op (not 500 / roll back) when the table is gone.
    $widget = AuditedWidget::create(['name' => 'Gamma']);

    expect($widget->exists)->toBeTrue()
        ->and(AuditedWidget::find($widget->id))->not->toBeNull();
});

it('attributes the change to the active portal guard, not the default web guard', function () {
    Schema::create('merchant_users', function (Blueprint $t) {
        $t->id();
        $t->string('name')->nullable();
    });
    $userModel = new class extends \Illuminate\Foundation\Auth\User
    {
        protected $table = 'merchant_users';

        public $timestamps = false;

        protected $guarded = [];
    };
    $merchant = $userModel::create(['name' => 'Shopkeeper']);

    // A 'merchant' portal guard, authenticated — while the default 'web' guard is NOT.
    config(['auth.guards.merchant' => ['driver' => 'session', 'provider' => 'merchant_users']]);
    config(['auth.providers.merchant_users' => ['driver' => 'eloquent', 'model' => $userModel::class]]);
    config(['admin-core.permission.guards.merchant' => ['super_role' => 'merchant-admin']]);
    auth()->guard('merchant')->setUser($merchant);
    expect(auth()->guard('merchant')->check())->toBeTrue()
        ->and(auth()->check())->toBeFalse(); // default web guard is unauthenticated → old code logged null

    $widget = AuditedWidget::create(['name' => 'Alpha']);

    $log = ActivityLog::where('description', 'created')->first();
    expect($log->causer_id)->toBe((string) $merchant->getKey())        // the merchant, not null/wrong
        ->and($log->causer_type)->toBe($merchant->getMorphClass());

    Schema::dropIfExists('merchant_users');
});
