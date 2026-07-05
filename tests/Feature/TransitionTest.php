<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\ActionWidgetController;
use Ngos\AdminCore\Tests\Fixtures\EnumWidget;
use Ngos\AdminCore\Tests\Fixtures\EnumWidgetStatus;
use Ngos\AdminCore\Tests\Fixtures\NotifiableUser;
use Ngos\AdminCore\Tests\Fixtures\Widget;

/* Document state machine: transitions move a record between states (atomically, with a side-effect), and a
   record in a locked state is read-only. */

beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('status')->nullable();
        $t->string('secret')->nullable();
        $t->string('photo')->nullable();
        $t->integer('sort')->default(0);
        $t->timestamps();
    });
});

function transition(Widget $w, string $key): string
{
    return '/admin/action-widgets/transition/' . $w->id . '/' . $key;
}

it('moves a record to the next state', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']);

    $this->post(transition($w, 'confirm'))->assertRedirect();

    expect($w->fresh()->status)->toBe('confirmed');
});

it('runs the transition side-effect atomically with the state change', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'confirmed']);

    $this->post(transition($w, 'post'))->assertRedirect();

    expect($w->fresh()->status)->toBe('posted')
        ->and($w->fresh()->photo)->toBe('posted-marker'); // the handler's side-effect persisted
});

it('rejects a transition that does not apply to the current state (409)', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']); // post is from "confirmed", not "draft"

    $this->post(transition($w, 'post'))->assertStatus(409);

    expect($w->fresh()->status)->toBe('draft')->and($w->fresh()->photo)->toBeNull(); // nothing ran
});

it('404s an unknown transition', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']);

    $this->post(transition($w, 'nope'))->assertNotFound();
});

it('honours a vetoing guard (422)', function () {
    $w = Widget::create(['name' => 'locked-reopen', 'status' => 'cancelled']); // guard vetoes this name

    $this->post(transition($w, 'reopen'))->assertStatus(422);

    expect($w->fresh()->status)->toBe('cancelled');
});

it('cannot run the same transition twice — the atomic state change wins once', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']);

    $this->post(transition($w, 'confirm'))->assertRedirect(); // draft → confirmed
    $this->post(transition($w, 'confirm'))->assertStatus(409); // no longer in "draft"

    expect($w->fresh()->status)->toBe('confirmed');
});

it('runs a fromAny transition on a record whose state is NULL (the claim matches NULL, not a spurious 409)', function () {
    $w = Widget::create(['name' => 'NoStatus']); // status NULL (legacy/seeded row, or created before the machine)

    // 'cancel' is ->fromAny()->to('cancelled'); the button shows, and clicking it must actually move the state.
    $this->post(transition($w, 'cancel'))->assertRedirect();

    expect($w->fresh()->status)->toBe('cancelled');
});

it('runs a self-loop transition once and dedupes the double-submit (side-effect not doubled)', function () {
    // 'recount' is fromAny->to('cancelled') with a +1 side-effect. On a record ALREADY 'cancelled' it is a
    // self-loop: the state doesn't change, so `SET status=cancelled WHERE status=cancelled` is a no-op that would
    // (a) match every submit on SQLite/PG → the +1 runs twice on a double-click, and (b) report 0 rows changed on
    // MySQL → a spurious 409 on the FIRST submit. The fix skips that no-op and dedupes via the submit token.
    $w = Widget::create(['name' => 'Doc', 'status' => 'cancelled', 'sort' => 0]);
    $token = (string) \Illuminate\Support\Str::uuid();

    // First submit succeeds (NOT a spurious 409) and runs the side-effect once.
    $this->post(transition($w, 'recount'), ['_idempotency_key' => $token])->assertRedirect();
    expect($w->fresh()->sort)->toBe(1)->and($w->fresh()->status)->toBe('cancelled');

    // A double-click reposts the SAME one-time token → deduped (409); the side-effect does NOT run again.
    $this->post(transition($w, 'recount'), ['_idempotency_key' => $token])->assertStatus(409);
    expect($w->fresh()->sort)->toBe(1); // still 1 — not doubled to 2
});

