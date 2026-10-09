<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/control-center', function () {
    return view('control-center');
})->name('control-center');
