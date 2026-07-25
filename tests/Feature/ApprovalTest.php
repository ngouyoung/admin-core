<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
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
        $t->string('guard')->nullable();
        $t->text('note')->nullable();
        $t->text('decision_note')->nullable();
        $t->timestamp('decided_at')->nullable();
        $t->timestamps();
    });
    // The approval decision notifies the requester/approvers via the Notification Platform (InApp channel), which
    // writes to the hybrid store — give it a table to land in.
    Schema::dropIfExists('notifications');
    Schema::create('notifications', function (Blueprint $t) {
        $t->id();
        $t->uuid('uuid')->unique();
        $t->morphs('notifiable');
        $t->string('guard')->nullable();
        $t->string('type');
        $t->json('data');
        $t->timestamp('read_at')->nullable();
        $t->timestamps();
    });
});

afterEach(function () {
    Schema::dropIfExists('approvals');
    Schema::dropIfExists('notifications');
});

it('still resolves the requester after that user is soft-deleted (approvals screen keeps the name)', function () {
    Schema::dropIfExists('req_users');
    Schema::create('req_users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->softDeletes();
    });

    // Named fixture (see Fixtures\ReqUser) — an anonymous morph class name can't round-trip on pgsql. (TS-1)
    $reqModel = new \Ngos\AdminCore\Tests\Fixtures\ReqUser;
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
        ->and($approval->note)->toBe('please')
        // The filing resource's guard is recorded (default guard here) so the request surfaces only in
        // its own portal's inbox — the portal-scope hardening.
        ->and($approval->guard)->toBe((string) config('auth.defaults.guard'));
});

// -- Portal / guard scope (hardening: each inbox lists + decides only its OWN portal's requests) -----

it('scopes pending approvals per route guard — a portal inbox never lists another portal\'s requests', function () {
    Approval::create(['action' => 'adm-thing', 'handler' => 'X', 'payload' => ['ids' => [1]], 'status' => 'pending',
        'guard' => (string) config('auth.defaults.guard')]);
    Approval::create(['action' => 'mer-thing', 'handler' => 'X', 'payload' => ['ids' => [2]], 'status' => 'pending',
        'guard' => 'merchant']);
    Approval::create(['action' => 'legacy-thing', 'handler' => 'X', 'payload' => ['ids' => [3]], 'status' => 'pending']); // pre-upgrade row: guard NULL

    // The default inbox: its own requests + legacy (pre-column) rows — never a portal's.
    expect(Approval::pending()->forGuard(null)->pluck('action')->sort()->values()->all())
        ->toBe(['adm-thing', 'legacy-thing']);

    // A portal inbox: ONLY its own requests — legacy NULL rows fail closed (default inbox only).
    expect(Approval::pending()->forGuard('merchant')->pluck('action')->all())
        ->toBe(['mer-thing']);
});

it('404s a decision attempted through another portal\'s inbox (cross-guard decide is blocked)', function () {
    config()->set('admin-core.permission.enabled', false); // isolate the guard scope from the permission gate
    config()->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'users']);

    Route::middleware('web')->prefix('adminy')->name('adminy.')
        ->group(fn () => Route::adminCoreApprovals()); // default-guard inbox
    Route::middleware('web')->prefix('merchanty')->name('merchanty.')
        ->group(fn () => Route::adminCoreApprovals('merchant'));
    Route::getRoutes()->refreshNameLookups();

    $merchantApproval = Approval::create(['action' => 'payout', 'handler' => 'X',
        'payload' => ['ids' => [1]], 'status' => 'pending', 'guard' => 'merchant']);

    $this->actingAs(new NotifiableUser(['name' => 'Admin']));

    // The DEFAULT inbox's decision routes cannot reach a merchant request — 404, still pending.
    $this->post('/adminy/approvals/' . $merchantApproval->uuid . '/reject')->assertNotFound();
    expect($merchantApproval->fresh()->status)->toBe('pending');

    // Its OWN portal's decision route still works.
    $this->post('/merchanty/approvals/' . $merchantApproval->uuid . '/reject')->assertRedirect();
    expect($merchantApproval->fresh()->status)->toBe('rejected');
});