// -- Form-input actions (validated input → handler) --------------------------------------------------

it('validates posted input and passes it to the handler of a state transition', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'confirmed']);

    $this->post(transition($w, 'close'), ['counted' => '500', 'note' => 'ok'])->assertRedirect();

    expect($w->fresh()->status)->toBe('closed')                       // state moved
        ->and($w->fresh()->photo)->toBe('counted:500|note:ok');       // the validated input reached the handler
});

it('rejects invalid input (errors back) and leaves the record untouched — no lock held on a bad form', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'confirmed']);

    $this->post(transition($w, 'close'), ['counted' => '', 'note' => 'x']) // counted is required|numeric
        ->assertRedirect()->assertSessionHasErrors('counted');

    expect($w->fresh()->status)->toBe('confirmed')                  // state NOT moved
        ->and($w->fresh()->photo)->toBeNull();                     // handler never ran
});

it('runs a PURE action (no state move) and persists its side-effect', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']);
    $w->forceFill(['sort' => 0])->save();

    $this->post(transition($w, 'pay-in'), ['amount' => '10', '_idempotency_key' => 'tok-a'])->assertRedirect();

    expect($w->fresh()->sort)->toBe(10)            // the side-effect ran
        ->and($w->fresh()->status)->toBe('draft'); // state UNCHANGED (pure action)
});

it('makes a pure action idempotent via the submit token (a double-submit runs once)', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']);
    $w->forceFill(['sort' => 0])->save();

    $this->post(transition($w, 'pay-in'), ['amount' => '10', '_idempotency_key' => 'dup'])->assertRedirect();
    $this->post(transition($w, 'pay-in'), ['amount' => '10', '_idempotency_key' => 'dup'])->assertStatus(409); // same token
    $this->post(transition($w, 'pay-in'), ['amount' => '5', '_idempotency_key' => 'fresh'])->assertRedirect(); // new token

    expect($w->fresh()->sort)->toBe(15); // 10 (once, not twice) + 5
});

it('releases a pure action token when the run is vetoed, so a corrected retry with the same token goes through', function () {
    $w = Widget::create(['name' => 'no-payin', 'status' => 'draft']); // the pay-in guard vetoes this name
    $w->forceFill(['sort' => 0])->save();

    $this->post(transition($w, 'pay-in'), ['amount' => '10', '_idempotency_key' => 'retry'])->assertStatus(422);
    $w->forceFill(['name' => 'ok'])->save(); // fix the vetoing condition

    // The veto released the token, so the same token now runs (not a stale 409 that jams the action forever).
    $this->post(transition($w, 'pay-in'), ['amount' => '10', '_idempotency_key' => 'retry'])->assertRedirect();
    expect($w->fresh()->sort)->toBe(10);
});

it('surfaces the form fields in the show-page transition descriptor', function () {
    $controller = app(ActionWidgetController::class);
    $close = collect($controller->exposedTransitions(Widget::create(['name' => 'a', 'status' => 'confirmed'])))
        ->firstWhere('key', 'close');

    expect($close['form'])->toBeArray()
        ->and(collect($close['form'])->pluck('name')->all())->toBe(['counted', 'note'])
        ->and(collect($close['form'])->firstWhere('name', 'counted'))
            ->toMatchArray(['type' => 'number', 'required' => true]); // inferred from numeric + required
});

// -- Permission gate ---------------------------------------------------------------------------------

it('forbids a gated transition without the permission', function () {
    config()->set('admin-core.permission.enabled', true);
    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']);

    $this->post(transition($w, 'confirm'))->assertForbidden(); // guest lacks confirm-action-widget

    expect($w->fresh()->status)->toBe('draft');
});

