<?php

namespace Atannex\Foundation\Facades;

use Atannex\Foundation\FoundationManager;
use Illuminate\Support\Facades\Facade;

/**
 * @see \Atannex\Foundation\Foundation
 */
class Foundation extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FoundationManager::class;
    }
}
