<?php

it('reports the package name', function () {
    $this->artisan('admin-core:version')
        ->expectsOutputToContain('ngos/admin-core')
        ->assertSuccessful();
});
