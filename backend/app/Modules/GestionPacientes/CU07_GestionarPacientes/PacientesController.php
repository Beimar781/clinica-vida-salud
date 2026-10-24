<?php

namespace App\Modules\GestionPacientes\CU07_GestionarPacientes;

use App\Http\Controllers\Controller;
use App\Models\Paciente;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CU-07: Gestionar pacientes (paquete P3 Gestión de Pacientes, RF07). Recepcionista y Administrador.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. La recepcionista accede a la sección «Pacientes».                 -> index() (la ruta exige el rol Recepcionista o Administrador)
 *  2. El sistema muestra el listado con búsqueda por CI, nombre o
 *     apellido.                                                         -> index() con PacienteService::listar()
 *  3. Selecciona registrar un nuevo paciente o editar uno existente.    -> crear() / editar()
 *  4. Ingresa CI, nombres, apellidos, fecha de nacimiento, sexo,
 *     teléfono, correo, dirección y contacto de emergencia.             -> pantallas crear.blade.php, editar.blade.php y campos.blade.php
 *  5. El sistema valida los datos, verifica que el CI no esté
 *     registrado y genera el código del paciente.                       -> PacienteRequest (usa PacienteService::existeCI()) + PacienteService::guardar() (código PAC-0001 con la secuencia pacientes_codigo_seq)
 *  6. El sistema guarda el registro, crea la historia clínica del
 *     paciente y muestra el listado actualizado.                        -> guardar() / actualizar() (misma transacción) y redirección a index()
 *
 * Excepciones:
 *  - El CI ya está registrado: se rechaza el registro y se muestra al paciente existente (crear() y editar()
 *    lo buscan con PacienteService::buscarPorCI()).
 *  - Faltan datos obligatorios o tienen formato inválido: se indica y no se guarda (PacienteRequest).
 *  - El paciente proviene de otro hospital: se registran hospital de origen, fecha, especialidad, diagnóstico y
 *    tratamiento, y se guardan como antecedentes externos de su historia clínica (casilla «viene de otro hospital»).
 *  - Si se desactiva un paciente, se conserva todo su historial de citas y su historia clínica -> desactivar() / activar().
 *
 * @CU CU-07 @RF RF07 @CI CIPacientes @CC CCPacientes @CE CEPaciente, CEHistoriaClinica, CEAntecedente
 */
class PacientesController extends Controller
{
    public function __construct(private readonly PacienteService $pacientes) {}

    /**
     * Pasos 1 y 2: listado con búsqueda por CI, nombre o apellido.
     *
     * @CI CIPacientes (mostrarListado) @CC CCPacientes (buscar) @CE CEPaciente (buscar)
     */
    public function index(Request $request): View
    {
        return view('gestionpacientes::CU07_GestionarPacientes.index', [
            'pacientes' => $this->pacientes->listar($request->query('q')),
            'buscar' => (string) $request->query('q'),
        ]);
    }

    /**
     * Pasos 3 y 4 (alta): formulario vacío. Si el envío anterior se rechazó por CI repetido, muestra al paciente existente.
     *
     * @CI CIPacientes (mostrarFormulario) @CC CCPacientes (crear) @CE CEPaciente (existeCI, buscar)
     */
    public function crear(Request $request): View
    {
        return view('gestionpacientes::CU07_GestionarPacientes.crear', ['existente' => $this->pacienteConElMismoCI($request)]);
    }

    /**
     * Pasos 5 y 6 (alta): valida (PacienteRequest), genera el código, guarda con su historia clínica y vuelve al listado.
     *
     * @CI CIPacientes (enviarDatos, mostrarResultado) @CC CCPacientes (guardar) @CE CEPaciente (crear), CEHistoriaClinica (crearParaPaciente), CEAntecedente (crear)
     */
    public function guardar(PacienteRequest $request): RedirectResponse
    {
        $paciente = $this->pacientes->guardar($request->validated(), $request->user());

        return redirect()->route('pacientes.index')
            ->with('exito', "Paciente {$paciente->nombre_completo} registrado con el código {$paciente->codigo}; su historia clínica quedó creada.");
    }

    /**
     * Pasos 3 y 4 (edición): formulario con los datos y los antecedentes de otros hospitales.
     *
     * @CI CIPacientes (mostrarFormulario) @CC CCPacientes (editar) @CE CEPaciente, CEAntecedente
     */
    public function editar(Request $request, Paciente $paciente): View
    {
        return view('gestionpacientes::CU07_GestionarPacientes.editar', [
            'paciente' => $paciente->load('historiaClinica'),
            'antecedentes' => $this->pacientes->antecedentes($paciente),
            'existente' => $this->pacienteConElMismoCI($request, $paciente->id),
        ]);
    }

    /**
     * Pasos 5 y 6 (edición): valida, actualiza y vuelve al listado.
     *
     * @CI CIPacientes (enviarDatos, mostrarResultado) @CC CCPacientes (actualizar) @CE CEPaciente (actualizar), CEAntecedente (crear)
     */
    public function actualizar(PacienteRequest $request, Paciente $paciente): RedirectResponse
    {
        $this->pacientes->actualizar($paciente, $request->validated(), $request->user());

        return redirect()->route('pacientes.index')->with('exito', "Paciente {$paciente->nombre_completo} actualizado correctamente.");
    }

    /**
     * Excepción de desactivar: se conservan el historial de citas y la historia clínica.
     *
     * @CI CIPacientes (mostrarResultado) @CC CCPacientes @CE CEPaciente (actualizar)
     */
    public function desactivar(Request $request, Paciente $paciente): RedirectResponse
    {
        $this->pacientes->desactivar($paciente, $request->user());

        return back()->with('exito', "Paciente {$paciente->nombre_completo} desactivado. Se conservan su historial de citas y su historia clínica.");
    }

    /**
     * Vuelve a habilitar a un paciente desactivado.
     *
     * @CI CIPacientes (mostrarResultado) @CC CCPacientes @CE CEPaciente (actualizar)
     */
    public function activar(Request $request, Paciente $paciente): RedirectResponse
    {
        $this->pacientes->activar($paciente, $request->user());

        return back()->with('exito', "Paciente {$paciente->nombre_completo} activado.");
    }

    /** Si el formulario volvió con el error de CI repetido, devuelve al otro paciente que tiene ese CI. */
    private function pacienteConElMismoCI(Request $request, ?int $ignorarId = null): ?Paciente
    {
        // Solo hay CI en el input anterior después de un envío rechazado; si ese CI es de otro paciente, ese fue el motivo.
        $ci = $request->old('ci');

        if (! is_string($ci) || $ci === '') {
            return null;
        }

        $existente = $this->pacientes->buscarPorCI($ci);

        return $existente && $existente->id !== $ignorarId ? $existente : null;
    }
}
