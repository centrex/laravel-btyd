<?php

declare(strict_types = 1);

namespace Centrex\Btyd\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Centrex\Btyd\Btyd
 */
class Btyd extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Centrex\Btyd\Btyd::class;
    }
}
