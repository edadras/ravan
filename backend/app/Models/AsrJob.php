<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AsrJob extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(fn (self $j) => $j->uuid ??= (string) Str::uuid());
    }
}
