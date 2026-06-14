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

it('logs created, updated and deleted activity', function () {
    $widget = AuditedWidget::create(['name' => 'Alpha']);
    expect(ActivityLog::where('description', 'created')->count())->toBe(1);

    $widget->update(['name' => 'Beta']);
    expect(ActivityLog::where('description', 'updated')->count())->toBe(1);

    $widget->delete();
    expect(ActivityLog::where('description', 'deleted')->count())->toBe(1);
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
