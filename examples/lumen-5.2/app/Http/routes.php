<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It is a breeze. Simply tell Lumen the URIs it should respond to
| and give it the Closure to call when that URI is requested.
|
*/

function verifyCredentials()
{
    Log::info('Verifying credentials');
    $user = DB::table('migrations')->where('migration', 'a migration')->first();
    throw new Exception('No credentials passed!');
}


function authenticateUser()
{
    Log::info('Authenticating the current user');
    verifyCredentials();
}


$app->get('/', function () use ($app) {
    Log::info('Rendering a page thats about to error');
    authenticateUser();
});
