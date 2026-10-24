<?php

use App\Modules\SeguridadUsuarios\CU01_IniciarSesion\LoginController;
use App\Modules\SeguridadUsuarios\CU01_IniciarSesion\PanelController;
use App\Modules\SeguridadUsuarios\CU02_CerrarSesion\LogoutController;
use App\Modules\SeguridadUsuarios\CU03_GestionarUsuariosRoles\UsuariosController;
use Illuminate\Support\Facades\Route;

// Paquete P1 - Seguridad y Usuarios (CU-01, CU-02 y CU-03).
// Este archivo se carga solo (ver AppServiceProvider::cargarModulos) dentro del grupo «web» (sesión, CSRF).

// Pantallas para quien NO ha iniciado sesión
Route::middleware('guest')->group(function () {
    // CU-01: Iniciar sesión
    Route::get('/login', [LoginController::class, 'mostrarFormulario'])->name('login');
    Route::post('/login', [LoginController::class, 'autenticar'])->name('login.autenticar');
});

// CU-01: Iniciar sesión (destino después de ingresar: panel principal por paquetes)
Route::get('/panel', PanelController::class)->middleware('auth')->name('panel');

// CU-02: Cerrar sesión (sin «auth»: si la sesión ya expiró solo se redirige al login)
Route::post('/logout', LogoutController::class)->name('logout');

// CU-03: Gestionar usuarios y roles (solo Administrador)
Route::middleware(['auth', 'rol:Administrador'])->prefix('usuarios')->name('usuarios.')->group(function () {
    Route::get('/', [UsuariosController::class, 'index'])->name('index');
    Route::get('/crear', [UsuariosController::class, 'crear'])->name('crear');
    Route::post('/', [UsuariosController::class, 'guardar'])->name('guardar');
    Route::get('/{usuario}/editar', [UsuariosController::class, 'editar'])->name('editar');
    Route::put('/{usuario}', [UsuariosController::class, 'actualizar'])->name('actualizar');
    Route::post('/{usuario}/desactivar', [UsuariosController::class, 'desactivar'])->name('desactivar');
    Route::post('/{usuario}/activar', [UsuariosController::class, 'activar'])->name('activar');
});
