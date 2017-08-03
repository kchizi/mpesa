<?php
namespace Ngodasamuel\Mpesa\Facades;

use Illuminate\Support\Facades\Facade;


class Mpesa extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'Ngodasamuel\Mpesa\MpesaFacadeAccessor';
    }

}