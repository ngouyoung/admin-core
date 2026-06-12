<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Tests\Fixtures\Widget;

/**
 * ApiController::index list query — search / filter / sort / per_page — driven over
 * HTTP against the WidgetApiController fixture (searchable=name, sortable=name+created_at,
 * filterable=status). Whitelists must block any non-declared column.
 */
beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('status')->default('active');
        $table->integer('sort')->default(0);
        $table->timestamps();
    });
});

it('searches by a searchable column (LIKE)', function () {
    Widget::create(['name' => 'Alpha']);
    Widget::create(['name' => 'Beta']);

    $data = $this->getJson('/api/widgets?search=Alph')->assertOk()->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Alpha');
});

it('filters by a whitelisted column (exact match)', function () {
    Widget::create(['name' => 'A', 'status' => 'active']);
    Widget::create(['name' => 'B', 'status' => 'archived']);

    $data = $this->getJson('/api/widgets?filter[status]=archived')->assertOk()->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('B');
});

it('ignores a filter on a non-whitelisted column', function () {
    Widget::create(['name' => 'A']);
    Widget::create(['name' => 'B']);

    // `sort` is a real column but NOT in $filterable → the filter is ignored, not applied.
    $data = $this->getJson('/api/widgets?filter[sort]=999')->assertOk()->json('data');

    expect($data)->toHaveCount(2);
});

it('sorts ascending and descending by a whitelisted column', function () {
    Widget::create(['name' => 'Banana']);
    Widget::create(['name' => 'Apple']);

    $asc = collect($this->getJson('/api/widgets?sort=name')->json('data'))->pluck('name');
    expect($asc->first())->toBe('Apple');

    $desc = collect($this->getJson('/api/widgets?sort=-name')->json('data'))->pluck('name');
    expect($desc->first())->toBe('Banana');
});

it('ignores a sort on a non-whitelisted column (no error, no order applied)', function () {
    Widget::create(['name' => 'B']);
    Widget::create(['name' => 'A']);

    // `secret` isn't whitelisted (or even a column) → silently ignored, never reaches SQL.
    $this->getJson('/api/widgets?sort=secret')->assertOk()->assertJsonCount(2, 'data');
});

it('clamps per_page to the configured maximum', function () {
    config(['admin-core.api.max_per_page' => 2]);
    foreach (range(1, 5) as $i) {
        Widget::create(['name' => "W{$i}"]);
    }

    $response = $this->getJson('/api/widgets?per_page=999')->assertOk();

    expect($response->json('meta.per_page'))->toBe(2);
    expect($response->json('data'))->toHaveCount(2);
});
