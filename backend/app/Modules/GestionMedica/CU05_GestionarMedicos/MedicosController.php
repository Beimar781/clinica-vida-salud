<?php

namespace App\Modules\GestionMedica\CU05_GestionarMedicos;

use App\Http\Controllers\Controller;
use App\Models\Medico;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CU-05: Gestionar médicos (paquete P2 Gestión Médica, RF05). Solo el Administrador.
 *
 * Precondición: existe al menos una especialidad activa (crear() lo comprueba).
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El administrador accede a la sección «Médicos».                      -> index() (la ruta exige el rol Administrador)
 *  2. El sistema muestra el listado con búsqueda por nombre o
 *     especialidad.                                                        -> index() con MedicoService::listar()
 *  3. Selecciona registrar o editar un médico.                             -> crear() / editar()
 *  4. Ingresa nombres, apellidos, número de registro profesional,
 *     teléfono, correo y ubicación (consultorio o sede), selecciona una
 *     o más especialidades e indica si puede marcar citas como atendidas. -> pantallas crear.blade.php, editar.blade.php y campos.blade.php
 *  5. El sistema valida los datos, genera el código del médico y, si se
 *     indica, crea su cuenta de usuario con rol Médico.                    -> MedicoRequest + MedicoService::guardar() (código MED-0001 con la secuencia medicos_codigo_seq)
 *  6. El sistema guarda el registro y muestra el listado actualizado.      -> guardar() / actualizar() y redirección a index()
 *
 * Excepciones:
 *  - El número de registro profesional ya existe: se rechaza el registro (MedicoRequest).
 *  - Si el médico se desactiva, no recibe nuevas citas, pero sus citas históricas se conservan;
 *    nada se elimina -> desactivar() / activar().
 *
 * @CU CU-05 @RF RF05 @CI CIMedicos @CC CCMedicos @CE CEMedico, CEEspecialidad
 */
class MedicosController extends Controller
{
    public function __construct(private readonly MedicoService $medicos) {}

    /**
     * Pasos 1 y 2: listado con búsqueda por nombre, código, registro o especialidad.
     *
     * @CI CIMedicos (mostrarListado) @CE CEMedico (obtener)
     */
    public function index(Request $request): View
    {
        return view('gestionmedica::CU05_GestionarMedicos.index', [
            'medicos' => $this->medicos->listar($request->query('q')),
            'buscar' => (string) $request->query('q'),
        ]);
    }

    /**
     * Pasos 3 y 4 (alta): formulario vacío con las especialidades activas. Precondición: debe haber alguna.
     *
     * @CI CIMedicos (mostrarFormulario) @CC CCMedicos (crear) @CE CEEspecialidad (listarActivas)
     */
    public function crear(): View|RedirectResponse
    {
        $especialidades = $this->medicos->especialidadesActivas();

        if ($especialidades->isEmpty()) {
            return redirect()->route('medicos.index')
                ->with('error', 'Primero registre al menos una especialidad activa (sección Especialidades).');
        }

        return view('gestionmedica::CU05_GestionarMedicos.crear', [
            'especialidades' => $especialidades,
            'especialidadesDelMedico' => [],
        ]);
    }

    /**
     * Pasos 5 y 6 (alta): valida (MedicoRequest), genera el código, guarda y vuelve al listado.
     *
     * @CI CIMedicos (enviarDatos, mostrarResultado) @CC CCMedicos (guardar) @CE CEMedico (crear)
     */
    public function guardar(MedicoRequest $request): RedirectResponse
    {
        $medico = $this->medicos->guardar($request->validated(), $request->user());

        return redirect()->route('medicos.index')
            ->with('exito', "Médico {$medico->nombre_completo} registrado con el código {$medico->codigo}.");
    }

    /**
     * Pasos 3 y 4 (edición): formulario con los datos y las especialidades actuales.
     *
     * @CI CIMedicos (mostrarFormulario) @CC CCMedicos (editar) @CE CEMedico (obtener), CEEspecialidad (listarActivas)
     */
    public function editar(Medico $medico): View
    {
        return view('gestionmedica::CU05_GestionarMedicos.editar', [
            'medico' => $medico->load('usuario'),
            'especialidades' => $this->medicos->especialidadesParaEditar($medico),
            'especialidadesDelMedico' => $medico->especialidades()->pluck('especialidades.id')->all(),
        ]);
    }

    /**
     * Pasos 5 y 6 (edición): valida, actualiza datos y especialidades y vuelve al listado.
     *
     * @CI CIMedicos (enviarDatos, mostrarResultado) @CC CCMedicos (actualizar) @CE CEMedico (actualizar)
     */
    public function actualizar(MedicoRequest $request, Medico $medico): RedirectResponse
    {
        $this->medicos->actualizar($medico, $request->validated(), $request->user());

        return redirect()->route('medicos.index')->with('exito', "Médico {$medico->nombre_completo} actualizado correctamente.");
    }

    /**
     * Excepción de desactivar: no recibe nuevas citas y su historial se conserva.
     *
     * @CI CIMedicos (mostrarResultado) @CC CCMedicos (desactivar) @CE CEMedico (desactivar)
     */
    public function desactivar(Request $request, Medico $medico): RedirectResponse
    {
        $this->medicos->desactivar($medico, $request->user());

        return back()->with('exito', "Médico {$medico->nombre_completo} desactivado. No recibirá nuevas citas; su historial se conserva.");
    }

    /**
     * Vuelve a habilitar a un médico desactivado.
     *
     * @CI CIMedicos (mostrarResultado) @CC CCMedicos (actualizar) @CE CEMedico (actualizar)
     */
    public function activar(Request $request, Medico $medico): RedirectResponse
    {
        $this->medicos->activar($medico, $request->user());

        return back()->with('exito', "Médico {$medico->nombre_completo} activado.");
    }
}
