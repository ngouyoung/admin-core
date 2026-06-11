<?php

namespace Ngos\AdminCore\Services;

/**
 * @deprecated Merged into {@see BaseService} (the one service base both channels
 *             use — it's not "CRUD only"). Kept as a back-compat alias so existing
 *             `extends CrudService` services keep working. Will be removed in a
 *             future major version — extend BaseService instead.
 */
abstract class CrudService extends BaseService
{
}
