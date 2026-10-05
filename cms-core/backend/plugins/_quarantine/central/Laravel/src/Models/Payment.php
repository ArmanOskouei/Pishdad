<?php

namespace Pishdad\Plugins\Central\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $table = 'central_payments';

    protected $fillable = [
        'invoice_id', 'gateway', 'ref', 'amount', 'status', 'raw',
    ];

    protected function casts(): array
    {
        return ['raw' => 'array'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
