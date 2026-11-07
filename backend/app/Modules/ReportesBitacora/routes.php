<?php

use App\Modules\ReportesBitacora\CU18_GenerarReportesConIA\ReportesController;
use App\Modules\ReportesBitacora\CU19_ConsultarBitacora\BitacoraController;
use Illuminate\Support\Facades\Route;

// Paquete P6 - Reportes y Bitácora (CU-18 y CU-19).
// Este archivo se carga solo (ver AppServiceProvider::cargarModulos) dentro del grupo «web» (sesión, CSRF).

// CU-18: Generar reportes con IA (Administrador y Recepcionista; solo consulta). Se ve el reporte con GET /reportes?tipo=...
// y se exporta con los mismos filtros en GET /reportes/exportar/{csv|pdf}.
Route::middleware(['auth', 'rol:Administrador,Recepcionista'])->prefix('reportes')->name('reportes.')->group(function () {
    Route::get('/', [ReportesController::class, 'index'])->name('index');
    Route::get('/exportar/{formato}', [ReportesController::class, 'exportar'])->where('formato', 'csv|pdf')->name('exportar');
});

// CU-19: Consultar bitácora (solo Administrador; solo lectura: no hay rutas de edición ni de borrado)
Route::middleware(['auth', 'rol:Administrador'])->group(function () {
    Route::get('/bitacora', [BitacoraController::class, 'index'])->name('bitacora.index');
});
