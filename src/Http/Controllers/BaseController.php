<?php

namespace Ngos\AdminCore\Http\Controllers;

use Illuminate\Routing\Controller;
use Ngos\AdminCore\Services\BaseService;

/**
 * Common base for admin-core controllers.
 *
 * Both the web controller (WebController) and the API controller (ApiController)
 * extend this, so the service binding and the store/update FormRequest contract
 * live in one place — and any future cross-cutting concern (shared response
 * helpers, tenant scoping, …) has a single home that flows to the web and the
 * JSON surface alike.
 */
abstract class BaseController extends Controller
{
    /** The resource's service. */
    protected BaseService $service;

    /**
     * FormRequest classes — resolved (and validated) when a write action runs. Null on a read-only resource
     * (which registers no write routes), so they default to null instead of staying uninitialized.
     *
     * @var class-string|null
     */
    protected ?string $storeRequest = null;

    /** @var class-string|null */
    protected ?string $updateRequest = null;
}
