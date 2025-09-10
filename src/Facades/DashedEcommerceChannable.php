<?php

namespace Dashed\DashedEcommerceBol\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Dashed\DashedEcommerceBol\DashedEcommerceBol
 */
class DashedEcommerceBol extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'dashed-ecommerce-bol';
    }
}
