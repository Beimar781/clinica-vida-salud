<?php

use App\Modules\GestionMedica\CU04_GestionarEspecialidadesMedicas\EspecialidadController;
use App\Modules\GestionMedica\CU05_GestionarMedicos\MedicosController;
use Illuminate\Support\Facades\Route;

// Paquete P2 - Gestión Médica (CU-04, CU-05 y CU-06). Solo el Administrador.
// Este archivo se carga solo (ver AppServiceProvider::cargarModulos) dentro del grupo «web» (sesión, CSRF).

Route::middleware(['auth', 'rol:Administrador'])->group(function () {

    // CU-04: Gestionar especialidades médicas
    Route::prefix('especialidades')->name('especialidades.')->group(function () {
        Route::get('/', [EspecialidadController::class, 'index'])->name('index');
        Route::get('/crear', [EspecialidadController::class, 'crear'])->name('crear');
        Route::post('/', [EspecialidadController::class, 'guardar'])->name('guardar');
        Route::get('/{especialidad}/editar', [EspecialidadController::class, 'editar'])->name('editar');
        Route::put('/{especialidad}', [EspecialidadController::class, 'actualizar'])->name('actualizar');
        Route::post('/{especialidad}/desactivar', [EspecialidadController::class, 'desactivar'])->name('desactivar');
        Route::post('/{especialidad}/activar', [EspecialidadController::class, 'activar'])->name('activar');
    });

    // CU-05: Gestionar médicos
    Route::prefix('medicos')->name('medicos.')->group(function () {
        Route::get('/', [MedicosController::class, 'index'])->name('index');
        Route::get('/crear', [MedicosController::class, 'crear'])->name('crear');
        Route::post('/', [MedicosController::class, 'guardar'])->name('guardar');
        Route::get('/{medico}/editar', [MedicosController::class, 'editar'])->name('editar');
        Route::put('/{medico}', [MedicosController::class, 'actualizar'])->name('actualizar');
        Route::post('/{medico}/desactivar', [MedicosController::class, 'desactivar'])->name('desactivar');
        Route::post('/{medico}/activar', [MedicosController::class, 'activar'])->name('activar');
    });

});
