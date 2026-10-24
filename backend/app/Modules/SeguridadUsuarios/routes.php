<?php

use App\Modules\SeguridadUsuarios\CU01_IniciarSesion\LoginController;
use App\Modules\SeguridadUsuarios\CU01_IniciarSesion\OlvidoClaveController;
use App\Modules\SeguridadUsuarios\CU01_IniciarSesion\PanelController;
use App\Modules\SeguridadUsuarios\CU01_IniciarSesion\RestablecerClaveController;
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

    // CU-01: Iniciar sesión (flujo alternativo: recuperar contraseña)
    // Los dos envíos (pedir el enlace y guardar la clave nueva) comparten el límite de 5 por minuto por equipo;
    // abrir las pantallas (GET) tiene un límite aparte y más amplio para no bloquear a quien solo navega.
    Route::get('/olvido-clave', [OlvidoClaveController::class, 'mostrar'])->middleware('throttle:30,1,clave-pantallas')->name('password.request');
    Route::post('/olvido-clave', [OlvidoClaveController::class, 'enviar'])->middleware('throttle:5,1,clave-envios')->name('password.email');
    Route::get('/restablecer-clave/{token}', [RestablecerClaveController::class, 'mostrar'])->middleware('throttle:30,1,clave-pantallas')->name('password.reset');
    Route::post('/restablecer-clave', [RestablecerClaveController::class, 'guardar'])->middleware('throttle:5,1,clave-envios')->name('password.update');
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
