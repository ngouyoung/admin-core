<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Ngos\AdminCore\Support\Search;
use Ngos\AdminCore\Tests\Fixtures\Widget;

beforeEach(function () {
    Schema::dropIfExists('widgets');
    Schema::create('widgets', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    Widget::create(['name' => 'Alpha Coffee']);
    Widget::create(['name' => 'Beta Tea']);

    config(['admin-core.search' => [
        ['model' => Widget::class, 'columns' => ['name'], 'label' => 'Widgets', 'icon' => 'bi bi-box'],
    ]]);
});

it('returns LIKE matches, grouped, with label + icon', function () {
    $results = Search::query('alpha');

    expect($results)->toHaveCount(1)
        ->and($results[0]['label'])->toBe('Alpha Coffee')
        ->and($results[0]['group'])->toBe('Widgets')
        ->and($results[0]['icon'])->toBe('bi bi-box')
        ->and($results[0]['url'])->toBeNull(); // no route configured -> no link
});

it('matches case-insensitively across rows and caps per group', function () {
    expect(Search::query('e'))->toHaveCount(2);          // both names contain "e"
    expect(Search::query('alpha', perGroup: 0))->toHaveCount(0); // cap respected
});

it('returns nothing for a blank term or when no resources are configured', function () {
    expect(Search::query(''))->toBe([]);

    config(['admin-core.search' => []]);
    expect(Search::query('alpha'))->toBe([]);
});
