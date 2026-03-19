<?php

namespace Atannex\Foundation\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Atannex\Foundation\Foundation
 */
class Foundation extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Atannex\Foundation\FoundationManager::class;
    }
}
