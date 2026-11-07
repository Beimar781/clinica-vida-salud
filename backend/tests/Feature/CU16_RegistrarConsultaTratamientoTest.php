<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Consulta;
use App\Models\Especialidad;
use App\Models\HistoriaClinica;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\AtencionClinica\CU16_RegistrarConsultaTratamiento\ConsultaService;
use App\Modules\GestionCitas\CU13_ControlarAsistenciaEstadosCita\EstadoCitaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/** CU-16: Registrar consulta y tratamiento. Los métodos paso_N son las filas del plan de pruebas 8.1. */
class CU16_RegistrarConsultaTratamientoTest extends SistemaTestCase
{
    private Usuario $usuarioMedico;

    private Medico $medico;

    private Paciente $paciente;

    private HistoriaClinica $historia;

    private Especialidad $especialidad;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuarioMedico = $this->crearUsuario('t-medico-cons', [Rol::MEDICO]);
        $this->especialidad = $this->crearEspecialidad('T-Consulta', precio: 100);
        $this->medico = $this->crearMedico(['usuario_id' => $this->usuarioMedico->id, 'puede_marcar_atendida' => true], [$this->especialidad]);
        $this->paciente = $this->crearPaciente([
            'nombres' => 'Consulta', 'apellidos' => 'Tpaciente', 'telefono' => '73333333', 'correo' => 'consulta@prueba.test',
            'fecha_nacimiento' => Carbon::today()->subYears(35)->subDays(3)->toDateString(), 'sexo' => 'F',
        ]);
        $this->historia = HistoriaClinica::crearParaPaciente($this->paciente);
        $this->historia->update(['tipo_sangre' => 'O+', 'alergias' => 'Penicilina']);
        $this->lunes = $this->proximaFecha(1);
    }

    // ------------------------------------------------------------------ Plan de pruebas 8.1

    /** Paso 1: registrar una consulta (cita Confirmada del médico, diagnóstico y tratamiento) → la consulta queda guardada en la historia clínica del paciente y la cita pasa a Atendida. */
    #[Test]
    public function paso_1_registrar_una_consulta(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);

        $this->actingAs($this->usuarioMedico)->get(route('consultas.mostrar', $cita))->assertOk()
            ->assertSee('Registrar consulta')->assertSee('Consulta Tpaciente')->assertSee('35 años')->assertSee('Femenino')
            ->assertSee('O+')->assertSee('Penicilina')->assertSee('T-Consulta')->assertSee('Guardar consulta');

        $this->post(route('consultas.guardar', $cita), ['diagnostico' => 'Faringitis aguda', 'tratamiento' => 'Amoxicilina 500 mg cada 8 horas por 7 días', 'observaciones' => 'Reposo y abundante líquido'])
            ->assertRedirect(route('consultas.mostrar', $cita))
            ->assertSessionHas('exito', "Consulta registrada en la historia clínica. La cita {$cita->codigo} pasó a Atendida.");

        $consulta = Consulta::where('cita_id', $cita->id)->firstOrFail();
        $this->assertSame($this->historia->id, $consulta->historia_clinica_id, 'Queda en la historia clínica del paciente.');
        $this->assertSame($this->medico->id, $consulta->medico_id);
        $this->assertSame('Faringitis aguda', $consulta->diagnostico);
        $this->assertSame('Amoxicilina 500 mg cada 8 horas por 7 días', $consulta->tratamiento);
        $this->assertSame('Reposo y abundante líquido', $consulta->observaciones);
        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);
        $this->assertDatabaseHas('bitacora', [
            'accion' => 'CONSULTA_REGISTRADA', 'usuario_id' => $this->usuarioMedico->id,
            'detalle' => "Consulta registrada en la cita {$cita->codigo} (paciente {$this->paciente->codigo}, médico {$this->medico->codigo}); la cita pasó a Atendida",
        ]);

        // Muestra la consulta registrada.
        $this->get(route('consultas.mostrar', $cita))->assertOk()->assertSee('Consulta registrada')->assertSee('Faringitis aguda')->assertSee('Amoxicilina 500 mg');
    }

    /** Paso 2: un médico sin permiso intenta registrar una consulta (médico sin permiso de marcar citas como atendidas) → el sistema no permite registrar la consulta. */
    #[Test]
    public function paso_2_un_medico_sin_permiso_intenta_registrar_una_consulta(): void
    {
        $this->medico->update(['puede_marcar_atendida' => false]);
        $cita = $this->cita(Cita::CONFIRMADA);

        $this->actingAs($this->usuarioMedico)->get(route('consultas.mostrar', $cita))
            ->assertRedirect(route('agenda.index'))->assertSessionHas('error', ConsultaService::MENSAJE_SIN_PERMISO);
        $this->post(route('consultas.guardar', $cita), ['diagnostico' => 'Intento sin permiso'])
            ->assertRedirect(route('agenda.index'))->assertSessionHas('error', ConsultaService::MENSAJE_SIN_PERMISO);

        $this->assertSame(0, Consulta::where('cita_id', $cita->id)->count());
        $this->assertSame(Cita::CONFIRMADA, $cita->fresh()->estado);
    }

    /** Paso 3: registrar consulta sin diagnóstico (diagnóstico vacío) → el sistema lo solicita y no guarda. */
    #[Test]
    public function paso_3_registrar_consulta_sin_diagnostico(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);

        foreach (['', '   ', null] as $vacio) {
            $this->actingAs($this->usuarioMedico)->from(route('consultas.mostrar', $cita))
                ->post(route('consultas.guardar', $cita), ['diagnostico' => $vacio, 'tratamiento' => 'Reposo'])
                ->assertRedirect(route('consultas.mostrar', $cita))
                ->assertSessionHasErrors(['diagnostico' => 'Ingrese el diagnóstico de la consulta.']);
        }

        $this->assertSame(0, Consulta::where('cita_id', $cita->id)->count());
        $this->assertSame(Cita::CONFIRMADA, $cita->fresh()->estado, 'La cita no cambia si no se guarda la consulta.');
    }

    // ------------------------------------------------------------------ Estados de la cita

    #[Test]
    public function una_cita_programada_pasa_por_confirmada_y_queda_atendida(): void
    {
        $cita = $this->cita(Cita::PROGRAMADA);

        $this->actingAs($this->usuarioMedico)->post(route('consultas.guardar', $cita), ['diagnostico' => 'Control sin novedades'])->assertSessionHas('exito');

        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);
        $this->assertSame(1, Consulta::where('cita_id', $cita->id)->count());
    }

    #[Test]
    public function una_cita_ya_atendida_sin_consulta_admite_registrar_la_consulta_y_sigue_atendida(): void
    {
        $cita = $this->cita(Cita::ATENDIDA); // el médico la marcó atendida en el control de citas sin registrar la consulta
        $this->actingAs($this->usuarioMedico);

        $this->get(route('consultas.mostrar', $cita))->assertOk()->assertSee('Guardar consulta');
        $this->post(route('consultas.guardar', $cita), ['diagnostico' => 'Dolor lumbar mecánico'])->assertSessionHas('exito');

        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);
        $this->assertSame('Dolor lumbar mecánico', Consulta::where('cita_id', $cita->id)->firstOrFail()->diagnostico);
    }

    #[Test]
    public function una_cita_cancelada_o_con_inasistencia_no_admite_consulta(): void
    {
        $this->actingAs($this->usuarioMedico);

        foreach ([[Cita::CANCELADA, 'cancelada'], [Cita::INASISTENCIA, 'con inasistencia']] as $i => [$estado, $texto]) {
            $cita = $this->cita($estado, sprintf('%02d:00', 8 + $i));
            $mensaje = "La cita está {$texto}: no se puede registrar la consulta.";

            $this->get(route('consultas.mostrar', $cita))->assertRedirect(route('agenda.index'))->assertSessionHas('error', $mensaje);
            $this->post(route('consultas.guardar', $cita), ['diagnostico' => 'Intento'])->assertSessionHas('error', $mensaje);

            $this->assertSame(0, Consulta::where('cita_id', $cita->id)->count());
            $this->assertSame($estado, $cita->fresh()->estado);
        }
    }

    // ------------------------------------------------------------------ Cita ajena, médico sin ficha y roles

    #[Test]
    public function el_medico_no_registra_consultas_de_citas_de_otro_medico_ni_ve_sus_datos(): void
    {
        $otroUsuario = $this->crearUsuario('t-medico-cons-otro', [Rol::MEDICO]);
        $otro = $this->crearMedico(['usuario_id' => $otroUsuario->id, 'puede_marcar_atendida' => true], [$this->especialidad]);
        $ajena = $this->crearCita($otro, $this->paciente, $this->especialidad, $this->lunes, '10:00', '10:30', Cita::CONFIRMADA);

        $this->actingAs($this->usuarioMedico)->get(route('consultas.mostrar', $ajena))
            ->assertRedirect(route('agenda.index'))->assertSessionHas('error', ConsultaService::MENSAJE_AJENA);
        $this->post(route('consultas.guardar', $ajena), ['diagnostico' => 'Cita ajena'])->assertSessionHas('error', ConsultaService::MENSAJE_AJENA);
        $this->put(route('consultas.actualizar', $ajena), ['diagnostico' => 'Cita ajena'])->assertSessionHas('error', ConsultaService::MENSAJE_AJENA);

        $this->assertSame(0, Consulta::where('cita_id', $ajena->id)->count());
        $this->assertSame(Cita::CONFIRMADA, $ajena->fresh()->estado);
        $this->followingRedirects()->get(route('consultas.mostrar', $ajena))->assertDontSee('Consulta Tpaciente');
    }

    #[Test]
    public function un_medico_sin_ficha_de_medico_recibe_el_aviso_y_no_registra_nada(): void
    {
        $sinFicha = $this->crearUsuario('t-medico-cons-sin-ficha', [Rol::MEDICO]);
        $cita = $this->cita(Cita::CONFIRMADA);

        $this->actingAs($sinFicha)->post(route('consultas.guardar', $cita), ['diagnostico' => 'Sin ficha'])
            ->assertRedirect(route('agenda.index'))->assertSessionHas('error', EstadoCitaService::MENSAJE_SIN_FICHA_MEDICO);
        $this->assertSame(0, Consulta::where('cita_id', $cita->id)->count());
    }

    #[Test]
    public function solo_el_rol_medico_accede_a_las_consultas(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);
        $this->get(route('consultas.mostrar', $cita))->assertRedirect('/login');

        foreach ([Rol::ADMINISTRADOR, Rol::RECEPCIONISTA, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-acceso-cons-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('consultas.mostrar', $cita))->assertForbidden();
            $this->post(route('consultas.guardar', $cita), ['diagnostico' => 'Intruso'])->assertForbidden();
            $this->put(route('consultas.actualizar', $cita), ['diagnostico' => 'Intruso'])->assertForbidden();
        }

        $this->assertSame(0, Consulta::where('cita_id', $cita->id)->count());
        $this->assertSame(Cita::CONFIRMADA, $cita->fresh()->estado);
    }

    // ------------------------------------------------------------------ Consulta existente: mostrar y corregir

    #[Test]
    public function si_la_cita_ya_tiene_consulta_se_muestra_y_se_puede_corregir_sin_cambiar_el_estado(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);
        $this->actingAs($this->usuarioMedico)->post(route('consultas.guardar', $cita), ['diagnostico' => 'Rinitis', 'tratamiento' => 'Loratadina']);

        // Se muestra la existente, lista para corregir.
        $this->get(route('consultas.mostrar', $cita))->assertOk()
            ->assertSee('Esta cita ya tiene su consulta registrada')->assertSee('Rinitis')->assertSee('Loratadina')->assertSee('Guardar la corrección');

        // No se registra una segunda consulta.
        $this->post(route('consultas.guardar', $cita), ['diagnostico' => 'Otra consulta'])
            ->assertRedirect(route('agenda.index'))->assertSessionHas('error', ConsultaService::MENSAJE_YA_TIENE);
        $this->assertSame(1, Consulta::where('cita_id', $cita->id)->count());

        // Se corrige: la cita sigue Atendida y queda en la bitácora.
        $this->put(route('consultas.actualizar', $cita), ['diagnostico' => 'Rinitis alérgica', 'tratamiento' => 'Loratadina 10 mg al día', 'observaciones' => 'Evitar el polvo'])
            ->assertRedirect(route('consultas.mostrar', $cita))->assertSessionHas('exito', 'Consulta corregida. La cita sigue Atendida.');

        $consulta = Consulta::where('cita_id', $cita->id)->firstOrFail();
        $this->assertSame('Rinitis alérgica', $consulta->diagnostico);
        $this->assertSame('Loratadina 10 mg al día', $consulta->tratamiento);
        $this->assertSame('Evitar el polvo', $consulta->observaciones);
        $this->assertSame(Cita::ATENDIDA, $cita->fresh()->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'CONSULTA_CORREGIDA', 'usuario_id' => $this->usuarioMedico->id]);
    }

    #[Test]
    public function corregir_una_consulta_sin_diagnostico_no_cambia_nada_y_corregir_sin_consulta_avisa(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);
        $this->actingAs($this->usuarioMedico);

        $this->put(route('consultas.actualizar', $cita), ['diagnostico' => 'Sin consulta previa'])
            ->assertRedirect(route('agenda.index'))->assertSessionHas('error', ConsultaService::MENSAJE_SIN_CONSULTA);

        $this->post(route('consultas.guardar', $cita), ['diagnostico' => 'Original']);
        $this->from(route('consultas.mostrar', $cita))->put(route('consultas.actualizar', $cita), ['diagnostico' => ''])
            ->assertSessionHasErrors(['diagnostico' => 'Ingrese el diagnóstico de la consulta.']);
        $this->assertSame('Original', Consulta::where('cita_id', $cita->id)->firstOrFail()->diagnostico);
    }

    // ------------------------------------------------------------------ Datos y límites

    #[Test]
    public function los_campos_respetan_los_limites_del_esquema_y_los_opcionales_vacios_se_guardan_como_nulos(): void
    {
        $this->actingAs($this->usuarioMedico);
        $cita = $this->cita(Cita::CONFIRMADA);

        $this->from(route('consultas.mostrar', $cita))->post(route('consultas.guardar', $cita), [
            'diagnostico' => str_repeat('d', 301), 'tratamiento' => str_repeat('t', 401), 'observaciones' => str_repeat('o', 401),
        ])->assertSessionHasErrors([
            'diagnostico' => 'El diagnóstico no puede superar los 300 caracteres.',
            'tratamiento' => 'El tratamiento no puede superar los 400 caracteres.',
            'observaciones' => 'Las observaciones no pueden superar los 400 caracteres.',
        ]);
        $this->assertSame(0, Consulta::where('cita_id', $cita->id)->count());

        // Los máximos exactos se aceptan.
        $this->post(route('consultas.guardar', $cita), ['diagnostico' => str_repeat('d', 300), 'tratamiento' => str_repeat('t', 400), 'observaciones' => str_repeat('o', 400)])->assertSessionHasNoErrors();
        $this->assertSame(300, mb_strlen(Consulta::where('cita_id', $cita->id)->firstOrFail()->diagnostico));

        // Opcionales vacíos: nulos; el diagnóstico se guarda sin espacios sobrantes.
        $otra = $this->cita(Cita::CONFIRMADA, '10:00');
        $this->post(route('consultas.guardar', $otra), ['diagnostico' => '  Control  ', 'tratamiento' => '   ', 'observaciones' => ''])->assertSessionHasNoErrors();
        $consulta = Consulta::where('cita_id', $otra->id)->firstOrFail();
        $this->assertSame('Control', $consulta->diagnostico);
        $this->assertNull($consulta->tratamiento);
        $this->assertNull($consulta->observaciones);
    }

    #[Test]
    public function un_paciente_sin_historia_clinica_abierta_la_recibe_al_registrar_su_primera_consulta(): void
    {
        $sinHistoria = $this->crearPaciente(['nombres' => 'Sin', 'apellidos' => 'Thistoria']);
        $cita = $this->crearCita($this->medico, $sinHistoria, $this->especialidad, $this->lunes, '11:00', '11:30', Cita::CONFIRMADA);
        $this->assertNull($sinHistoria->fresh()->historiaClinica);

        $this->actingAs($this->usuarioMedico)->post(route('consultas.guardar', $cita), ['diagnostico' => 'Primera consulta'])->assertSessionHas('exito');

        $historia = $sinHistoria->fresh()->historiaClinica;
        $this->assertNotNull($historia);
        $this->assertSame($historia->id, Consulta::where('cita_id', $cita->id)->firstOrFail()->historia_clinica_id);
    }

    #[Test]
    public function la_bitacora_no_guarda_el_contenido_clinico_y_la_pantalla_no_muestra_el_contacto_del_paciente(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);
        $this->actingAs($this->usuarioMedico)->post(route('consultas.guardar', $cita), ['diagnostico' => 'Diagnóstico confidencial XYZ', 'tratamiento' => 'Tratamiento confidencial XYZ']);

        $this->assertSame(0, DB::table('bitacora')->where('detalle', 'like', '%confidencial XYZ%')->count());
        $this->get(route('consultas.mostrar', $cita))->assertOk()->assertDontSee('73333333')->assertDontSee('consulta@prueba.test');
    }

    #[Test]
    public function dos_envios_seguidos_dejan_una_sola_consulta(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);
        $this->actingAs($this->usuarioMedico);

        $this->post(route('consultas.guardar', $cita), ['diagnostico' => 'Primero'])->assertSessionHas('exito');
        $this->post(route('consultas.guardar', $cita), ['diagnostico' => 'Segundo'])->assertSessionHas('error', ConsultaService::MENSAJE_YA_TIENE);

        $this->assertSame(1, Consulta::where('cita_id', $cita->id)->count());
        $this->assertSame('Primero', Consulta::where('cita_id', $cita->id)->firstOrFail()->diagnostico);
        $this->assertSame(1, DB::table('bitacora')->where('accion', 'CONSULTA_REGISTRADA')->where('detalle', 'like', "Consulta registrada en la cita {$cita->codigo}%")->count());
    }

    // ------------------------------------------------------------------ Agenda y panel

    #[Test]
    public function la_agenda_ofrece_registrar_o_ver_la_consulta_solo_al_medico_con_permiso(): void
    {
        $pendiente = $this->cita(Cita::CONFIRMADA, '08:00');
        $hecha = $this->cita(Cita::CONFIRMADA, '09:00');
        $cancelada = $this->cita(Cita::CANCELADA, '10:00');
        $this->actingAs($this->usuarioMedico)->post(route('consultas.guardar', $hecha), ['diagnostico' => 'Hecha']);

        $pantalla = $this->get(route('agenda.index', ['desde' => $this->lunes]))->assertOk()
            ->assertSee(route('consultas.mostrar', $pendiente), false)->assertSee('Registrar consulta</a>', false)
            ->assertSee(route('consultas.mostrar', $hecha), false)->assertSee('Ver consulta')
            ->assertDontSee(route('consultas.mostrar', $cancelada), false);
        $this->assertNotNull($pantalla);

        // Sin el permiso no se ofrece ningún botón; la recepción tampoco lo ve.
        $this->medico->update(['puede_marcar_atendida' => false]);
        $this->get(route('agenda.index', ['desde' => $this->lunes]))->assertOk()->assertDontSee('Registrar consulta</a>', false)->assertDontSee(route('consultas.mostrar', $pendiente), false);

        $recepcion = $this->crearUsuario('t-recep-cons', [Rol::RECEPCIONISTA]);
        $this->actingAs($recepcion)->get(route('agenda.index', ['desde' => $this->lunes, 'medico' => $this->medico->id]))->assertOk()
            ->assertDontSee('Registrar consulta</a>', false)->assertDontSee('Ver consulta');
    }

    #[Test]
    public function el_panel_ofrece_la_atencion_clinica_solo_al_medico(): void
    {
        $this->actingAs($this->usuarioMedico)->get(route('panel'))->assertOk()
            ->assertSee('Atención clínica')->assertSee('Registrar consulta (desde la agenda)')->assertSee('CU-16')->assertSee('Historia clínica')->assertSee('CU-17');

        foreach ([Rol::ADMINISTRADOR, Rol::RECEPCIONISTA, Rol::PACIENTE] as $rol) {
            $this->actingAs($this->crearUsuario('t-panel-cons-'.strtolower(substr($rol, 0, 3)), [$rol]))->get(route('panel'))->assertOk()
                ->assertDontSee('Atención clínica')->assertDontSee('Historia clínica');
        }
    }

    #[Test]
    public function la_atencion_clinica_se_sirve_sin_depender_de_internet(): void
    {
        $cita = $this->cita(Cita::CONFIRMADA);

        $pantalla = $this->actingAs($this->usuarioMedico)->get(route('consultas.mostrar', $cita))->assertOk();
        $pantalla->assertSee('/assets/css/modulos/atencion-clinica.css', false)->assertSee('/assets/js/modulos/atencion-clinica.js', false);
        $this->get('/assets/css/modulos/atencion-clinica.css')->assertOk();
        $this->get('/assets/js/modulos/atencion-clinica.js')->assertOk();
    }

    /** Cita del médico y el paciente de la prueba (por defecto a las 09:00 del lunes). */
    private function cita(string $estado = Cita::CONFIRMADA, string $inicio = '09:00'): Cita
    {
        $fin = Carbon::parse($inicio)->addMinutes(30)->format('H:i');

        return $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, $inicio, $fin, $estado);
    }
}
