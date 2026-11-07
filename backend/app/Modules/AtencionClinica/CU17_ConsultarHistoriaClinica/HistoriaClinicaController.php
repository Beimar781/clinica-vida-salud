<?php

namespace App\Modules\AtencionClinica\CU17_ConsultarHistoriaClinica;

use App\Http\Controllers\Controller;
use App\Models\Paciente;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * CU-17: Consultar historia clínica (paquete P5 Atención Clínica, RF17). Solo el rol Médico (ni el Administrador ni el Paciente
 * acceden) y en modo de solo lectura: ninguna ruta de este caso de uso modifica datos.
 *
 * Precondición: el usuario autenticado es Médico; existe el paciente.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El médico accede a «Historia clínica».                              -> index() (GET /historia-clinica; la ruta exige el rol Médico)
 *  2. El médico busca al paciente por CI o nombre.                        -> index() con HistoriaClinicaService::buscar()
 *  3. El sistema consulta la historia clínica del paciente, sus consultas
 *     y sus antecedentes externos.                                         -> ver() (GET /historia-clinica/{paciente}) con HistoriaClinicaService::consultar()
 *  4. El sistema muestra los datos generales (tipo de sangre, alergias,
 *     enfermedades crónicas, medicamentos y antecedentes), las consultas
 *     ordenadas por fecha con el médico, el diagnóstico y el tratamiento,
 *     y los antecedentes de otros hospitales.                              -> ver.blade.php
 *
 * Excepciones:
 *  - El paciente no tiene consultas: «No hay consultas registradas» y, si existen, los antecedentes de otros hospitales.
 *  - El usuario no tiene el rol de Médico: acceso denegado (403) y nada se modifica (middleware «rol»).
 *
 * @CU CU-17 @RF RF17 @CI CIHistoriaClinica @CC CCHistoriaClinica @CE CEHistoriaClinica, CEConsulta, CEAntecedente
 */
class HistoriaClinicaController extends Controller
{
    public function __construct(private readonly HistoriaClinicaService $historias) {}

    /**
     * Pasos 1 y 2: busca al paciente por CI o nombre. Con una sola coincidencia abre directamente su historia.
     *
     * @CI CIHistoriaClinica (buscarPaciente) @CC CCHistoriaClinica (buscar) @CE CEPaciente (buscar)
     */
    public function index(Request $request): View|RedirectResponse
    {
        $buscar = (string) $request->query('q');
        $encontrados = $this->historias->buscar($buscar);

        if ($encontrados->count() === 1) {
            return redirect()->route('historia.ver', $encontrados->first());
        }

        return view('atencionclinica::CU17_ConsultarHistoriaClinica.ver', [
            'buscar' => $buscar, 'encontrados' => $encontrados, 'paciente' => null, 'datos' => null,
        ]);
    }

    /**
     * Pasos 3 y 4: muestra la historia clínica (solo lectura) y la deja registrada en la bitácora.
     *
     * @CI CIHistoriaClinica (mostrarHistoria) @CC CCHistoriaClinica (consultar) @CE CEHistoriaClinica (obtenerPorPaciente), CEConsulta (consultasDeHistoria), CEAntecedente (antecedentesDeHistoria)
     */
    public function ver(Request $request, Paciente $paciente): View
    {
        return view('atencionclinica::CU17_ConsultarHistoriaClinica.ver', [
            'buscar' => '', 'encontrados' => collect(), 'paciente' => $paciente,
            'datos' => $this->historias->consultar($paciente, $request->user()),
        ]);
    }
}
