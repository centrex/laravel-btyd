<?php

namespace Centrex\Btyd\Models;

use Illuminate\Database\Eloquent\Model;

class BtydParam extends Model
{
    protected $table = 'btyd_params';
    protected $fillable = ['model', 'params'];

    protected $casts = [
        'params' => 'array',
    ];

    public static function getParams(string $model): ?array
    {
        return static::where('model', $model)->value('params');
    }
}
