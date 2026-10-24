<?php

use App\Modules\GestionCitas\CU08_ConsultarDisponibilidadHorarios\DisponibilidadController;
use App\Modules\GestionCitas\CU09_RegistrarCitaMedica\RegistrarCitaController;
use Illuminate\Support\Facades\Route;

// Paquete P4 - Gestión de Citas (CU-08, CU-09, CU-10 y CU-11).
// Este archivo se carga solo (ver AppServiceProvider::cargarModulos) dentro del grupo «web» (sesión, CSRF).

// CU-08: Consultar disponibilidad de horarios (Recepcionista y Paciente)
Route::middleware(['auth', 'rol:Recepcionista,Paciente'])->get('/disponibilidad', [DisponibilidadController::class, 'index'])->name('disponibilidad.index');

// Citas que gestiona la Recepcionista (CU-09 y CU-10)
Route::middleware(['auth', 'rol:Recepcionista'])->prefix('citas')->name('citas.')->group(function () {

    // CU-09: Registrar cita médica
    Route::get('/registrar', [RegistrarCitaController::class, 'index'])->name('registrar');
    Route::post('/', [RegistrarCitaController::class, 'guardar'])->name('guardar');
    Route::get('/{cita}/comprobante', [RegistrarCitaController::class, 'comprobante'])->whereNumber('cita')->name('comprobante');

});
