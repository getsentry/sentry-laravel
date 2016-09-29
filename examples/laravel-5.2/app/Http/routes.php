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

function verifyCredentials()
{
    Log::info('Verifying credentials');
    $user = DB::table('users')->where('name', 'John')->first();
    throw new Exception('No credentials passed!');
}


function authenticateUser()
{
    Log::info('Authenticating the current user');
    verifyCredentials();
}


Route::get('/', function () {
    Log::info('Rendering a page thats about to error');
    authenticateUser();
})->name('index');

Route::get('/welcome/{id}', 'HomeController@showWelcome')->name('welcome');
