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
