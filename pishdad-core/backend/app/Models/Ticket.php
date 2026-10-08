<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    public const OPEN = 'open';

    public const PENDING = 'pending';

    public const CLOSED = 'closed';

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = [
        'user_id', 'subject', 'status', 'priority', 'labels',
        'contact_name', 'contact_email', 'source', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'labels' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    public function isOpen(): bool
    {
        return $this->status !== self::CLOSED;
    }
}
