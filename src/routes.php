<?php
/**
 * Created by PhpStorm.
 * User: Lawrence
 * Date: 10/3/16
 * Time: 6:55 PM
 */
Route::post('c2b/payments/receiver', 'ngodasamuel\mpesa\controllers\C2BController@receiver');
Route::get('c2b/register', 'ngodasamuel\mpesa\controllers\C2BController@registerc2b');
