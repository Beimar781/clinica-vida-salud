<?php

use App\Modules\ReportesBitacora\CU19_ConsultarBitacora\BitacoraController;
use Illuminate\Support\Facades\Route;

// Paquete P6 - Reportes y Bitácora (por ahora solo CU-19; los reportes llegan con el Ciclo II).
// Este archivo se carga solo (ver AppServiceProvider::cargarModulos) dentro del grupo «web» (sesión, CSRF).

// CU-19: Consultar bitácora (solo Administrador; solo lectura: no hay rutas de edición ni de borrado)
Route::middleware(['auth', 'rol:Administrador'])->group(function () {
    Route::get('/bitacora', [BitacoraController::class, 'index'])->name('bitacora.index');
});
