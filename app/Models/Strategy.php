<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configuration strategy whose options are pushed to clients.
 */
#[Fillable(['name', 'enabled', 'is_default', 'options', 'extra', 'modified_at', 'note'])]
class Strategy extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'is_default' => 'boolean',
            'options' => 'array',
            'extra' => 'array',
            'modified_at' => 'integer',
        ];
    }

    /**
     * The designated default strategy (enabled), applied as the lowest-priority fallback, or
     * null when none is designated.
     */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->where('enabled', true)->first();
    }

    /**
     * @return HasMany<StrategyAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(StrategyAssignment::class);
    }
}
