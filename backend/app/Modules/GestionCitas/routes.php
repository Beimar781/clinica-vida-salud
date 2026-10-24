<?php

use App\Modules\GestionCitas\CU08_ConsultarDisponibilidadHorarios\DisponibilidadController;
use Illuminate\Support\Facades\Route;

// Paquete P4 - Gestión de Citas (CU-08, CU-09, CU-10 y CU-11).
// Este archivo se carga solo (ver AppServiceProvider::cargarModulos) dentro del grupo «web» (sesión, CSRF).

// CU-08: Consultar disponibilidad de horarios (Recepcionista y Paciente)
Route::middleware(['auth', 'rol:Recepcionista,Paciente'])->get('/disponibilidad', [DisponibilidadController::class, 'index'])->name('disponibilidad.index');
