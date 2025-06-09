<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PppController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix('ppp')->group(function () {
    // PPP
    Route::prefix('secrets')->group(function () {
        Route::get('show/{router_id}', [PppController::class, 'showSecrets']);
        Route::post('disable', [PppController::class, 'disableSecrets']);
        Route::post('enable', [PppController::class, 'enableSecrets']);
    });
});

