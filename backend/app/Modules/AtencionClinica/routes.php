<?php

use App\Modules\AtencionClinica\CU16_RegistrarConsultaTratamiento\ConsultaController;
use App\Modules\AtencionClinica\CU17_ConsultarHistoriaClinica\HistoriaClinicaController;
use Illuminate\Support\Facades\Route;

// Paquete P5 - Atención Clínica (CU-16 y CU-17). Solo el rol Médico (el Administrador y el Paciente no acceden).
// Este archivo se carga solo (ver AppServiceProvider::cargarModulos) dentro del grupo «web» (sesión, CSRF).

Route::middleware(['auth', 'rol:Médico'])->group(function () {

    // CU-16: Registrar consulta y tratamiento (desde la agenda médica; la cita propia y el permiso los comprueba ConsultaService)
    Route::prefix('consultas')->name('consultas.')->group(function () {
        Route::get('/{cita}', [ConsultaController::class, 'mostrar'])->whereNumber('cita')->name('mostrar');
        Route::post('/{cita}', [ConsultaController::class, 'guardar'])->whereNumber('cita')->name('guardar');
        Route::put('/{cita}', [ConsultaController::class, 'actualizar'])->whereNumber('cita')->name('actualizar');
    });

    // CU-17: Consultar historia clínica (solo lectura)
    Route::prefix('historia-clinica')->name('historia.')->group(function () {
        Route::get('/', [HistoriaClinicaController::class, 'index'])->name('index');
        Route::get('/{paciente}', [HistoriaClinicaController::class, 'ver'])->whereNumber('paciente')->name('ver');
    });

});
