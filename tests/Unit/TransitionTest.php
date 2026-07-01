<?php

use Illuminate\Database\Eloquent\Model;
use Ngos\AdminCore\States\Transition;

/* The Transition value object behind the document state machine. */

it('defaults the label to a headline of the key', function () {
    expect(Transition::make('mark-paid')->resolveLabel())->toBe('Mark Paid')
        ->and(Transition::make('x')->label('Go')->resolveLabel())->toBe('Go');
});

it('applies only from its declared source states', function () {
    $t = Transition::make('post')->from('confirmed')->to('posted');
    expect($t->appliesTo('confirmed'))->toBeTrue()
        ->and($t->appliesTo('draft'))->toBeFalse()
        ->and($t->toState())->toBe('posted');
});

it('accepts multiple from-states, and fromAny() matches anything', function () {
    expect(Transition::make('cancel')->from(['draft', 'confirmed'])->appliesTo('confirmed'))->toBeTrue();
    expect(Transition::make('cancel')->from(['draft', 'confirmed'])->appliesTo('posted'))->toBeFalse();

    $any = Transition::make('cancel')->fromAny();
    expect($any->appliesTo('posted'))->toBeTrue()->and($any->appliesTo('whatever'))->toBeTrue();
});

it('derives the permission from key + resource, honours explicit + ungated', function () {
    expect(Transition::make('post')->resolvePermission('order'))->toBe('post-order')
        ->and(Transition::make('post')->permission('finance-post')->resolvePermission('order'))->toBe('finance-post')
        ->and(Transition::make('post')->withoutPermission()->resolvePermission('order'))->toBeNull();
});

it('only confirms when confirm() was called', function () {
    expect(Transition::make('post')->resolveConfirm())->toBeNull()
        ->and(Transition::make('post')->confirm()->resolveConfirm())->not->toBeNull()
        ->and(Transition::make('post')->confirm('Sure?')->resolveConfirm())->toBe('Sure?');
});

it('runs a guard closure against the record (default: allowed)', function () {
    $model = new class extends Model
    {
        protected $guarded = [];
    };
    $model->setAttribute('ok', false);

    expect(Transition::make('x')->passesGuard($model))->toBeTrue() // no guard → allowed
        ->and(Transition::make('x')->guard(fn ($r) => (bool) $r->ok)->passesGuard($model))->toBeFalse();
});

it('serialises to a button descriptor', function () {
    $array = Transition::make('post')->label('Post')->icon('bi bi-send')->color('success')->confirm()
        ->toArray('/admin/x/transition/1/post');

    expect($array)->toMatchArray([
        'key' => 'post', 'label' => 'Post', 'icon' => 'bi bi-send', 'color' => 'success',
        'url' => '/admin/x/transition/1/post',
    ])->and($array['confirm'])->not->toBeNull();
});

// -- Form-input actions ------------------------------------------------------------------------------

it('moves state only when a non-empty to() is set (a pure action does not)', function () {
    expect(Transition::make('post')->to('posted')->movesState())->toBeTrue()
        ->and(Transition::make('pay-in')->movesState())->toBeFalse()       // no to()
        ->and(Transition::make('x')->to(null)->movesState())->toBeFalse(); // explicit null
});

it('extracts validation rules from a form (simple list + rich descriptor)', function () {
    $t = Transition::make('close')->form([
        'counted' => ['required', 'numeric'],
        'method' => ['rules' => ['required'], 'type' => 'select', 'options' => ['cash' => 'Cash']],
    ]);

    expect($t->hasForm())->toBeTrue()
        ->and($t->formRules())->toBe([
            'counted' => ['required', 'numeric'],
            'method' => ['required'],
        ]);
    expect(Transition::make('x')->hasForm())->toBeFalse();
});

it('builds modal field descriptors — inferring type + required, honouring overrides', function () {
    $fields = Transition::make('close')->form([
        'counted' => ['required', 'numeric', 'min:0'],   // → number, required
        'note' => ['nullable', 'string'],                // → text, not required
        'agreed' => ['accepted', 'boolean'],             // → checkbox
        'when' => ['required', 'date'],                  // → date
        'method' => ['rules' => ['required'], 'type' => 'select', 'label' => 'Pay method', 'options' => ['cash' => 'Cash']],
    ])->formFields();

    expect($fields)->toHaveCount(5)
        ->and($fields[0])->toMatchArray(['name' => 'counted', 'type' => 'number', 'required' => true])
        ->and($fields[1])->toMatchArray(['name' => 'note', 'type' => 'text', 'required' => false])
        ->and($fields[2])->toMatchArray(['name' => 'agreed', 'type' => 'checkbox'])
        ->and($fields[3])->toMatchArray(['name' => 'when', 'type' => 'date'])
        ->and($fields[4])->toMatchArray(['name' => 'method', 'type' => 'select', 'label' => 'Pay method', 'options' => ['cash' => 'Cash']]);
});

it('passes the validated input to the handler as a second argument (1-arg handlers still work)', function () {
    $model = new class extends Model { protected $guarded = []; };

    // 2-arg handler receives the input
    Transition::make('close')->handle(fn ($r, $input) => $r->setAttribute('got', $input['x'] ?? null))->run($model, ['x' => 42]);
    expect($model->getAttribute('got'))->toBe(42);

    // 1-arg handler ignores the extra arg (backward-compatible) — no error
    Transition::make('post')->handle(fn ($r) => $r->setAttribute('ran', true))->run($model, ['x' => 1]);
    expect($model->getAttribute('ran'))->toBeTrue();
});

it('includes the form fields in the descriptor only when a form is declared', function () {
    expect(Transition::make('post')->to('posted')->toArray('/u')['form'])->toBeNull();
    expect(Transition::make('close')->form(['n' => ['required']])->toArray('/u')['form'])->toBeArray();
});
