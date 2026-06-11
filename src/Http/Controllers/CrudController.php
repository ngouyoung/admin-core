<?php

namespace Ngos\AdminCore\Http\Controllers;

/**
 * @deprecated Renamed to {@see WebController} (the web channel; its API twin is
 *             ApiController). Kept as a back-compat alias so existing
 *             `extends CrudController` controllers keep working. Will be removed
 *             in a future major version — extend WebController instead.
 */
abstract class CrudController extends WebController
{
}