it('resolves the approver pool and inbox link on THIS resource\'s guard/prefix, not the default admin one', function () {
    // A portal resource (merchant guard + prefix). notifyApprovers() must (1) resolve the approver model from the
    // MERCHANT guard's provider — not the hardcoded default `users` provider — so a distinct-provider portal
    // notifies ITS approvers, and (2) build the inbox link inside the merchant route group, since a merchant
    // approver handed `admin.approvals.index` would 403 (or get no link in a portal-only app). Audit-12 MED,
    // guard-scoping class. Tested on the two resolution helpers directly (protected → exposed via a subclass).
    config()->set('auth.guards.merchant', ['driver' => 'session', 'provider' => 'merchants']);
    config()->set('auth.providers.merchants', ['driver' => 'eloquent', 'model' => 'App\\Models\\MerchantSentinel']);

    // Register the portal's OWN inbox route (as admin-core:portal / Route::adminCoreApprovals('merchant') would).
    Route::middleware('web')->prefix('merchant')->name('merchant.')
        ->group(fn () => Route::adminCoreApprovals('merchant'));
    Route::getRoutes()->refreshNameLookups();

    $portal = new class extends \Ngos\AdminCore\Http\Controllers\WebController
    {
        protected ?string $guard = 'merchant';

        protected ?string $routePrefix = 'merchant.';

        public function pubApproverModel(): ?string
        {
            return $this->approverModel();
        }

        public function pubInboxUrl(): ?string
        {
            return $this->approvalsInboxUrl();
        }
    };

    // (1) approver pool: the merchant provider's model, NOT the default users one.
    expect($portal->pubApproverModel())->toBe('App\\Models\\MerchantSentinel')
        ->not->toBe(config('auth.providers.users.model'));

    // (2) inbox link: the merchant route group, never the admin one.
    expect($portal->pubInboxUrl())
        ->toContain('/merchant/approvals')
        ->not->toContain('/admin/approvals');

    // A plain admin resource (no guard/prefix) still resolves the default users provider + the admin inbox.
    $admin = new class extends \Ngos\AdminCore\Http\Controllers\WebController
    {
        public function pubApproverModel(): ?string
        {
            return $this->approverModel();
        }

        public function pubInboxUrl(): ?string
        {
            return $this->approvalsInboxUrl();
        }
    };
    expect($admin->pubApproverModel())->toBe(config('auth.providers.users.model'));
    expect($admin->pubInboxUrl())->toContain('/admin/approvals');
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
    config()->set('admin-core.permission.enabled', true);
    Gate::define('approve-refund-action-widget', fn () => true); // Alice was later granted the approve permission

    Schema::dropIfExists('mc_users');
    Schema::create('mc_users', fn (Blueprint $t) => tap($t)->id()->string('name'));
    // Named fixture (see Fixtures\McUser) — an anonymous morph class name can't round-trip on pgsql. (TS-1)
    $userModel = new \Ngos\AdminCore\Tests\Fixtures\McUser;
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

    // The decision notifies the REQUESTER (Alice) through the platform — correct recipient + the approved branch.
    $n = \Ngos\AdminCore\Models\Notification::where('notifiable_id', $alice->getKey())
        ->where('notifiable_type', $alice->getMorphClass())->sole();
    expect($n->guard)->toBeNull()
        ->and($n->data['title'])->toBe(__('admin-core::admin-core.approvals.notify_approved_title'))
        ->and($n->data['icon'])->toBe('bi-check-circle');

    Schema::dropIfExists('mc_users');
});

