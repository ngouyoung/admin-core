<?php

namespace Ngos\AdminCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's saved dashboard arrangement: the widget order and the keys they've hidden. One row per user.
 *
 * @property int $user_id
 * @property array|null $layout
 */
class DashboardLayout extends Model
{
    protected $fillable = ['user_id', 'layout'];

    protected $casts = ['layout' => 'array'];
}
