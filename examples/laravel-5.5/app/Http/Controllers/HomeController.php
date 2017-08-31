<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('home');
    }

    public function authenticateUser()
    {
        \Log::info('Authenticating the current user');
        $this->verifyCredentials();
    }

    public function showWelcome($id)
    {
        \Log::info('Rendering a page thats about to error');
        // $this->authenticateUser();
        return view('hello');
    }
}
