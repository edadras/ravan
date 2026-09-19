<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = ['appointment_id', 'payer_id', 'amount', 'currency', 'gateway', 'gateway_ref', 'status', 'gateway_payload', 'paid_at'];

    protected $casts = ['gateway_payload' => 'array', 'paid_at' => 'datetime'];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
