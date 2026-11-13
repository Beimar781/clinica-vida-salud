<?php

use App\Modules\GestionCitas\Compartido\RetornoPagoController;
use App\Modules\GestionCitas\CU08_ConsultarDisponibilidadHorarios\DisponibilidadController;
use App\Modules\GestionCitas\CU09_RegistrarCitaMedica\RegistrarCitaController;
use App\Modules\GestionCitas\CU10_ReprogramarCitaMedica\ReprogramarCitaController;
use App\Modules\GestionCitas\CU11_ReservarCitaEnLinea\ReservarCitaController;
use App\Modules\GestionCitas\CU12_CancelarCitaMedica\CancelarCitaController;
use App\Modules\GestionCitas\CU13_ControlarAsistenciaEstadosCita\EstadoCitaController;
use App\Modules\GestionCitas\CU14_ConsultarAgendaMedica\AgendaController;
use App\Modules\GestionCitas\CU22_EnviarRecordatoriosCitas\EnviarRecordatorioController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Paquete P4 - Gestión de Citas (CU-08 a CU-14 y CU-22).
// Este archivo se carga solo (ver AppServiceProvider::cargarModulos) dentro del grupo «web» (sesión, CSRF).

// CU-08: Consultar disponibilidad de horarios (Recepcionista y Paciente)
Route::middleware(['auth', 'rol:Recepcionista,Paciente'])->get('/disponibilidad', [DisponibilidadController::class, 'index'])->name('disponibilidad.index');

// Citas que gestiona la Recepcionista (CU-09 y CU-10)
Route::middleware(['auth', 'rol:Recepcionista'])->prefix('citas')->name('citas.')->group(function () {

    // CU-09: Registrar cita médica
    Route::get('/registrar', [RegistrarCitaController::class, 'index'])->name('registrar');
    Route::post('/', [RegistrarCitaController::class, 'guardar'])->name('guardar');
    Route::get('/{cita}/comprobante', [RegistrarCitaController::class, 'comprobante'])->whereNumber('cita')->name('comprobante');

    // CU-10: Reprogramar cita médica (el listado «Citas» es también la búsqueda de la cita)
    Route::get('/', [ReprogramarCitaController::class, 'index'])->name('index');
    Route::get('/{cita}/reprogramar', [ReprogramarCitaController::class, 'reprogramar'])->whereNumber('cita')->name('reprogramar');
    Route::put('/{cita}/reprogramar', [ReprogramarCitaController::class, 'actualizar'])->whereNumber('cita')->name('actualizar');
    Route::get('/{cita}/reprogramada', [ReprogramarCitaController::class, 'comprobante'])->whereNumber('cita')->name('reprogramada');

});

// CU-11: Reservar cita en línea (solo el Paciente autenticado)
Route::middleware(['auth', 'rol:Paciente'])->prefix('reservas')->name('reservas.')->group(function () {
    Route::get('/crear', [ReservarCitaController::class, 'buscar'])->name('crear');
    Route::get('/reservar', [ReservarCitaController::class, 'reservar'])->name('reservar');
    Route::post('/', [ReservarCitaController::class, 'guardar'])->name('guardar');
    Route::get('/{cita}/comprobante', [ReservarCitaController::class, 'comprobante'])->whereNumber('cita')->name('comprobante');
});

// CU-12: Cancelar cita médica (Recepcionista, Administrador y el Paciente dueño de la cita; la cita propia y las
// 2 horas de anticipación del paciente las comprueba CancelarCitaService)
Route::middleware(['auth', 'rol:Recepcionista,Paciente'])->prefix('citas')->group(function () {
    Route::get('/{cita}/cancelar', [CancelarCitaController::class, 'mostrar'])->whereNumber('cita')->name('citas.cancelar');
    Route::post('/{cita}/cancelar', [CancelarCitaController::class, 'guardar'])->whereNumber('cita')->name('citas.cancelar.guardar');
});

// CU-13: Controlar asistencia y estados de la cita (Recepcionista, Administrador y Médico; cada acción la valida TransicionesCita)
Route::middleware(['auth', 'rol:Recepcionista,Médico'])->group(function () {
    Route::get('/control-citas', [EstadoCitaController::class, 'index'])->name('control.index');
    Route::post('/citas/{cita}/estado', [EstadoCitaController::class, 'cambiar'])->whereNumber('cita')->name('citas.estado');
});

// CU-22 (extendido): enviar ahora el recordatorio de una cita (Recepcionista y Administrador; 10 por minuto por usuario)
Route::middleware(['auth', 'rol:Recepcionista', 'throttle:10,1'])->post('/citas/{cita}/recordatorio', [EnviarRecordatorioController::class, 'enviar'])->whereNumber('cita')->name('citas.recordatorio');

// CU-14: Consultar agenda médica (Médico: solo su propia agenda; Recepcionista y Administrador: eligen el médico)
Route::middleware(['auth', 'rol:Recepcionista,Médico'])->get('/agenda', [AgendaController::class, 'index'])->name('agenda.index');
// Alias: el menú se llama «Agenda médica», así que /agenda-medica también funciona (mismos permisos; conserva los filtros de la URL).
Route::middleware(['auth', 'rol:Recepcionista,Médico'])->get('/agenda-medica', fn (Request $request) => redirect()->route('agenda.index', $request->query()))->name('agenda.alias');

// Pago con tarjeta (Stripe Checkout alojado) de CU-09 y CU-11: regreso de la página de pago y pago cancelado.
// Solo quien inició la reserva (Recepcionista o Paciente); todo se comprueba contra la sesión del servidor y la API de Stripe.
Route::middleware(['auth', 'rol:Recepcionista,Paciente'])->prefix('pagos/stripe')->name('pagos.stripe.')->group(function () {
    Route::get('/retorno', [RetornoPagoController::class, 'retorno'])->name('retorno');
    Route::get('/cancelado', [RetornoPagoController::class, 'cancelado'])->name('cancelado');
});
