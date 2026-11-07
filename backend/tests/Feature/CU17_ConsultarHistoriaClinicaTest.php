<?php

namespace Tests\Feature;

use App\Models\AntecedenteExterno;
use App\Models\Cita;
use App\Models\Consulta;
use App\Models\Especialidad;
use App\Models\HistoriaClinica;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\AtencionClinica\CU17_ConsultarHistoriaClinica\HistoriaClinicaService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/** CU-17: Consultar historia clínica. Los métodos paso_N son las filas del plan de pruebas 8.1. */
class CU17_ConsultarHistoriaClinicaTest extends SistemaTestCase
{
    private Usuario $usuarioMedico;

    private Medico $medico;

    private Paciente $paciente;

    private HistoriaClinica $historia;

    private Especialidad $especialidad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuarioMedico = $this->crearUsuario('t-medico-hist', [Rol::MEDICO]);
        $this->especialidad = $this->crearEspecialidad('T-Historia', precio: 100);
        $this->medico = $this->crearMedico(['usuario_id' => $this->usuarioMedico->id, 'nombres' => 'Doctor', 'apellidos' => 'Thistoria'], [$this->especialidad]);
        $this->paciente = $this->crearPaciente(['nombres' => 'Clínica', 'apellidos' => 'Tpaciente', 'telefono' => '74444444', 'correo' => 'clinica@prueba.test', 'sexo' => 'M']);
        $this->historia = HistoriaClinica::crearParaPaciente($this->paciente);
        $this->historia->update([
            'tipo_sangre' => 'A+', 'alergias' => 'Polen y látex', 'enfermedades_cronicas' => 'Asma leve', 'medicamentos' => 'Salbutamol',
            'antecedentes_personales' => 'Apendicectomía en 2015', 'observaciones' => 'Prefiere citas por la mañana',
        ]);
    }

    // ------------------------------------------------------------------ Plan de pruebas 8.1

    /** Paso 1: consultar la historia clínica (médico autenticado y paciente con consultas registradas) → se muestran los datos generales, las consultas por fecha y los antecedentes externos. */
    #[Test]
    public function paso_1_consultar_la_historia_clinica(): void
    {
        $antigua = $this->consulta('2026-02-02', 'Gastritis leve', 'Omeprazol 20 mg en ayunas');
        $reciente = $this->consulta('2026-09-14', 'Hipertensión arterial', 'Losartán 50 mg cada 24 horas');
        $this->antecedente('Hospital Japonés', '2025-08-20', 'Cardiología', 'Arritmia benigna', 'Control anual');

        $pantalla = $this->actingAs($this->usuarioMedico)->get(route('historia.ver', $this->paciente))->assertOk()
            // Datos generales
            ->assertSee('Clínica Tpaciente')->assertSee('A+')->assertSee('Polen y látex')->assertSee('Asma leve')->assertSee('Salbutamol')
            ->assertSee('Apendicectomía en 2015')->assertSee('Prefiere citas por la mañana')->assertSee('Solo lectura')
            // Consultas, con médico, diagnóstico y tratamiento
            ->assertSee('02/02/2026')->assertSee('14/09/2026')->assertSee('Doctor Thistoria')->assertSee('T-Historia')
            ->assertSee('Gastritis leve')->assertSee('Omeprazol 20 mg en ayunas')->assertSee('Hipertensión arterial')->assertSee('Losartán 50 mg cada 24 horas')
            // Antecedentes externos
            ->assertSee('Hospital Japonés')->assertSee('Arritmia benigna');

        // Consultas ordenadas por fecha, la más reciente primero.
        $contenido = $pantalla->getContent();
        $this->assertLessThan(strpos($contenido, 'Gastritis leve'), strpos($contenido, 'Hipertensión arterial'));
        $this->assertSame([$reciente->id, $antigua->id], $pantalla->viewData('datos')['consultas']->pluck('id')->all());
    }

    /** Paso 2: consultar los antecedentes de otro hospital (paciente con antecedentes externos registrados) → el médico visualiza el hospital de origen, el diagnóstico y el tratamiento previos. */
    #[Test]
    public function paso_2_consultar_los_antecedentes_de_otro_hospital(): void
    {
        $this->antecedente('Hospital Obrero N.º 1', '2025-11-12', 'Endocrinología', 'Diabetes mellitus tipo 2', 'Metformina 850 mg cada 12 horas', 'Control cada 3 meses');
        $this->antecedente('Clínica del Sur', null, null, 'Fractura de muñeca', null);

        $this->actingAs($this->usuarioMedico)->get(route('historia.ver', $this->paciente))->assertOk()
            ->assertSee('Antecedentes de otros hospitales (2)')
            ->assertSee('Hospital Obrero N.º 1')->assertSee('12/11/2025')->assertSee('Endocrinología')->assertSee('Diabetes mellitus tipo 2')
            ->assertSee('Metformina 850 mg cada 12 horas')->assertSee('Control cada 3 meses')
            ->assertSee('Clínica del Sur')->assertSee('Sin fecha')->assertSee('Fractura de muñeca');
    }

    /** Paso 3: un usuario sin rol de médico intenta abrir la historia clínica (usuario autenticado con rol Paciente) → el sistema no permite el acceso y la historia clínica no se modifica. */
    #[Test]
    public function paso_3_un_usuario_sin_rol_de_medico_intenta_abrir_la_historia_clinica(): void
    {
        $this->consulta('2026-02-02', 'Gastritis leve', 'Omeprazol');
        $antes = $this->instantanea();

        $paciente = $this->crearUsuario('t-pac-hist-17', [Rol::PACIENTE]);
        $this->actingAs($paciente)->get(route('historia.index'))->assertForbidden();
        $this->get(route('historia.ver', $this->paciente))->assertForbidden()->assertDontSee('Gastritis leve');

        $this->assertSame($antes, $this->instantanea(), 'La historia clínica no se modifica.');
        $this->assertSame(0, DB::table('bitacora')->where('accion', 'HISTORIA_CONSULTADA')->where('usuario_id', $paciente->id)->count());
    }

    // ------------------------------------------------------------------ Sin consultas, roles y solo lectura

    #[Test]
    public function sin_consultas_dice_no_hay_consultas_registradas_y_aun_asi_muestra_los_antecedentes_si_existen(): void
    {
        $this->actingAs($this->usuarioMedico)->get(route('historia.ver', $this->paciente))->assertOk()
            ->assertSee('No hay consultas registradas')->assertSee(HistoriaClinicaService::MENSAJE_SIN_ANTECEDENTES);

        $this->antecedente('Hospital Japonés', '2025-08-20', 'Cardiología', 'Hipertensión arterial grado I', 'Losartán');
        $this->get(route('historia.ver', $this->paciente))->assertOk()
            ->assertSee('No hay consultas registradas')->assertSee('Hospital Japonés')->assertSee('Hipertensión arterial grado I')->assertDontSee(HistoriaClinicaService::MENSAJE_SIN_ANTECEDENTES);
    }

    #[Test]
    public function el_administrador_la_recepcion_y_los_visitantes_no_acceden_a_la_historia_clinica(): void
    {
        $this->consulta('2026-02-02', 'Gastritis leve', 'Omeprazol');
        $this->get(route('historia.index'))->assertRedirect('/login');
        $this->get(route('historia.ver', $this->paciente))->assertRedirect('/login');

        foreach ([Rol::ADMINISTRADOR, Rol::RECEPCIONISTA, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-hist-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('historia.index', ['q' => $this->paciente->ci]))->assertForbidden();
            $this->get(route('historia.ver', $this->paciente))->assertForbidden()->assertDontSee('Gastritis leve');
        }
    }

    #[Test]
    public function la_historia_es_de_solo_lectura_sin_formularios_ni_rutas_para_modificarla(): void
    {
        $this->consulta('2026-02-02', 'Gastritis leve', 'Omeprazol');
        $antes = $this->instantanea();
        $this->actingAs($this->usuarioMedico);

        $pantalla = $this->get(route('historia.ver', $this->paciente))->assertOk();
        $contenido = $pantalla->getContent();
        $this->assertStringNotContainsString('<textarea', $contenido);
        $this->assertStringNotContainsString('name="_method"', $contenido);
        $this->assertStringNotContainsString('name="diagnostico"', $contenido);

        // Ninguna ruta de la historia acepta cambios.
        $this->post(route('historia.ver', $this->paciente), ['alergias' => 'Ninguna'])->assertStatus(405);
        $this->put(route('historia.ver', $this->paciente), ['alergias' => 'Ninguna'])->assertStatus(405);
        $this->delete(route('historia.ver', $this->paciente))->assertStatus(405);
        $this->post(route('historia.index'), ['q' => 'x'])->assertStatus(405);

        $this->assertSame($antes, $this->instantanea());
    }

    #[Test]
    public function cada_consulta_de_una_historia_queda_en_la_bitacora_sin_datos_clinicos(): void
    {
        $this->consulta('2026-02-02', 'Diagnóstico reservado ZZZ', 'Tratamiento reservado ZZZ');
        $this->actingAs($this->usuarioMedico);

        $this->get(route('historia.ver', $this->paciente))->assertOk();
        $this->get(route('historia.ver', $this->paciente))->assertOk();

        $this->assertSame(2, DB::table('bitacora')->where('accion', 'HISTORIA_CONSULTADA')->where('usuario_id', $this->usuarioMedico->id)
            ->where('detalle', "Historia clínica del paciente {$this->paciente->codigo} consultada")->count());
        $this->assertSame(0, DB::table('bitacora')->where('detalle', 'like', '%reservado ZZZ%')->count());
    }

    // ------------------------------------------------------------------ Búsqueda

    #[Test]
    public function el_medico_busca_por_ci_nombre_o_apellido_sin_distinguir_tildes(): void
    {
        $this->actingAs($this->usuarioMedico);

        // Una sola coincidencia: abre directamente la historia.
        $this->get(route('historia.index', ['q' => $this->paciente->ci]))->assertRedirect(route('historia.ver', $this->paciente));
        $this->get(route('historia.index', ['q' => 'TPACIENTE']))->assertRedirect(route('historia.ver', $this->paciente));
        $this->get(route('historia.index', ['q' => 'clinica']))->assertRedirect(route('historia.ver', $this->paciente)); // «Clínica» sin tilde

        // Varias coincidencias: se elige de la lista.
        $otro = $this->crearPaciente(['nombres' => 'Clínico', 'apellidos' => 'Tpaciente Segundo']);
        $this->get(route('historia.index', ['q' => 'tpaciente']))->assertOk()
            ->assertSee('Clínica Tpaciente')->assertSee('Clínico Tpaciente Segundo')->assertSee('Ver historia')
            ->assertSee(route('historia.ver', $this->paciente), false)->assertSee(route('historia.ver', $otro), false);

        $this->get(route('historia.index', ['q' => 'xyz-no-existe']))->assertOk()->assertSee('No se encontraron pacientes');
        $this->get(route('historia.index'))->assertOk()->assertSee('Busque a un paciente por CI o nombre');
    }

    #[Test]
    public function el_medico_consulta_a_cualquier_paciente_aunque_lo_haya_atendido_otro_medico_o_este_inactivo(): void
    {
        $otroUsuario = $this->crearUsuario('t-medico-hist-otro', [Rol::MEDICO]);
        $otro = $this->crearMedico(['usuario_id' => $otroUsuario->id, 'nombres' => 'Otra', 'apellidos' => 'Tdoctora'], [$this->especialidad]);
        $cita = $this->crearCita($otro, $this->paciente, $this->especialidad, '2026-03-02', '09:00', '09:30', Cita::ATENDIDA);
        Consulta::create(['cita_id' => $cita->id, 'historia_clinica_id' => $this->historia->id, 'medico_id' => $otro->id, 'diagnostico' => 'Visto por otra doctora']);
        $this->paciente->update(['estado' => false]);

        $this->actingAs($this->usuarioMedico)->get(route('historia.ver', $this->paciente))->assertOk()
            ->assertSee('Visto por otra doctora')->assertSee('Otra Tdoctora');
        $this->get(route('historia.index', ['q' => 'Tpaciente']))->assertRedirect(route('historia.ver', $this->paciente));
    }

    #[Test]
    public function un_paciente_sin_historia_abierta_se_muestra_vacio_sin_crearla_ni_dar_error(): void
    {
        $sinHistoria = $this->crearPaciente(['nombres' => 'Sin', 'apellidos' => 'Thistoria']);

        $this->actingAs($this->usuarioMedico)->get(route('historia.ver', $sinHistoria))->assertOk()
            ->assertSee('Sin Thistoria')->assertSee('No hay consultas registradas')->assertSee('No registrado');
        $this->assertNull($sinHistoria->fresh()->historiaClinica, 'Consultar no crea ni modifica nada.');
    }

    #[Test]
    public function no_se_muestra_el_telefono_ni_el_correo_del_paciente_y_el_aviso_de_alergias_destaca(): void
    {
        $this->actingAs($this->usuarioMedico)->get(route('historia.ver', $this->paciente))->assertOk()
            ->assertDontSee('74444444')->assertDontSee('clinica@prueba.test')
            ->assertSee('dato-clinico--alerta', false)->assertSee('Polen y látex');
    }

    #[Test]
    public function el_historial_de_otro_paciente_no_se_mezcla_y_los_ids_inexistentes_dan_404(): void
    {
        $otro = $this->crearPaciente(['nombres' => 'Otro', 'apellidos' => 'Tpaciente2']);
        $historiaOtro = HistoriaClinica::crearParaPaciente($otro);
        $cita = $this->crearCita($this->medico, $otro, $this->especialidad, '2026-04-06', '09:00', '09:30', Cita::ATENDIDA);
        Consulta::create(['cita_id' => $cita->id, 'historia_clinica_id' => $historiaOtro->id, 'medico_id' => $this->medico->id, 'diagnostico' => 'Solo del otro paciente']);
        $this->consulta('2026-02-02', 'Solo de este paciente', 'x');

        $this->actingAs($this->usuarioMedico)->get(route('historia.ver', $this->paciente))->assertOk()
            ->assertSee('Solo de este paciente')->assertDontSee('Solo del otro paciente');
        $this->get(route('historia.ver', 999999999))->assertNotFound();
    }

    /** Consulta del médico de la prueba para el paciente de la prueba, en una cita Atendida de esa fecha. */
    private function consulta(string $fecha, string $diagnostico, string $tratamiento): Consulta
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $fecha, '09:00', '09:30', Cita::ATENDIDA);

        return Consulta::create([
            'cita_id' => $cita->id, 'historia_clinica_id' => $this->historia->id, 'medico_id' => $this->medico->id,
            'diagnostico' => $diagnostico, 'tratamiento' => $tratamiento,
        ]);
    }

    private function antecedente(string $hospital, ?string $fecha, ?string $especialidad, string $diagnostico, ?string $tratamiento, ?string $observaciones = null): AntecedenteExterno
    {
        return AntecedenteExterno::create([
            'historia_clinica_id' => $this->historia->id, 'hospital_origen' => $hospital, 'fecha_atencion' => $fecha, 'especialidad' => $especialidad,
            'diagnostico' => $diagnostico, 'tratamiento' => $tratamiento, 'observaciones' => $observaciones,
        ]);
    }

    /** Estado de la historia, sus consultas y sus antecedentes para comprobar que consultar no cambia nada. */
    private function instantanea(): string
    {
        return json_encode([
            HistoriaClinica::find($this->historia->id)->toArray(),
            Consulta::where('historia_clinica_id', $this->historia->id)->orderBy('id')->get()->toArray(),
            AntecedenteExterno::where('historia_clinica_id', $this->historia->id)->orderBy('id')->get()->toArray(),
        ]);
    }
}
