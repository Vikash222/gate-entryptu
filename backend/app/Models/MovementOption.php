<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MovementOption extends Model
{
    use HasFactory;

    public const TYPE_DESTINATION = 'DESTINATION';
    public const TYPE_PURPOSE = 'PURPOSE';

    protected $fillable = [
        'type',
        'name',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    public function scopeDestinations(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_DESTINATION);
    }

    public function scopePurposes(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PURPOSE);
    }
}
