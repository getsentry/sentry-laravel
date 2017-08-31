<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the controller to call when that URI is requested.
|
*/

Route::get('/', function () {
    Log::info('Rendering a page thats about to error');
    throw new Exception('An unhandled exception');
});

Route::get('login', function () {
    return 'This represents a login page we are redirect to if we need to login.';
})->name('login');

Route::group([
    'middleware' => 'auth',
], function () {
    Route::get('protected', function () {
        Log::info('Rendering a page thats about to error and protected with the auth middleware');
        throw new Exception('An unhandled exception');
    })->name('index');
});
