<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// FactoryFlow AI routes
require __DIR__.'/factory.php';

