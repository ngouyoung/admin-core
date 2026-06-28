<?php

use Illuminate\Support\Collection;
use Ngos\AdminCore\Actions\Action;

/* The Action value object: the fluent builder behind declarative table actions. */

it('defaults the label to a headline of the key, and lets you override it', function () {
    expect(Action::make('mark-paid')->resolveLabel())->toBe('Mark Paid')
        ->and(Action::make('mark-paid')->label('Settle')->resolveLabel())->toBe('Settle');
});

it('derives the permission from the resource pattern, falling back to the bare key', function () {
    expect(Action::make('mark-paid')->resolvePermission('order'))->toBe('mark-paid-order')
        ->and(Action::make('mark-paid')->resolvePermission(''))->toBe('mark-paid');
});

it('uses an explicit permission when given', function () {
    expect(Action::make('mark-paid')->permission('settle-invoice')->resolvePermission('order'))
        ->toBe('settle-invoice');
});

it('is ungated (null permission) after withoutPermission()', function () {
    expect(Action::make('export')->withoutPermission()->resolvePermission('order'))->toBeNull();
});

it('only asks for confirmation when confirm() was called', function () {
    expect(Action::make('go')->resolveConfirm())->toBeNull()
        ->and(Action::make('go')->confirm()->resolveConfirm())->not->toBeNull()
        ->and(Action::make('go')->confirm('Sure about this?')->resolveConfirm())->toBe('Sure about this?');
});

it('shows on both bulk and row by default; scope flags narrow it', function () {
    $both = Action::make('x');
    expect($both->isBulk())->toBeTrue()->and($both->isRow())->toBeTrue();

    expect(Action::make('x')->onlyBulk()->isRow())->toBeFalse();
    expect(Action::make('x')->onlyOnRow()->isBulk())->toBeFalse();
});

it('runs the handler over the records and returns its array result (or null)', function () {
    $records = new Collection(['a', 'b']);

    $withResult = Action::make('x')->handle(fn (Collection $r) => ['message' => $r->count() . ' done']);
    expect($withResult->run($records))->toBe(['message' => '2 done']);

    $noResult = Action::make('x')->handle(fn (Collection $r) => null);
    expect($noResult->run($records))->toBeNull();

    expect(Action::make('x')->run($records))->toBeNull(); // no handler
});

it('replaces :count in the success toast, and honours a custom message', function () {
    expect(Action::make('x')->resolveSuccess(3))->toBe(str_replace(':count', '3', __('admin-core::admin-core.toast.action_done')))
        ->and(Action::make('x')->success('All set')->resolveSuccess(3))->toBe('All set');
});

it('serialises to the front-end button shape', function () {
    $array = Action::make('publish')->label('Publish')->icon('bi bi-send')->color('success')->confirm()
        ->toArray('/admin/things/action/publish');

    expect($array)->toMatchArray([
        'key' => 'publish',
        'label' => 'Publish',
        'icon' => 'bi bi-send',
        'color' => 'success',
        'url' => '/admin/things/action/publish',
    ])->and($array['confirm'])->not->toBeNull();
});
