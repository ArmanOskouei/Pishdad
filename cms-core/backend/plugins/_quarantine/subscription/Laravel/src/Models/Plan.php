<?php

namespace Pishdad\Plugins\Subscription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * L-B12 — مدلِ افزونه‌ای. جدولش `subscription_plans` است و در core نیست.
 */
final class Plan extends Model
{
    protected $table = 'subscription_plans';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'integer',
            'price_yearly' => 'integer',
            'features' => 'array',
            'active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
