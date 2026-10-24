<?php

namespace App\Modules\GestionCitas\CU08_ConsultarDisponibilidadHorarios;

use App\Http\Controllers\Controller;
use App\Models\Medico;
use App\Modules\GestionCitas\Compartido\CatalogoMedicosService;
use App\Modules\GestionCitas\Compartido\DisponibilidadService;
use Illuminate\Contracts\View\View;

/**
 * CU-08: Consultar disponibilidad de horarios (paquete P4 Gestión de Citas, RF08). Recepcionista y Paciente.
 * Es el servicio que incluyen CU-09, CU-10 y CU-11: el cálculo vive en Compartido/DisponibilidadService y aquí
 * solo se muestra. No modifica ningún dato.
 *
 * Precondición: el médico está activo, tiene horarios de atención y se conoce la fecha.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El usuario elige el médico y la fecha.                              -> index() (formulario de disponibilidad.blade.php, parámetros ?medico= y ?fecha=)
 *  2. El sistema verifica que la fecha no sea pasada, feriado ni día
 *     sin atención.                                                       -> DisponibilidadService::verificarDia()
 *  3. Obtiene los horarios de atención activos del médico para el día
 *     de la semana de esa fecha.                                          -> DisponibilidadService::calcularLibres()
 *  4. Divide cada horario en bloques según su duración (por ejemplo, 30 minutos). -> DisponibilidadService::calcularLibres()
 *  5. Descarta los bloques que ya tienen una cita no cancelada y, si la
 *     fecha es hoy, los que ya pasaron.                                   -> DisponibilidadService::calcularLibres() (citasDelDia)
 *  6. Muestra la lista de bloques libres.                                 -> index() y disponibilidad.blade.php
 *
 * Excepciones:
 *  - La fecha es pasada, feriado o día sin atención: el sistema lo indica y no muestra horarios.
 *  - El médico no atiende ese día: «El médico no atiende en esa fecha».
 *  - No quedan bloques libres: «No hay horarios disponibles».
 *
 * @CU CU-08 @RF RF08 @CI CIDisponibilidad @CC CCDisponibilidad @CE CEMedico (horariosLibres), CECita (citasDelDia)
 */
class DisponibilidadController extends Controller
{
    public function __construct(
        private readonly DisponibilidadService $disponibilidad,
        private readonly CatalogoMedicosService $catalogo,
    ) {}

    /**
     * Pasos 1 a 6: muestra el formulario y, si se eligieron médico y fecha, los horarios libres o el mensaje que corresponda.
     *
     * @CI CIDisponibilidad (elegirMedico, elegirFecha, mostrarHorarios, mostrarMensaje) @CC CCDisponibilidad (calcularLibres, verificarDia)
     */
    public function index(DisponibilidadRequest $request): View
    {
        $medicos = $this->catalogo->medicosConHorario();
        $medico = $request->filled('medico') ? Medico::with('especialidades')->find($request->integer('medico')) : null;
        $fecha = $request->filled('fecha') ? $request->string('fecha')->toString() : null;

        return view('gestioncitas::CU08_ConsultarDisponibilidadHorarios.disponibilidad', [
            'medicos' => $medicos,
            'medico' => $medico,
            'fecha' => $fecha,
            'resultado' => $medico && $fecha ? $this->disponibilidad->calcularLibres($medico, $fecha) : null,
        ]);
    }
}
