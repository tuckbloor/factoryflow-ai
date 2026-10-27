<?php

use App\Http\Controllers\FactoryController;
use Illuminate\Support\Facades\Route;

Route::get('/factory', [FactoryController::class, 'index'])->name('factory.index');
Route::get('/factory/api/status', [FactoryController::class, 'status'])->name('factory.status');
Route::post('/factory/api/action', [FactoryController::class, 'action'])->name('factory.action');
