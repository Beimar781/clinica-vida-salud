<?php

use App\Modules\Seguridad\Http\Controllers\AutenticacionController;
use Illuminate\Support\Facades\Route;

/*
 * Rutas del paquete P1 · Seguridad y Usuarios.
 * Cada caso de uso agrega aquí las suyas, dentro del grupo «web» (sesión, CSRF).
 */
Route::middleware('web')->group(function () {
    // CU-01 Iniciar sesión
    Route::middleware('guest')->group(function () {
        Route::get('/login', [AutenticacionController::class, 'mostrarFormulario'])->name('login');
        Route::post('/login', [AutenticacionController::class, 'autenticar'])->name('login.autenticar');
    });
    Route::get('/panel', [AutenticacionController::class, 'mostrarPanel'])->middleware('auth')->name('panel');

});
