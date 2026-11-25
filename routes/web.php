<?php

use App\Http\Controllers\SafePayController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SafePayController::class, 'home'])->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [SafePayController::class, 'login'])->name('login');
    Route::post('/login', [SafePayController::class, 'authenticate']);
    Route::get('/register', [SafePayController::class, 'register'])->name('register');
    Route::post('/register', [SafePayController::class, 'storeUser']);
});

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [SafePayController::class, 'dashboard'])->name('dashboard');
    Route::get('/live/state', [SafePayController::class, 'liveState'])->name('live.state');
    Route::post('/logout', [SafePayController::class, 'logout'])->name('logout');
    Route::post('/wallet', [SafePayController::class, 'bindWallet'])->name('wallet.bind');
    Route::post('/wallet/faucet', [SafePayController::class, 'faucetWallet'])->name('wallet.faucet');
    Route::post('/orders', [SafePayController::class, 'storeOrder'])->name('orders.store');
    Route::post('/orders/{order}/delete', [SafePayController::class, 'destroy'])->name('orders.destroy');
    Route::post('/orders/{order}/tx', [SafePayController::class, 'recordTransaction'])->name('orders.tx');
    Route::post('/orders/{order}/manual', [SafePayController::class, 'manualAction'])->name('orders.manual');
    Route::get('/explorer', [SafePayController::class, 'explorer'])->name('explorer');
    Route::get('/explorer/{tx}', [SafePayController::class, 'verify'])->name('explorer.verify');
});
