<?php

namespace App\Modules\GestionMedica\CU06_GestionarHorariosDiasSinAtencion;

use App\Http\Controllers\Controller;
use App\Models\BloqueoAgenda;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CU-06: Gestionar horarios y días sin atención (paquete P2 Gestión Médica, RF06), parte de los feriados
 * y días sin atención de la clínica. Solo el Administrador. Los horarios los gestiona HorariosController.
 *
 * Flujo de suceso (ficha 4.3 del documento), paso 7:
 *  7. Si corresponde, el administrador registra un feriado o día sin atención con su fecha y su motivo;
 *     el sistema lo guarda, muestra el listado y lista las citas ya programadas que quedan afectadas
 *     para que se reprogramen.
 *       -> index() muestra el listado y el formulario; guardar() lo registra; la redirección vuelve a
 *          index(?afectadas=) que lista las citas afectadas con DiaSinAtencionService::citasAfectadas().
 *
 * Excepciones:
 *  - La fecha ya está registrada o es anterior a hoy: se rechaza (DiaSinAtencionRequest).
 *  - Desactivar un día sin atención: la fecha vuelve a estar disponible en la agenda -> desactivar() / activar().
 *
 * @CU CU-06 @RF RF06 @CI CIHorarios @CC CCHorarios @CE CEHorario, CEMedico
 */
class DiasSinAtencionController extends Controller
{
    public function __construct(private readonly DiaSinAtencionService $dias) {}

    /**
     * Listado de días sin atención, formulario de registro y, si se pide (?afectadas=ID), las citas afectadas de ese día.
     *
     * @CI CIHorarios (mostrarAgenda, mostrarResultado) @CE CEHorario
     */
    public function index(Request $request): View
    {
        [$dias, $afectadasPorFecha] = $this->dias->listar();
        $seleccionado = BloqueoAgenda::find((int) $request->query('afectadas'));

        return view('gestionmedica::CU06_GestionarHorariosDiasSinAtencion.dias-sin-atencion', [
            'dias' => $dias,
            'afectadasPorFecha' => $afectadasPorFecha,
            'seleccionado' => $seleccionado,
            'citasAfectadas' => $seleccionado ? $this->dias->citasAfectadas($seleccionado->fecha) : collect(),
        ]);
    }

    /**
     * Paso 7: valida (DiaSinAtencionRequest), guarda el día y muestra el listado con las citas afectadas.
     *
     * @CI CIHorarios (enviarDatos, mostrarResultado) @CC CCHorarios (guardar) @CE CEHorario (crear)
     */
    public function guardar(DiaSinAtencionRequest $request): RedirectResponse
    {
        $dia = $this->dias->guardar($request->validated(), $request->user());
        $afectadas = $this->dias->citasAfectadas($dia->fecha)->count();

        $mensaje = 'Día sin atención registrado: '.$dia->fecha->format('d/m/Y').'.'
            .($afectadas > 0 ? " Hay {$afectadas} ".($afectadas === 1 ? 'cita programada afectada' : 'citas programadas afectadas').'; revíselas abajo para reprogramarlas.' : ' No hay citas programadas ese día.');

        return redirect()->route('dias-sin-atencion.index', ['afectadas' => $dia->id])->with('exito', $mensaje);
    }

    /**
     * Excepción de desactivar: la fecha vuelve a estar disponible en la agenda.
     *
     * @CI CIHorarios (mostrarResultado) @CC CCHorarios (desactivar) @CE CEHorario (desactivar)
     */
    public function desactivar(Request $request, BloqueoAgenda $dia): RedirectResponse
    {
        $this->dias->desactivar($dia, $request->user());

        return back()->with('exito', 'Día sin atención del '.$dia->fecha->format('d/m/Y').' desactivado: la fecha vuelve a estar disponible en la agenda.');
    }

    /**
     * Vuelve a bloquear una fecha desactivada.
     *
     * @CI CIHorarios (mostrarResultado) @CC CCHorarios (actualizar) @CE CEHorario (actualizar)
     */
    public function activar(Request $request, BloqueoAgenda $dia): RedirectResponse
    {
        $this->dias->activar($dia, $request->user());

        return back()->with('exito', 'Día sin atención del '.$dia->fecha->format('d/m/Y').' activado.');
    }
}
