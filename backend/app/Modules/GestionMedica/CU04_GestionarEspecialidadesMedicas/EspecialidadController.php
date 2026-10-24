<?php

namespace App\Modules\GestionMedica\CU04_GestionarEspecialidadesMedicas;

use App\Http\Controllers\Controller;
use App\Models\Especialidad;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CU-04: Gestionar especialidades médicas (paquete P2 Gestión Médica, RF04). Solo el Administrador.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El administrador accede a la sección «Especialidades».          -> index() (la ruta exige el rol Administrador)
 *  2. El sistema muestra el listado de especialidades con su estado.   -> index() con EspecialidadService::listar()
 *  3. Selecciona registrar una nueva o editar una existente.           -> crear() / editar()
 *  4. Ingresa el nombre y, opcionalmente, la descripción.              -> pantallas crear.blade.php, editar.blade.php y campos.blade.php
 *  5. El sistema valida los datos y verifica que el nombre
 *     normalizado no exista.                                           -> EspecialidadRequest (usa EspecialidadService::existeNombre())
 *  6. El sistema guarda la especialidad y muestra el listado
 *     actualizado.                                                     -> guardar() / actualizar() y redirección a index()
 *
 * Excepciones:
 *  - Ya existe una especialidad con el mismo nombre normalizado (minúsculas, sin tildes ni espacios
 *    sobrantes): se rechaza el registro (EspecialidadRequest).
 *  - Desactivar una especialidad: deja de ofrecerse para nuevas citas, pero las citas históricas se
 *    conservan; nada se elimina -> desactivar() / activar().
 *
 * @CU CU-04 @RF RF04 @CI CIEspecialidad @CC CCEspecialidad @CE CEEspecialidad
 */
class EspecialidadController extends Controller
{
    public function __construct(private readonly EspecialidadService $especialidades) {}

    /**
     * Pasos 1 y 2: listado con su estado (y búsqueda por nombre).
     *
     * @CI CIEspecialidad (mostrarListado) @CE CEEspecialidad
     */
    public function index(Request $request): View
    {
        return view('gestionmedica::CU04_GestionarEspecialidadesMedicas.index', [
            'especialidades' => $this->especialidades->listar($request->query('q')),
            'buscar' => (string) $request->query('q'),
        ]);
    }

    /**
     * Pasos 3 y 4 (alta): formulario vacío.
     *
     * @CI CIEspecialidad (mostrarFormulario) @CC CCEspecialidad (crear)
     */
    public function crear(): View
    {
        return view('gestionmedica::CU04_GestionarEspecialidadesMedicas.crear');
    }

    /**
     * Pasos 5 y 6 (alta): valida (EspecialidadRequest), guarda y vuelve al listado.
     *
     * @CI CIEspecialidad (enviarDatos, mostrarResultado) @CC CCEspecialidad (guardar) @CE CEEspecialidad (crear)
     */
    public function guardar(EspecialidadRequest $request): RedirectResponse
    {
        $especialidad = $this->especialidades->guardar($request->validated(), $request->user());

        return redirect()->route('especialidades.index')->with('exito', "Especialidad {$especialidad->nombre} registrada correctamente.");
    }

    /**
     * Pasos 3 y 4 (edición): formulario con los datos actuales.
     *
     * @CI CIEspecialidad (mostrarFormulario) @CC CCEspecialidad (editar) @CE CEEspecialidad
     */
    public function editar(Especialidad $especialidad): View
    {
        return view('gestionmedica::CU04_GestionarEspecialidadesMedicas.editar', ['especialidad' => $especialidad]);
    }

    /**
     * Pasos 5 y 6 (edición): valida, actualiza y vuelve al listado.
     *
     * @CI CIEspecialidad (enviarDatos, mostrarResultado) @CC CCEspecialidad (actualizar) @CE CEEspecialidad (actualizar)
     */
    public function actualizar(EspecialidadRequest $request, Especialidad $especialidad): RedirectResponse
    {
        $this->especialidades->actualizar($especialidad, $request->validated(), $request->user());

        return redirect()->route('especialidades.index')->with('exito', "Especialidad {$especialidad->nombre} actualizada correctamente.");
    }

    /**
     * Excepción de desactivar: se conserva el registro y las citas históricas.
     *
     * @CI CIEspecialidad (mostrarResultado) @CC CCEspecialidad (desactivar) @CE CEEspecialidad (desactivar)
     */
    public function desactivar(Request $request, Especialidad $especialidad): RedirectResponse
    {
        $this->especialidades->desactivar($especialidad, $request->user());

        return back()->with('exito', "Especialidad {$especialidad->nombre} desactivada. Deja de ofrecerse para nuevas citas; las citas históricas se conservan.");
    }

    /**
     * Vuelve a ofrecer una especialidad desactivada.
     *
     * @CI CIEspecialidad (mostrarResultado) @CC CCEspecialidad (actualizar) @CE CEEspecialidad (actualizar)
     */
    public function activar(Request $request, Especialidad $especialidad): RedirectResponse
    {
        $this->especialidades->activar($especialidad, $request->user());

        return back()->with('exito', "Especialidad {$especialidad->nombre} activada.");
    }
}
