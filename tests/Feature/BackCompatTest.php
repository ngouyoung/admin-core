<?php

use Ngos\AdminCore\Http\Controllers\CrudController;
use Ngos\AdminCore\Http\Controllers\WebController;
use Ngos\AdminCore\Services\BaseService;
use Ngos\AdminCore\Services\CrudService;

/**
 * The classes were renamed (CrudController → WebController, CrudService →
 * BaseService); the old names live on as deprecated aliases so existing
 * `extends CrudController` / `extends CrudService` code keeps working.
 */
it('keeps CrudController as a deprecated alias of WebController', function () {
    expect(class_exists(CrudController::class))->toBeTrue();
    expect(is_subclass_of(CrudController::class, WebController::class))->toBeTrue();
});

it('keeps CrudService as a deprecated alias of BaseService', function () {
    expect(class_exists(CrudService::class))->toBeTrue();
    expect(is_subclass_of(CrudService::class, BaseService::class))->toBeTrue();
});
