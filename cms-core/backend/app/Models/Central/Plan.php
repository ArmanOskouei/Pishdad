<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $table = 'central_plans';

    protected $fillable = [
        'name', 'slug', 'tier', 'price_monthly', 'price_yearly',
        'currency', 'features', 'active',
    ];

    protected function casts(): array
    {
        return ['features' => 'array', 'active' => 'boolean'];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
