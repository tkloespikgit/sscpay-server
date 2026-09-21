<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PaymentPageController;
use Illuminate\Support\Facades\Route;


Route::get('/payment/{token}', [PaymentPageController::class, 'show'])->name('payment.show');
Route::post('/payment/{token}/confirm', [PaymentPageController::class, 'confirm'])->name('payment.confirm');
Route::get('/payment/expired', fn () => view('payment.expired'))->name('payment.expired');


Route::get('/', [HomeController::class, 'show'])->name('home.show');
