<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Routing\Controller;
use Ngos\AdminCore\Services\CrudService;

/**
 * Common base for admin-core controllers.
 *
 * Both the web CRUD controller (CrudController) and the generated API
 * controllers extend this, so the service binding and the store/update
 * FormRequest contract live in one place — and any future cross-cutting concern
 * (shared response helpers, tenant scoping, …) has a single home that flows to
 * the web and the JSON surface alike.
 */
abstract class BaseController extends Controller
{
    /** CRUD service for this resource. */
    protected CrudService $service;

    /** FormRequest classes — resolved (and validated) when the action runs. */
    protected string $storeRequest;
    protected string $updateRequest;
}
