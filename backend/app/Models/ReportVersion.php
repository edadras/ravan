<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportVersion extends Model
{
    protected $fillable = ['session_report_id', 'author_id', 'version', 'items', 'summary'];

    protected $casts = ['items' => 'array'];
}
