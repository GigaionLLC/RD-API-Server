<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A grouping of devices used for strategy assignment and organisation.
 */
#[Fillable(['name', 'note', 'is_default'])]
class DeviceGroup extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /**
     * The id of the default device group (the one new/ungrouped devices fall into), or null
     * when none is designated.
     */
    public static function defaultId(): ?int
    {
        $id = static::query()->where('is_default', true)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
