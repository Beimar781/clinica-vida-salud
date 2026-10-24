<?php

use App\Modules\Seguridad\Http\Controllers\AutenticacionController;
use App\Modules\Seguridad\Http\Controllers\UsuarioController;
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

    // CU-02 Cerrar sesión (sin «auth»: si la sesión ya expiró solo se redirige al login)
    Route::post('/logout', [AutenticacionController::class, 'cerrar'])->name('logout');

    // CU-03 Gestionar usuarios y roles (solo Administrador)
    Route::middleware(['auth', 'rol:Administrador'])->prefix('usuarios')->name('usuarios.')->group(function () {
        Route::get('/', [UsuarioController::class, 'index'])->name('index');
        Route::get('/crear', [UsuarioController::class, 'crear'])->name('crear');
        Route::post('/', [UsuarioController::class, 'guardar'])->name('guardar');
        Route::get('/{usuario}/editar', [UsuarioController::class, 'editar'])->name('editar');
        Route::put('/{usuario}', [UsuarioController::class, 'actualizar'])->name('actualizar');
        Route::post('/{usuario}/desactivar', [UsuarioController::class, 'desactivar'])->name('desactivar');
        Route::post('/{usuario}/activar', [UsuarioController::class, 'activar'])->name('activar');
    });
});
