<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsentVersion extends Model
{
    protected $fillable = ['type', 'version', 'locale', 'title', 'body', 'bullet_points', 'is_current'];

    protected $casts = ['bullet_points' => 'array', 'is_current' => 'boolean'];
}
