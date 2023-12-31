<?php

use App\Services\Pterodactyl\Http\Controllers\PterodactylController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::prefix('pterodactyl')->group(function() {
    Route::get('/{order}/login-to-panel', 'PterodactylController@loginPanel')->name('pterodactyl.login');
});