it('allows a gated transition with the permission, and an ungated one always', function () {
    config()->set('admin-core.permission.enabled', true);
    Gate::define('confirm-action-widget', fn () => true);
    $this->actingAs(new NotifiableUser(['name' => 'U']));

    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']);
    $this->post(transition($w, 'confirm'))->assertRedirect();
    expect($w->fresh()->status)->toBe('confirmed');

    // 'cancel' is ->withoutPermission() — runs even for a guest with permissions on.
    auth()->logout();
    $this->post(transition($w, 'cancel'))->assertRedirect();
    expect($w->fresh()->status)->toBe('cancelled');
});

// -- Locking -----------------------------------------------------------------------------------------

it('refuses to edit or delete a record in a locked state', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'posted']); // 'posted' is a locked state

    $this->put('/admin/action-widgets/update/' . $w->id, ['name' => 'Changed'])->assertForbidden();
    $this->delete('/admin/action-widgets/delete/' . $w->id)->assertForbidden();
    // The row-menu delete goes through ajaxDelete — it must honour the lock too (was a bypass).
    $this->delete('/admin/action-widgets/ajaxDelete/' . $w->id)->assertForbidden();

    expect($w->fresh()->name)->toBe('Doc')->and(Widget::find($w->id))->not->toBeNull(); // unchanged + not deleted
});

it('refuses an update when the record becomes locked between the guard check and the write (TOCTOU race)', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']); // UNLOCKED when guardLocked() runs

    // Simulate a concurrent transition landing mid-request: the update FormRequest resolves AFTER
    // guardLocked() has already passed — flip the record into a locked state exactly there, before the
    // write executes. guardedWrite() must re-check inside its transaction and refuse.
    app()->resolving(\Ngos\AdminCore\Tests\Fixtures\ActionWidgetRequest::class, function () use ($w) {
        Widget::where('id', $w->id)->update(['status' => 'posted']);
    });

    $this->put('/admin/action-widgets/update/' . $w->id, ['name' => 'Changed'])->assertForbidden();

    expect($w->fresh()->name)->toBe('Doc'); // the stale-guard write never happened
});

it('still allows edit/delete in an unlocked state', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']);

    $this->put('/admin/action-widgets/update/' . $w->id, ['name' => 'Changed'])->assertRedirect();

    expect($w->fresh()->name)->toBe('Changed');
});

// -- The state column can't be set through the ordinary form (no transition bypass) ------------------

it('strips the state column from a normal update — status moves only via transitions', function () {
    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']);

    // Try to jump straight to "posted" (a locked state) through the edit form, skipping the post transition.
    $this->put('/admin/action-widgets/update/' . $w->id, ['name' => 'Edited', 'status' => 'posted'])
        ->assertRedirect();

    expect($w->fresh()->status)->toBe('draft')   // status was NOT changed by the form
        ->and($w->fresh()->name)->toBe('Edited'); // the rest of the edit applied
});

it('strips the state column from create too', function () {
    $this->post('/admin/action-widgets', ['name' => 'New', 'status' => 'posted'])->assertRedirect();

    expect(Widget::first()->status)->not->toBe('posted'); // can't be born into a chosen state
});

it('treats a NULL state as unlocked in bulk delete (not silently skipped)', function () {
    $nullState = Widget::create(['name' => 'NoStatus']); // status null
    $posted = Widget::create(['name' => 'P', 'status' => 'posted']);

    $this->postJson('/admin/action-widgets/bulkDelete', ['ids' => [$nullState->id, $posted->id]])
        ->assertOk()->assertJson(['deleted' => 1]);

    expect(Widget::find($nullState->id))->toBeNull()    // null state → deleted (not locked)
        ->and(Widget::find($posted->id))->not->toBeNull(); // posted → kept
});

