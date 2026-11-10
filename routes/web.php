<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The API answers unauthenticated requests with JSON; this named route keeps Laravel's
// auth redirect from failing with "Route [login] not defined" (it was a server-only fix before V2).
Route::get('/login', function () {
    return response()->json(['message' => 'Unauthenticated.'], 401);
})->name('login');
