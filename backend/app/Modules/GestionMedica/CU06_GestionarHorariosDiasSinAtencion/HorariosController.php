<?php

namespace App\Modules\GestionMedica\CU06_GestionarHorariosDiasSinAtencion;

use App\Http\Controllers\Controller;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CU-06: Gestionar horarios y días sin atención (paquete P2 Gestión Médica, RF06), parte de los horarios
 * de atención. Solo el Administrador. Los días sin atención los gestiona DiasSinAtencionController.
 *
 * Precondición: existe al menos un médico activo (la pantalla lo avisa si no hay).
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El administrador accede a la sección «Horarios de atención».       -> index() (la ruta exige el rol Administrador)
 *  2. Selecciona el médico.                                              -> formulario de horarios.blade.php (parámetro ?medico=)
 *  3. El sistema muestra los horarios actuales del médico.               -> index() con HorarioService::agendaSemanal()
 *  4. Selecciona agregar o editar un bloque e ingresa día, hora
 *     inicial, hora final y duración de la cita.                         -> pantalla horarios.blade.php (alta, o edición con ?editar=)
 *  5. El sistema valida los datos y calcula la cantidad de citas
 *     del bloque.                                                        -> HorarioRequest (validarHoras, haySuperposicion, duración) + HorarioService::cantidadCitas()
 *  6. El sistema guarda el horario y muestra la agenda semanal
 *     actualizada.                                                       -> guardar() / actualizar() y redirección a index()
 *  7. (feriados y días sin atención)                                     -> DiasSinAtencionController
 *
 * Excepciones:
 *  - La hora final no es posterior a la inicial: se rechaza el horario (HorarioRequest).
 *  - El bloque se superpone con otro del mismo médico y día: se rechaza (HorarioRequest y EXCLUDE de la base).
 *  - La duración no divide exactamente el bloque: se advierte cuántos minutos sobran y se pide corregir.
 *
 * @CU CU-06 @RF RF06 @CI CIHorarios @CC CCHorarios @CE CEHorario, CEMedico
 */
class HorariosController extends Controller
{
    public function __construct(private readonly HorarioService $horarios) {}

    /**
     * Pasos 1 a 4: elegir médico, ver su agenda semanal y mostrar el formulario de alta o de edición.
     *
     * @CI CIHorarios (mostrarAgenda, mostrarFormulario) @CC CCHorarios (crear) @CE CEMedico (obtener), CEHorario
     */
    public function index(Request $request): View
    {
        $medicos = $this->horarios->medicosActivos();
        $medico = $medicos->firstWhere('id', (int) $request->query('medico'));
        $editar = $medico
            ? HorarioAtencion::where('medico_id', $medico->id)->find((int) $request->query('editar'))
            : null;

        return view('gestionmedica::CU06_GestionarHorariosDiasSinAtencion.horarios', [
            'medicos' => $medicos,
            'medico' => $medico,
            'agenda' => $medico ? $this->horarios->agendaSemanal($medico) : [],
            'editar' => $editar,
            'dias' => HorarioService::DIAS,
        ]);
    }

    /**
     * Pasos 5 y 6 (alta): valida (HorarioRequest), calcula la cantidad de citas, guarda y vuelve a la agenda.
     *
     * @CI CIHorarios (enviarDatos, mostrarResultado) @CC CCHorarios (guardar, validarHoras) @CE CEHorario (crear, haySuperposicion)
     */
    public function guardar(HorarioRequest $request): RedirectResponse
    {
        $medico = Medico::findOrFail($request->integer('medico_id'));
        $horario = $this->horarios->guardar($medico, $request->validated(), $request->user());

        return redirect()->route('horarios.index', ['medico' => $medico->id])->with('exito', $this->mensajeGuardado($horario, 'registrado'));
    }

    /**
     * Pasos 5 y 6 (edición): valida, actualiza el bloque y vuelve a la agenda.
     *
     * @CI CIHorarios (enviarDatos, mostrarResultado) @CC CCHorarios (actualizar) @CE CEHorario (actualizar)
     */
    public function actualizar(HorarioRequest $request, HorarioAtencion $horario): RedirectResponse
    {
        $this->horarios->actualizar($horario, $request->validated(), $request->user());

        return redirect()->route('horarios.index', ['medico' => $horario->medico_id])->with('exito', $this->mensajeGuardado($horario, 'actualizado'));
    }

    /**
     * Desactiva el bloque: deja de ofrecerse en la disponibilidad.
     *
     * @CI CIHorarios (mostrarResultado) @CC CCHorarios (desactivar) @CE CEHorario (desactivar)
     */
    public function desactivar(Request $request, HorarioAtencion $horario): RedirectResponse
    {
        $this->horarios->desactivar($horario, $request->user());

        return redirect()->route('horarios.index', ['medico' => $horario->medico_id])->with('exito', 'Horario desactivado. Ya no se ofrece para nuevas citas.');
    }

    /**
     * Reactiva un bloque desactivado (si no se superpone con otro activo).
     *
     * @CI CIHorarios (mostrarResultado) @CC CCHorarios (actualizar) @CE CEHorario (haySuperposicion, actualizar)
     */
    public function activar(Request $request, HorarioAtencion $horario): RedirectResponse
    {
        $this->horarios->activar($horario, $request->user());

        return redirect()->route('horarios.index', ['medico' => $horario->medico_id])->with('exito', 'Horario activado.');
    }

    private function mensajeGuardado(HorarioAtencion $h, string $accion): string
    {
        $inicio = HorarioService::hhmm($h->hora_inicio);
        $fin = HorarioService::hhmm($h->hora_fin);
        $citas = $this->horarios->cantidadCitas($inicio, $fin, $h->duracion_min);

        return 'Horario '.$accion.': '.HorarioService::DIAS[$h->dia_semana]." de {$inicio} a {$fin}, citas de {$h->duracion_min} minutos (".$citas.' '.($citas === 1 ? 'cita' : 'citas').' en el bloque).';
    }
}