it('excludes locked records from a bulk delete (no lock bypass)', function () {
    $locked = Widget::create(['name' => 'Posted', 'status' => 'posted']);
    $open = Widget::create(['name' => 'Draft', 'status' => 'draft']);

    $this->postJson('/admin/action-widgets/bulkDelete', ['ids' => [$locked->id, $open->id]])
        ->assertOk()
        ->assertJson(['deleted' => 1]); // only the unlocked one

    expect(Widget::find($locked->id))->not->toBeNull()  // locked → kept
        ->and(Widget::find($open->id))->toBeNull();      // unlocked → deleted
});

// -- The show-page descriptors -----------------------------------------------------------------------

it('exposes only the transitions valid for the current state', function () {
    $controller = app(ActionWidgetController::class);

    $draft = collect($controller->exposedTransitions(Widget::create(['name' => 'a', 'status' => 'draft'])))->pluck('key')->all();
    expect($draft)->toContain('confirm', 'cancel')->not->toContain('post', 'reopen');

    $posted = collect($controller->exposedTransitions(Widget::create(['name' => 'b', 'status' => 'posted'])))->pluck('key')->all();
    expect($posted)->toContain('cancel')->not->toContain('confirm', 'post');
});

// -- Enum-cast state columns -------------------------------------------------------------------------

it('handles an ENUM-CAST state column (generated status:enum) without a string-cast fatal', function () {
    // A generated document model casts `status` to a backed enum; (string) $enum fatals, so the state
    // machine must unwrap the backing value everywhere it reads the column.
    $w = Widget::create(['name' => 'Doc', 'status' => 'draft']);
    $w->mergeCasts(['status' => TransitionTestStatus::class]);
    expect($w->status)->toBeInstanceOf(TransitionTestStatus::class); // really enum-cast now

    $controller = app(ActionWidgetController::class);

    // transitionsFor (the show page) reads the state — must resolve 'draft' transitions, not fatal.
    $keys = collect($controller->exposedTransitions($w))->pluck('key')->all();
    expect($keys)->toContain('confirm')->not->toContain('post');
});

// EnumWidget CLASS-casts status to a backed enum, so these HTTP paths re-fetch an enum-cast record — covering
// the two sites transitionsFor() can't reach: runTransition() (POST) and isLockedState() (the lock guard).

it('runs an HTTP transition on an ENUM-CAST status column — runTransition unwraps the enum, no fatal', function () {
    $w = EnumWidget::create(['name' => 'Doc', 'status' => 'draft']);

    $this->post('/admin/enum-widgets/transition/' . $w->id . '/confirm')->assertRedirect();

    expect($w->fresh()->status)->toBe(EnumWidgetStatus::Confirmed); // moved; (string)$enum would have 500'd
});

it('rejects an HTTP transition invalid for the ENUM-CAST current state (409, not a 500)', function () {
    $w = EnumWidget::create(['name' => 'Doc', 'status' => 'draft']); // post is from "confirmed"

    $this->post('/admin/enum-widgets/transition/' . $w->id . '/post')->assertStatus(409);

    expect($w->fresh()->status)->toBe(EnumWidgetStatus::Draft);
});

it('locks edit + delete on an ENUM-CAST status column in a locked state — isLockedState unwraps the enum', function () {
    $w = EnumWidget::create(['name' => 'Doc', 'status' => 'posted']); // 'posted' is locked

    $this->put('/admin/enum-widgets/update/' . $w->id, ['name' => 'Changed'])->assertForbidden();
    $this->delete('/admin/enum-widgets/delete/' . $w->id)->assertForbidden();

    expect($w->fresh()->name)->toBe('Doc'); // unchanged — and no 500 from a string-cast on the enum
});

it('still allows edit on an ENUM-CAST status column in an unlocked state', function () {
    $w = EnumWidget::create(['name' => 'Doc', 'status' => 'draft']);

    $this->put('/admin/enum-widgets/update/' . $w->id, ['name' => 'Changed'])->assertRedirect();

    expect($w->fresh()->name)->toBe('Changed');
});

enum TransitionTestStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
    case Closed = 'closed';
}
