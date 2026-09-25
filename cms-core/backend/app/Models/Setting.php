<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Generic key/value store. PK is (group, key) logically
 * (auto-increment id + unique(group, key) physically for Eloquent).
 *
 * Per-user panel appearance lives here: group = 'ui', key = 'user_{id}'.
 */
class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        $row = static::query()->where('group', $group)->where('key', $key)->first();

        return $row?->value ?? $default;
    }

    public static function set(string $group, string $key, mixed $value): static
    {
        return static::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value]
        );
    }
}
