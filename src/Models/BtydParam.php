<?php

declare(strict_types = 1);

namespace Centrex\Btyd\Models;

use Illuminate\Database\Eloquent\Model;

class BtydParam extends Model
{
    protected $table = 'btyd_params';

    protected $fillable = [
        'model',
        'params',
        'fitted_at',
    ];

    protected $casts = [
        'params'    => 'array',
        'fitted_at' => 'datetime',
    ];

    public static function getParams(string $model): ?array
    {
        return static::where('model', $model)->value('params');
    }

    public static function store(string $model, array $params): static
    {
        $record = static::updateOrCreate(
            ['model' => $model],
            ['params' => $params, 'fitted_at' => now()],
        );

        return $record;
    }

    public static function isFitted(): bool
    {
        return static::whereIn('model', ['bgnbd', 'gamma_gamma'])->count() === 2;
    }

    public static function fittedAt(): ?\Illuminate\Support\Carbon
    {
        return static::where('model', 'bgnbd')->value('fitted_at');
    }
}
