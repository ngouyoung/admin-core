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

it('still resolves the causer after that user is soft-deleted (audit attribution survives offboarding)', function () {
    Schema::dropIfExists('causer_users');
    Schema::create('causer_users', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->softDeletes();
    });

    $causerModel = new class extends \Illuminate\Database\Eloquent\Model {
        use \Illuminate\Database\Eloquent\SoftDeletes;

        protected $table = 'causer_users';
        protected $guarded = [];
        public $timestamps = false;
    };
    $alice = $causerModel->create(['name' => 'Alice']);

    $log = ActivityLog::create([
        'description' => 'updated',
        'causer_type' => $causerModel::class,
        'causer_id' => $alice->id,
    ]);

    $alice->delete(); // Alice is offboarded (soft-deleted)

    // The log must still name Alice — not lose attribution to 'system' the moment she's soft-deleted.
    expect($log->fresh()->causer)->not->toBeNull()
        ->and($log->fresh()->causer->name)->toBe('Alice');

    Schema::dropIfExists('causer_users');
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

it('does not break the write when a configured portal guard is missing from auth.php', function () {
    // A guard named in admin-core config but never defined in auth.php: resolving the causer calls
    // auth()->guard('ghost') → "Auth guard [ghost] is not defined". That must NOT roll back the write.
    config(['admin-core.permission.guards.ghost' => ['super_role' => 'ghost-admin']]);
    // deliberately DO NOT define auth.guards.ghost

    $widget = AuditedWidget::create(['name' => 'Delta']); // must not throw / roll back

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

it('boots + logs a plain --audit model with NO SoftDeletes (registerModelEvent, not the crashing statics)', function () {
    // Before the fix, bootLogsActivity()'s static::restored()/forceDeleted() on a non-SoftDeletes model fell
    // through __callStatic → `new static` → a re-entrant boot LogicException (an --audit --read-only resource).
    Schema::dropIfExists('plain_audited');
    Schema::create('plain_audited', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });

    $m = PlainAuditedWidget::create(['name' => 'X']); // booting here threw before the fix
    $m->update(['name' => 'Y']);
    $m->delete();

    expect(ActivityLog::whereIn('description', ['created', 'updated', 'deleted'])->count())->toBe(3);
});

class PlainAuditedWidget extends \Illuminate\Database\Eloquent\Model
{
    use \Ngos\AdminCore\Concerns\LogsActivity;

    protected $table = 'plain_audited';

    protected $guarded = [];
}
