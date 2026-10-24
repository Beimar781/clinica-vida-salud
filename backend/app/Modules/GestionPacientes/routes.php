<?php

use App\Modules\GestionPacientes\CU07_GestionarPacientes\PacientesController;
use Illuminate\Support\Facades\Route;

// Paquete P3 - Gestión de Pacientes (CU-07).
// Este archivo se carga solo (ver AppServiceProvider::cargarModulos) dentro del grupo «web» (sesión, CSRF).

// CU-07: Gestionar pacientes (Recepcionista y Administrador)
Route::middleware(['auth', 'rol:Recepcionista,Administrador'])->prefix('pacientes')->name('pacientes.')->group(function () {
    Route::get('/', [PacientesController::class, 'index'])->name('index');
    Route::get('/crear', [PacientesController::class, 'crear'])->name('crear');
    Route::post('/', [PacientesController::class, 'guardar'])->name('guardar');
    Route::get('/{paciente}/editar', [PacientesController::class, 'editar'])->name('editar');
    Route::put('/{paciente}', [PacientesController::class, 'actualizar'])->name('actualizar');
    Route::post('/{paciente}/desactivar', [PacientesController::class, 'desactivar'])->name('desactivar');
    Route::post('/{paciente}/activar', [PacientesController::class, 'activar'])->name('activar');
});
