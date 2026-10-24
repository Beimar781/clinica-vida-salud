<?php

use Illuminate\Support\Facades\Route;

// Página de inicio pública (no es un caso de uso). Las rutas de cada paquete están en app/Modules/*/routes.php.
Route::view('/', 'inicio')->name('inicio');
