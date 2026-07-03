<?php

namespace Ngos\AdminCore\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's saved dashboard arrangement: the widget order and the keys they've hidden. One row per user per
 * auth guard (so id 5 on a portal guard never collides with id 5 on the web guard).
 *
 * @property int $user_id
 * @property string|null $guard
 * @property array|null $layout
 */
class DashboardLayout extends Model
{
    protected $fillable = ['user_id', 'guard', 'layout'];

    protected $casts = ['layout' => 'array'];
}