it('gates the portal approvals inbox on the portal guard suffix, not the default guard (full-audit)', function () {
    // registerApprovalsMacro must append the guard to the Spatie permission middleware
    // (permission:list-approval,merchant) for a portal — else the middleware resolves the DEFAULT guard and
    // 403s the merchant approver from her own inbox. Mirrors Route::crud. The middleware is baked at
    // REGISTRATION time from permission.enabled, so set it true before mounting.
    config()->set('admin-core.permission.enabled', true);

    \Illuminate\Support\Facades\Route::middleware('web')->prefix('merchant')->name('merchant.')
        ->group(fn () => \Illuminate\Support\Facades\Route::adminCoreApprovals('merchant'));
    \Illuminate\Support\Facades\Route::middleware('web')->prefix('adminx')->name('adminx.')
        ->group(fn () => \Illuminate\Support\Facades\Route::adminCoreApprovals()); // no guard = default
    \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();

    // Every portal approvals route (index + approve + reject) carries the guard-suffixed gate.
    foreach (['merchant.approvals.index', 'merchant.approvals.approve', 'merchant.approvals.reject'] as $name) {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('permission:list-approval,merchant');
    }

    // A default (no-guard) inbox keeps the unsuffixed gate.
    $plain = \Illuminate\Support\Facades\Route::getRoutes()->getByName('adminx.approvals.index');
    expect($plain->gatherMiddleware())
        ->toContain('permission:list-approval')
        ->not->toContain('permission:list-approval,merchant');
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
    // Named fixture (see Fixtures\McUser) — an anonymous morph class name can't round-trip on pgsql. (TS-1)
    $userModel = new \Ngos\AdminCore\Tests\Fixtures\McUser;
    $alice = $userModel::create(['name' => 'Alice']); // the MAKER, a merchant-guard user

    $w = Widget::create(['name' => 'a']);
    $approval = pendingRefund($w);
    $approval->guard = 'merchant'; // as a merchant resource's createApproval() records — the row belongs to THIS portal's inbox
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
    // A persisted requester makes the "no notify on rollback" assertion falsifiable — notifyRequester runs only
    // AFTER the transaction commits, so a rolled-back decision must leave the requester with zero notification rows.
    Schema::dropIfExists('mc_users');
    Schema::create('mc_users', fn (Blueprint $t) => tap($t)->id()->string('name'));
    $approval->requester()->associate(\Ngos\AdminCore\Tests\Fixtures\McUser::create(['name' => 'Req']))->save();

    // The handler throws → the claim + action roll back together. Whether the test env re-throws or renders a
    // 500, the transaction is rolled back, so the approval must stay PENDING (retryable), not stuck 'approved'.
    try {
        $this->post('/admin/approvals/' . $approval->uuid . '/approve');
    } catch (\Throwable $e) {
        // expected: the handler's exception
    }

    expect($approval->fresh()->status)->toBe('pending')       // rolled back — not 'approved'
        ->and($approval->fresh()->decided_at)->toBeNull();
    expect(\Ngos\AdminCore\Models\Notification::count())->toBe(0); // rolled back → the requester got no notification
    Schema::dropIfExists('mc_users');
});

it('warns (not a plain success) when an approved action affects zero of its captured records', function () {
    // The approved action re-runs through the APPROVER's service query() on a fresh controller, so in a
    // tenant/scope-bound app the requester's captured ids can resolve to 0 rows (also true if they were deleted
    // between request and decision). Surface it instead of a green "approved" that silently did nothing.
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

it('marks rejected WITHOUT running the action, and notifies the requester (rejected branch)', function () {
    config()->set('admin-core.permission.enabled', true);
    Gate::define('approve-refund-action-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'Owner']));
    $w = Widget::create(['name' => 'a']);
    $approval = pendingRefund($w);
    Schema::dropIfExists('mc_users');
    Schema::create('mc_users', fn (Blueprint $t) => tap($t)->id()->string('name'));
    $requester = \Ngos\AdminCore\Tests\Fixtures\McUser::create(['name' => 'Req']);
    $approval->requester()->associate($requester)->save();

    $this->post('/admin/approvals/' . $approval->uuid . '/reject', ['note' => 'nope'])->assertRedirect();

    expect($w->fresh()->status)->toBeNull()                     // never executed
        ->and($approval->fresh()->status)->toBe('rejected')
        ->and($approval->fresh()->decision_note)->toBe('nope');
    // the requester is notified of the REJECTED decision (the bi-x-circle / rejected-title branch)
    $n = \Ngos\AdminCore\Models\Notification::where('notifiable_id', $requester->getKey())->sole();
    expect($n->data['title'])->toBe(__('admin-core::admin-core.approvals.notify_rejected_title'))
        ->and($n->data['icon'])->toBe('bi-x-circle');
    Schema::dropIfExists('mc_users');
});

it('treats an array note as absent instead of a TypeError 500', function () {
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
