<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\Approval;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;
use Ngos\AdminCore\Tests\Fixtures\Widget;

/* Action approval: ->requiresApproval() actions file a pending request (for a requester who can't approve),
   which the inbox runs on approve / discards on reject. */

beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('status')->nullable();
        $t->string('secret')->nullable();
        $t->integer('sort')->default(0);
        $t->timestamps();
    });
    Schema::dropIfExists('approvals');
    Schema::create('approvals', function (Blueprint $t) {
        $t->id();
        $t->uuid('uuid')->unique();
        $t->nullableMorphs('requester');
        $t->nullableMorphs('approver');
        $t->string('action');
        $t->string('resource')->nullable();
        $t->string('handler');
        $t->json('payload');
        $t->string('status')->default('pending');
        $t->text('note')->nullable();
        $t->text('decision_note')->nullable();
        $t->timestamp('decided_at')->nullable();
        $t->timestamps();
    });
});

afterEach(fn () => Schema::dropIfExists('approvals'));

// -- Request side (runAction branch) ----------------------------------------------------------------

it('files a pending approval instead of executing, for a requester who cannot approve', function () {
    config()->set('admin-core.permission.enabled', true);
    Gate::define('refund-action-widget', fn () => true); // may request…
    // …but NOT approve-refund-action-widget.
    $this->actingAs(new NotifiableUser(['name' => 'Staff']));
    $w = Widget::create(['name' => 'a']);

    $this->postJson('/admin/action-widgets/action/refund', ['ids' => [$w->id], 'note' => 'please'])
        ->assertStatus(202)
        ->assertJson(['pending' => true]);

    expect($w->fresh()->status)->toBeNull()          // NOT executed
        ->and(Approval::count())->toBe(1);
    $approval = Approval::first();
    expect($approval->action)->toBe('refund')
        ->and($approval->status)->toBe('pending')
        ->and($approval->ids())->toBe([$w->id])
        ->and($approval->note)->toBe('please');
});

it('executes immediately for a user who can approve (no request filed)', function () {
    config()->set('admin-core.permission.enabled', true);
    Gate::define('refund-action-widget', fn () => true);
    Gate::define('approve-refund-action-widget', fn () => true); // an approver runs it directly
    $this->actingAs(new NotifiableUser(['name' => 'Owner']));
    $w = Widget::create(['name' => 'a']);

    $this->postJson('/admin/action-widgets/action/refund', ['ids' => [$w->id]])
        ->assertOk()
        ->assertJson(['affected' => 1]);

    expect($w->fresh()->status)->toBe('refunded')
        ->and(Approval::count())->toBe(0);
});

it('executes immediately when permissions are disabled (no approver concept)', function () {
    $w = Widget::create(['name' => 'a']); // permission.enabled defaults to false in tests

    $this->postJson('/admin/action-widgets/action/refund', ['ids' => [$w->id]])->assertOk();

    expect($w->fresh()->status)->toBe('refunded')->and(Approval::count())->toBe(0);
});

// -- Decision side (inbox approve / reject) ---------------------------------------------------------

function pendingRefund(Widget $w): Approval
{
    return Approval::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'action' => 'refund',
        'resource' => 'action-widget',
        'handler' => \Ngos\AdminCore\Tests\Fixtures\ActionWidgetController::class,
        'payload' => ['ids' => [$w->id], 'label' => 'Refund'],
        'status' => 'pending',
    ]);
}

it('runs the original action and marks approved when an approver approves', function () {
    Notification::fake();
    config()->set('admin-core.permission.enabled', true);
    Gate::define('approve-refund-action-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'Owner']));
    $w = Widget::create(['name' => 'a']);
    $approval = pendingRefund($w);

    $this->post('/admin/approvals/' . $approval->uuid . '/approve')->assertRedirect();

    expect($w->fresh()->status)->toBe('refunded')              // the held action ran
        ->and($approval->fresh()->status)->toBe('approved')
        ->and($approval->fresh()->decided_at)->not->toBeNull();
});

it('marks rejected WITHOUT running the action', function () {
    Notification::fake();
    config()->set('admin-core.permission.enabled', true);
    Gate::define('approve-refund-action-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'Owner']));
    $w = Widget::create(['name' => 'a']);
    $approval = pendingRefund($w);

    $this->post('/admin/approvals/' . $approval->uuid . '/reject', ['note' => 'nope'])->assertRedirect();

    expect($w->fresh()->status)->toBeNull()                     // never executed
        ->and($approval->fresh()->status)->toBe('rejected')
        ->and($approval->fresh()->decision_note)->toBe('nope');
});

it('forbids deciding without the action approve permission', function () {
    config()->set('admin-core.permission.enabled', true); // guest → cannot approve
    $w = Widget::create(['name' => 'a']);
    $approval = pendingRefund($w);

    $this->post('/admin/approvals/' . $approval->uuid . '/approve')->assertForbidden();

    expect($w->fresh()->status)->toBeNull()
        ->and($approval->fresh()->status)->toBe('pending');
});

it('cannot be approved twice — the atomic claim wins once', function () {
    Notification::fake();
    config()->set('admin-core.permission.enabled', true);
    Gate::define('approve-refund-action-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'Owner']));
    $w = Widget::create(['name' => 'a']);
    $approval = pendingRefund($w);

    $this->post('/admin/approvals/' . $approval->uuid . '/approve')->assertRedirect();  // first claim wins
    $this->post('/admin/approvals/' . $approval->uuid . '/approve')->assertNotFound();   // already decided

    expect($approval->fresh()->status)->toBe('approved');
});

it('does not act on an already-decided request', function () {
    config()->set('admin-core.permission.enabled', true);
    Gate::define('approve-refund-action-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'Owner']));
    $w = Widget::create(['name' => 'a']);
    $approval = pendingRefund($w);
    $approval->update(['status' => 'approved']);

    $this->post('/admin/approvals/' . $approval->uuid . '/approve')->assertNotFound();
});
