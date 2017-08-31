<?php

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

function verifyCredentials($username, $password)
{
    Log::info('Verifying credentials');
    $user = DB::table('users')->where('name', $username)->first();
    // in the real world, we'd have some logic here
    throw new Exception('Invalid password!');
}


function authenticateUser()
{
    Log::info('Authenticating the current user');
    verifyCredentials('jane_doe99', 'fizzbuzz');
}


Route::get('/', function () {
    Log::info('Rendering a page thats about to error');
    authenticateUser();
})->name('index');

Route::get('login', function () {
    return 'This represents a login page we are redirect to if we need to login.';
})->name('login');

Route::group([
    'middleware' => 'auth',
], function () {
    Route::get('protected', function () {
        Log::info('Rendering a page thats about to error and protected with the auth middleware');
        authenticateUser();
    })->name('index');
});

Route::get('/welcome/{id}', 'HomeController@showWelcome')->name('welcome');
