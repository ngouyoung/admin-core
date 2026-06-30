<?php

namespace Ngos\AdminCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's saved list view: a named set of advanced-filter values for one resource (e.g. "Overdue invoices" =
 * {status: overdue, due_to: today}). One row per (user, resource, name) — saving the same name overwrites.
 *
 * @property int $user_id
 * @property string $resource
 * @property string $name
 * @property array|null $filters
 */
class SavedView extends Model
{
    protected $fillable = ['user_id', 'resource', 'name', 'filters'];

    protected $casts = ['filters' => 'array'];
}
