<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Models\MenuItem;

/*
 * Exercises the *published* Menu manager controller stub
 * (App\Http\Controllers\Backend\MenuController) over HTTP — store / reorder / update /
 * destroy — so the runtime behaviour is regression-tested, not only dogfooded. We load
 * the real .stub file so the test tracks exactly what ships.
 */

// A minimal base controller + the stub itself, declared once at collection time.
if (! class_exists(\App\Http\Controllers\Controller::class)) {
    eval('namespace App\Http\Controllers; abstract class Controller {}');
}
if (! class_exists(\App\Http\Controllers\Backend\MenuController::class)) {
    $stub = file_get_contents(__DIR__ . '/../../stubs/access/Http/Controllers/Backend/MenuController.php.stub');
    eval(preg_replace('/^<\?php/', '', $stub));
}

beforeEach(function () {
    Cache::flush();

    Schema::create('menu_items', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('parent_id')->nullable();
        $t->string('label');
        $t->string('icon')->nullable();
        $t->string('route')->nullable();
        $t->string('url')->nullable();
        $t->string('match')->nullable();
        $t->string('permission')->nullable();
        $t->string('target')->nullable();
        $t->unsignedInteger('sort')->default(0);
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });

    // Routes mirroring the access account.php menu group. `web` gives the session
    // (for back()->with()) + SubstituteBindings (for the {menu} model binding); CSRF
    // is auto-skipped under tests. Permission middleware is off in the test env.
    $c = \App\Http\Controllers\Backend\MenuController::class;
    Route::middleware('web')->prefix('admin/menu')->name('admin.menu.')->group(function () use ($c) {
        Route::post('/', [$c, 'store'])->name('store');
        Route::post('reorder', [$c, 'reorder'])->name('reorder');
        Route::put('{menu}', [$c, 'update'])->name('update');
        Route::delete('{menu}', [$c, 'destroy'])->name('destroy');
    });
});

afterEach(fn () => Schema::dropIfExists('menu_items'));

it('store creates an item and appends it to the end of its level', function () {
    MenuItem::create(['label' => 'First', 'route' => 'admin.dashboard', 'sort' => 1]);

    $this->post('/admin/menu', [
        'label' => 'Reports', 'link_type' => 'url', 'url' => '/admin/reports',
        'icon' => 'bi bi-graph-up', 'is_active' => '1',
    ])->assertRedirect();

    $row = MenuItem::where('label', 'Reports')->first();
    expect($row)->not->toBeNull()
        ->and($row->url)->toBe('/admin/reports')
        ->and($row->route)->toBeNull()
        ->and($row->is_active)->toBeTrue()
        ->and($row->sort)->toBe(2); // appended after "First"
});

it('store with link_type=none makes a section header (no route/url)', function () {
    $this->post('/admin/menu', ['label' => 'System', 'link_type' => 'none'])->assertRedirect();

    $row = MenuItem::where('label', 'System')->first();
    expect($row->route)->toBeNull()
        ->and($row->url)->toBeNull()
        ->and($row->is_active)->toBeFalse(); // checkbox absent → unchecked
});

it('store validates: a route link requires a route', function () {
    $this->post('/admin/menu', ['label' => 'Bad', 'link_type' => 'route'])
        ->assertSessionHasErrors('route');

    expect(MenuItem::where('label', 'Bad')->exists())->toBeFalse();
});

it('reorder persists parent_id + sort from the dragged tree and busts the cache', function () {
    $a = MenuItem::create(['label' => 'A', 'route' => 'admin.dashboard', 'sort' => 1]);
    $b = MenuItem::create(['label' => 'B', 'route' => 'admin.dashboard', 'sort' => 2]);
    $c = MenuItem::create(['label' => 'C', 'route' => 'admin.dashboard', 'sort' => 3]);

    MenuItem::tree(); // prime the cache
    expect(Cache::has(MenuItem::CACHE_KEY))->toBeTrue();

    // New tree: B first (with C nested under it), then A.
    $this->postJson('/admin/menu/reorder', ['data' => [
        ['id' => $b->id, 'children' => [['id' => $c->id]]],
        ['id' => $a->id],
    ]])->assertOk()->assertJson(['status' => 'OK']);

    expect($b->fresh()->parent_id)->toBeNull()
        ->and($b->fresh()->sort)->toBe(1)
        ->and($a->fresh()->sort)->toBe(2)
        ->and($c->fresh()->parent_id)->toBe($b->id)
        ->and($c->fresh()->sort)->toBe(1)
        ->and(Cache::has(MenuItem::CACHE_KEY))->toBeFalse(); // flushed (bulk update fires no events)
});

it('update switches the link type — setting a route clears the url', function () {
    $item = MenuItem::create(['label' => 'X', 'url' => '/x', 'sort' => 1]);

    $this->put("/admin/menu/{$item->id}", [
        'label' => 'X2', 'link_type' => 'route', 'route' => 'admin.dashboard', 'is_active' => '1',
    ])->assertRedirect();

    $fresh = $item->fresh();
    expect($fresh->label)->toBe('X2')
        ->and($fresh->route)->toBe('admin.dashboard')
        ->and($fresh->url)->toBeNull();
});

it('destroy removes the item', function () {
    $item = MenuItem::create(['label' => 'Gone', 'route' => 'admin.dashboard', 'sort' => 1]);

    $this->delete("/admin/menu/{$item->id}")->assertRedirect();

    expect(MenuItem::find($item->id))->toBeNull();
});
