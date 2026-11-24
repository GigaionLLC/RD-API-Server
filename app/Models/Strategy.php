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
     * The stored options minus the keys the client no longer defines. A legacy strategy may still
     * carry one (the editor shows it read-only and keeps it on save), but nothing should push it:
     * no client reads it, so it would only litter the config.
     *
     * @return array<string, string>
     */
    public function pushableOptions(): array
    {
        $removed = array_map(strval(...), array_keys((array) config('strategy_options.removed', [])));

        $options = [];
        foreach ((array) ($this->options ?? []) as $key => $value) {
            $key = (string) $key;
            if (! in_array($key, $removed, true)) {
                $options[$key] = (string) $value;
            }
        }

        return $options;
    }

    /**
     * @return HasMany<StrategyAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(StrategyAssignment::class);
    }
}
