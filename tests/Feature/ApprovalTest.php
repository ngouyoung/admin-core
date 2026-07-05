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

it('still resolves the requester after that user is soft-deleted (approvals screen keeps the name)', function () {
    Schema::dropIfExists('req_users');
    Schema::create('req_users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->softDeletes();
    });

    $reqModel = new class extends \Illuminate\Database\Eloquent\Model {
        use \Illuminate\Database\Eloquent\SoftDeletes;

        protected $table = 'req_users';
        protected $guarded = [];
        public $timestamps = false;
    };
    $bob = $reqModel->create(['name' => 'Bob']);

    $approval = \Ngos\AdminCore\Models\Approval::create([
        'action' => 'refund', 'handler' => 'X', 'payload' => ['ids' => [1]], 'status' => 'pending',
    ]);
    $approval->requester()->associate($bob)->save(); // morph columns aren't fillable

    $bob->delete(); // requester offboarded

    expect($approval->fresh()->requester)->not->toBeNull()
        ->and($approval->fresh()->requester->name)->toBe('Bob');

    Schema::dropIfExists('req_users');
});

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

it('forbids the requester from approving their OWN request (maker != checker), even with the permission', function () {
    Notification::fake();
    config()->set('admin-core.permission.enabled', true);
    Gate::define('approve-refund-action-widget', fn () => true); // Alice was later granted the approve permission

    Schema::dropIfExists('mc_users');
    Schema::create('mc_users', fn (Blueprint $t) => tap($t)->id()->string('name'));
    $userModel = new class extends \Illuminate\Foundation\Auth\User
    {
        protected $table = 'mc_users';

        public $timestamps = false;

        protected $guarded = [];
    };
    $alice = $userModel::create(['name' => 'Alice']);
    $bob = $userModel::create(['name' => 'Bob']);

    $w = Widget::create(['name' => 'a']);
    $approval = pendingRefund($w);
    $approval->requester()->associate($alice)->save(); // Alice is the MAKER

    // Alice now holds approve-… but she is the requester → self-approval is refused.
    $this->actingAs($alice);
    $this->post('/admin/approvals/' . $approval->uuid . '/approve')->assertForbidden();
    expect($approval->fresh()->status)->toBe('pending')     // not self-approved
        ->and($w->fresh()->status)->not->toBe('refunded');  // the action did not run

    // A DIFFERENT approver with the permission can still decide it.
    $this->actingAs($bob);
    $this->post('/admin/approvals/' . $approval->uuid . '/approve')->assertRedirect();
    expect($approval->fresh()->status)->toBe('approved');

    Schema::dropIfExists('mc_users');
});

it('resolves the approver on the route\'s portal guard for maker-checker, not the default guard', function () {
    // Multi-portal: the inbox mounted with Route::adminCoreApprovals('merchant') must evaluate the maker-checker
    // block (and the approve gate) against the MERCHANT user, not the default 'web' guard. With the old
    // auth()->user() (default guard = null here), the requester would NOT be recognised as the maker and could
    // self-approve. permission.enabled=false isolates the isRequester() path (checked before the perm gate).
    config()->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'users']);

    \Illuminate\Support\Facades\Route::middleware('web')->prefix('merchant')->name('merchant.')
        ->group(fn () => \Illuminate\Support\Facades\Route::adminCoreApprovals('merchant'));
    \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();

    Schema::dropIfExists('mc_users');
    Schema::create('mc_users', fn (Blueprint $t) => tap($t)->id()->string('name'));
    $userModel = new class extends \Illuminate\Foundation\Auth\User
    {
        protected $table = 'mc_users';

        public $timestamps = false;

        protected $guarded = [];
    };
    $alice = $userModel::create(['name' => 'Alice']); // the MAKER, a merchant-guard user

    $w = Widget::create(['name' => 'a']);
    $approval = pendingRefund($w);
    $approval->requester()->associate($alice)->save();

    // Authenticate Alice ONLY on the merchant guard (default 'web' stays null — the real portal shape).
    auth()->guard('merchant')->setUser($alice);

    // The route resolves the merchant guard → Alice IS the requester → her own request is refused.
    $this->post('/merchant/approvals/' . $approval->uuid . '/approve')->assertForbidden();
    expect($approval->fresh()->status)->toBe('pending')      // not self-approved
        ->and($w->fresh()->status)->not->toBe('refunded');   // the action did not run

    Schema::dropIfExists('mc_users');
});

it('rolls the approval back to pending when the action handler throws (retryable, not stuck approved)', function () {
    Notification::fake();
    config()->set('admin-core.permission.enabled', true);
    Gate::define('approve-boom-action-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'Owner']));
    $w = Widget::create(['name' => 'a']);
    $approval = Approval::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'action' => 'boom', // its handler throws
        'resource' => 'action-widget',
        'handler' => \Ngos\AdminCore\Tests\Fixtures\ActionWidgetController::class,
        'payload' => ['ids' => [$w->id], 'label' => 'Boom'],
        'status' => 'pending',
    ]);

    // The handler throws → the claim + action roll back together. Whether the test env re-throws or renders a
    // 500, the transaction is rolled back, so the approval must stay PENDING (retryable), not stuck 'approved'.
    try {
        $this->post('/admin/approvals/' . $approval->uuid . '/approve');
    } catch (\Throwable $e) {
        // expected: the handler's exception
    }

    expect($approval->fresh()->status)->toBe('pending')       // rolled back — not 'approved'
        ->and($approval->fresh()->decided_at)->toBeNull();
    Notification::assertNothingSent();                        // never told the requester it was approved
});

it('warns (not a plain success) when an approved action affects zero of its captured records', function () {
    // The approved action re-runs through the APPROVER's service query() on a fresh controller, so in a
    // tenant/scope-bound app the requester's captured ids can resolve to 0 rows (also true if they were deleted
    // between request and decision). Surface it instead of a green "approved" that silently did nothing.
    Notification::fake();
    config()->set('admin-core.permission.enabled', true);
    Gate::define('approve-refund-action-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'Owner']));

    $approval = Approval::create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'action' => 'refund',
        'resource' => 'action-widget',
        'handler' => \Ngos\AdminCore\Tests\Fixtures\ActionWidgetController::class,
        'payload' => ['ids' => [999999], 'label' => 'Refund'], // an id that resolves to no record in scope
        'status' => 'pending',
    ]);

    $this->post('/admin/approvals/' . $approval->uuid . '/approve')
        ->assertRedirect()
        ->assertSessionHas('warning')       // surfaced…
        ->assertSessionMissing('success');  // …not a green success

    expect($approval->fresh()->status)->toBe('approved'); // the decision still stands (approver acted)
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

it('treats an array note as absent instead of a TypeError 500', function () {
    Notification::fake();
    config()->set('admin-core.permission.enabled', true);
    Gate::define('approve-refund-action-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'Owner']));
    $approval = pendingRefund(Widget::create(['name' => 'a']));

    // note[]=x makes input('note') an array — it must not reach the ?string-typed claim().
    $this->post('/admin/approvals/' . $approval->uuid . '/reject', ['note' => ['x', 'y']])->assertRedirect();

    expect($approval->fresh()->status)->toBe('rejected')
        ->and($approval->fresh()->decision_note)->toBeNull(); // the junk note is dropped, not crashed on
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
