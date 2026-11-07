<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\DisponibilidadService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/** CU-06: Gestionar horarios y días sin atención. Los métodos paso_N son las filas del plan de pruebas 8.1. */
class CU06_GestionarHorariosDiasSinAtencionTest extends SistemaTestCase
{
    private Usuario $admin;

    private Especialidad $especialidad;

    private Medico $medico;

    private string $fechaLejana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->crearUsuario('t-admin-hor', [Rol::ADMINISTRADOR]);
        $this->especialidad = $this->crearEspecialidad('T-Horarios');
        $this->medico = $this->crearMedico([], [$this->especialidad]);
        // Una fecha lejana: no choca con los feriados de demostración ni con citas de otras pruebas.
        $this->fechaLejana = Carbon::today()->addYears(3)->setMonth(3)->setDay(15)->toDateString();
    }

    /** Paso 1: registrar horario válido (lunes 08:00 a 12:00, 30 min) → el horario se guarda y se calculan 8 citas. */
    #[Test]
    public function paso_1_registrar_horario_valido(): void
    {
        $this->actingAs($this->admin)->post(route('horarios.guardar'), $this->datos())
            ->assertRedirect(route('horarios.index', ['medico' => $this->medico->id]))
            ->assertSessionHas('exito', 'Horario registrado: Lunes de 08:00 a 12:00, citas de 30 minutos (8 citas en el bloque).');

        $horario = HorarioAtencion::where('medico_id', $this->medico->id)->firstOrFail();
        $this->assertSame(1, $horario->dia_semana);
        $this->assertSame('08:00', $horario->hora_inicio_corta);
        $this->assertSame('12:00', $horario->hora_fin_corta);
        $this->assertSame(8, $horario->cantidad_citas);
        $this->assertTrue($horario->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'HORARIO_CREADO', 'usuario_id' => $this->admin->id]);

        $this->get(route('horarios.index', ['medico' => $this->medico->id]))
            ->assertOk()->assertSee('08:00 – 12:00')->assertSee('8 citas');
    }

    /** Paso 2: registrar horario inválido (hora final menor o igual a la inicial) → el sistema rechaza el horario. */
    #[Test]
    public function paso_2_registrar_horario_invalido(): void
    {
        foreach (['08:00', '07:00'] as $fin) {
            $this->actingAs($this->admin)->from(route('horarios.index'))
                ->post(route('horarios.guardar'), $this->datos(['hora_inicio' => '08:00', 'hora_fin' => $fin]))
                ->assertSessionHasErrors(['hora_fin' => 'La hora final debe ser posterior a la hora inicial.']);
        }

        $this->assertSame(0, HorarioAtencion::where('medico_id', $this->medico->id)->count());
    }

    /** Paso 3: registrar horario superpuesto (lunes 11:00 a 14:00 para el mismo médico) → el sistema rechaza el bloque superpuesto. */
    #[Test]
    public function paso_3_registrar_horario_superpuesto(): void
    {
        $this->crearHorario($this->medico, 1, '08:00', '12:00');

        $this->actingAs($this->admin)
            ->post(route('horarios.guardar'), $this->datos(['hora_inicio' => '11:00', 'hora_fin' => '14:00']))
            ->assertSessionHasErrors(['hora_inicio' => 'El bloque se superpone con otro horario del médico el lunes de 08:00 a 12:00.']);

        $this->assertSame(1, HorarioAtencion::where('medico_id', $this->medico->id)->count());
    }

    /** Paso 4: registrar un día sin atención (fecha futura y motivo) → la fecha queda registrada. */
    #[Test]
    public function paso_4_registrar_un_dia_sin_atencion(): void
    {
        $this->actingAs($this->admin)
            ->post(route('dias-sin-atencion.guardar'), ['fecha' => $this->fechaLejana, 'motivo' => 'T-Feriado de prueba'])
            ->assertSessionHas('exito');

        $dia = BloqueoAgenda::whereDate('fecha', $this->fechaLejana)->firstOrFail();
        $this->assertSame('T-Feriado de prueba', $dia->motivo);
        $this->assertTrue($dia->estado);
        $this->assertSame($this->admin->id, $dia->registrado_por, 'v30: queda guardado quién registró el día sin atención.');
        $this->assertDatabaseHas('bitacora', ['accion' => 'DIA_SIN_ATENCION_CREADO', 'usuario_id' => $this->admin->id]);

        $this->get(route('dias-sin-atencion.index'))->assertOk()->assertSee('Registrado por')->assertSee('t-admin-hor');
        $this->get(route('dias-sin-atencion.index', ['afectadas' => $dia->id]))->assertOk()->assertSee('T-Feriado de prueba');
    }

    /** Paso 5: registrar un día sin atención repetido (fecha ya registrada) → el sistema rechaza el duplicado. */
    #[Test]
    public function paso_5_registrar_un_dia_sin_atencion_repetido(): void
    {
        $this->crearBloqueo(['fecha' => $this->fechaLejana, 'motivo' => 'T-Primero', 'estado' => true]);

        $this->actingAs($this->admin)
            ->post(route('dias-sin-atencion.guardar'), ['fecha' => $this->fechaLejana, 'motivo' => 'T-Segundo'])
            ->assertSessionHasErrors(['fecha' => 'La fecha ya está registrada como día sin atención.']);

        $this->assertSame(1, BloqueoAgenda::whereDate('fecha', $this->fechaLejana)->count());
    }

    #[Test]
    public function la_duracion_debe_dividir_exactamente_el_bloque_y_avisa_los_minutos_que_sobran(): void
    {
        // 08:00 a 12:00 son 240 minutos; con 50 minutos sobran 40.
        $this->actingAs($this->admin)->post(route('horarios.guardar'), $this->datos(['duracion_min' => 50]))
            ->assertSessionHasErrors(['duracion_min' => 'La duración no divide exactamente el bloque: sobran 40 minutos. Corrija la duración o las horas.']);

        $this->assertSame(0, HorarioAtencion::where('medico_id', $this->medico->id)->count());
    }

    #[Test]
    public function dos_bloques_contiguos_o_de_otro_dia_u_otro_medico_no_se_superponen(): void
    {
        $this->crearHorario($this->medico, 1, '08:00', '12:00');
        $otro = $this->crearMedico([], [$this->especialidad]);

        $this->actingAs($this->admin)->post(route('horarios.guardar'), $this->datos(['hora_inicio' => '12:00', 'hora_fin' => '14:00']))->assertSessionHasNoErrors();
        $this->post(route('horarios.guardar'), $this->datos(['dia_semana' => 2]))->assertSessionHasNoErrors();
        $this->post(route('horarios.guardar'), $this->datos(['medico_id' => $otro->id]))->assertSessionHasNoErrors();

        $this->assertSame(3, HorarioAtencion::where('medico_id', $this->medico->id)->count());
        $this->assertSame(1, HorarioAtencion::where('medico_id', $otro->id)->count());
    }

    #[Test]
    public function un_bloque_desactivado_no_cuenta_para_la_superposicion_pero_no_se_puede_reactivar_si_choca(): void
    {
        $viejo = $this->crearHorario($this->medico, 1, '08:00', '12:00', 30, activo: false);

        $this->actingAs($this->admin)->post(route('horarios.guardar'), $this->datos(['hora_inicio' => '10:00', 'hora_fin' => '14:00']))
            ->assertSessionHasNoErrors();

        $this->post(route('horarios.activar', $viejo))
            ->assertSessionHasErrors(['horario' => 'El bloque se superpone con otro horario del médico el lunes de 10:00 a 14:00.']);
        $this->assertFalse($viejo->fresh()->estado);
    }

    #[Test]
    public function editar_un_bloque_no_choca_consigo_mismo_y_actualiza_la_agenda(): void
    {
        $bloque = $this->crearHorario($this->medico, 1, '08:00', '12:00');

        $this->actingAs($this->admin)->put(route('horarios.actualizar', $bloque), $this->datos(['hora_inicio' => '09:00', 'hora_fin' => '12:00', 'duracion_min' => 20]))
            ->assertRedirect(route('horarios.index', ['medico' => $this->medico->id]))
            ->assertSessionHas('exito');

        $bloque->refresh();
        $this->assertSame('09:00', $bloque->hora_inicio_corta);
        $this->assertSame(9, $bloque->cantidad_citas);
        $this->assertDatabaseHas('bitacora', ['accion' => 'HORARIO_EDITADO', 'usuario_id' => $this->admin->id]);

        $this->get(route('horarios.index', ['medico' => $this->medico->id, 'editar' => $bloque->id]))
            ->assertOk()->assertSee('Editar bloque de atención');
    }

    #[Test]
    public function desactivar_un_bloque_lo_conserva_pero_deja_de_estar_activo(): void
    {
        $bloque = $this->crearHorario($this->medico, 3, '14:00', '18:00');

        $this->actingAs($this->admin)->post(route('horarios.desactivar', $bloque))->assertSessionHas('exito');

        $this->assertFalse($bloque->fresh()->estado);
        $this->assertDatabaseHas('horarios_atencion', ['id' => $bloque->id]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'HORARIO_DESACTIVADO', 'usuario_id' => $this->admin->id]);
    }

    #[Test]
    public function los_campos_de_horario_se_validan(): void
    {
        $this->actingAs($this->admin)->post(route('horarios.guardar'), ['medico_id' => $this->medico->id, 'dia_semana' => 9, 'hora_inicio' => '', 'hora_fin' => 'xx', 'duracion_min' => 2])
            ->assertSessionHasErrors([
                'dia_semana' => 'El día de la semana no es válido.',
                'hora_inicio' => 'Ingrese la hora inicial.',
                'hora_fin' => 'La hora final no es válida (use HH:MM).',
                'duracion_min' => 'La duración de la cita debe estar entre 5 y 240 minutos.',
            ]);
    }

    #[Test]
    public function no_se_registran_horarios_para_un_medico_inactivo(): void
    {
        $inactivo = $this->crearMedico(['estado' => false], [$this->especialidad]);

        $this->actingAs($this->admin)->post(route('horarios.guardar'), $this->datos(['medico_id' => $inactivo->id]))
            ->assertSessionHasErrors('medico_id');
        $this->assertSame(0, HorarioAtencion::where('medico_id', $inactivo->id)->count());

        $this->get(route('horarios.index', ['medico' => $inactivo->id]))->assertOk()->assertDontSee('Agenda semanal');
    }

    #[Test]
    public function la_fecha_de_un_dia_sin_atencion_no_puede_ser_anterior_a_hoy(): void
    {
        $this->actingAs($this->admin)->post(route('dias-sin-atencion.guardar'), ['fecha' => Carbon::yesterday()->toDateString(), 'motivo' => 'T-Ayer'])
            ->assertSessionHasErrors(['fecha' => 'La fecha no puede ser anterior a hoy.']);

        // Hoy sí se permite (la fecha pasada es la anterior a hoy).
        $this->post(route('dias-sin-atencion.guardar'), ['fecha' => Carbon::today()->toDateString(), 'motivo' => 'T-Hoy'])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function el_motivo_es_obligatorio(): void
    {
        $this->actingAs($this->admin)->post(route('dias-sin-atencion.guardar'), ['fecha' => $this->fechaLejana, 'motivo' => ''])
            ->assertSessionHasErrors(['motivo' => 'Ingrese el motivo (por ejemplo, el nombre del feriado).']);
    }

    #[Test]
    public function al_registrar_un_dia_sin_atencion_se_listan_las_citas_ya_programadas_afectadas(): void
    {
        $paciente = $this->crearPaciente(['nombres' => 'Afectada', 'apellidos' => 'Tcita']);
        $programada = $this->crearCita($this->medico, $paciente, $this->especialidad, $this->fechaLejana, '09:00', '09:30');
        $cancelada = $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->fechaLejana, '10:00', '10:30', Cita::CANCELADA);
        $atendida = $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->fechaLejana, '11:00', '11:30', Cita::ATENDIDA);

        $respuesta = $this->actingAs($this->admin)
            ->post(route('dias-sin-atencion.guardar'), ['fecha' => $this->fechaLejana, 'motivo' => 'T-Con citas']);

        $dia = BloqueoAgenda::whereDate('fecha', $this->fechaLejana)->firstOrFail();
        $respuesta->assertRedirect(route('dias-sin-atencion.index', ['afectadas' => $dia->id]))
            ->assertSessionHas('exito', fn ($mensaje) => str_contains($mensaje, '1 cita programada afectada'));

        $this->get(route('dias-sin-atencion.index', ['afectadas' => $dia->id]))
            ->assertOk()
            ->assertSee($programada->codigo)->assertSee('Afectada Tcita')
            ->assertDontSee($cancelada->codigo)->assertDontSee($atendida->codigo);
    }

    #[Test]
    public function desactivar_un_dia_sin_atencion_vuelve_a_dejar_disponible_la_fecha(): void
    {
        $dia = $this->crearBloqueo(['fecha' => $this->fechaLejana, 'motivo' => 'T-Temporal', 'estado' => true]);

        $this->actingAs($this->admin)->post(route('dias-sin-atencion.desactivar', $dia))
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'la fecha vuelve a estar disponible'));

        $this->assertFalse($dia->fresh()->estado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'DIA_SIN_ATENCION_DESACTIVADO', 'usuario_id' => $this->admin->id]);
        $this->assertFalse(BloqueoAgenda::where('estado', true)->whereDate('fecha', $this->fechaLejana)->exists());

        $this->post(route('dias-sin-atencion.activar', $dia))->assertSessionHas('exito');
        $this->assertTrue($dia->fresh()->estado);
    }

    #[Test]
    public function solo_el_administrador_gestiona_horarios_y_dias_sin_atencion(): void
    {
        $this->get(route('horarios.index'))->assertRedirect('/login');
        $this->get(route('dias-sin-atencion.index'))->assertRedirect('/login');

        foreach ([Rol::RECEPCIONISTA, Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-hor-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('horarios.index'))->assertForbidden();
            $this->actingAs($usuario)->post(route('horarios.guardar'), $this->datos())->assertForbidden();
            $this->actingAs($usuario)->get(route('dias-sin-atencion.index'))->assertForbidden();
            $this->actingAs($usuario)->post(route('dias-sin-atencion.guardar'), ['fecha' => $this->fechaLejana, 'motivo' => 'T-Intruso'])->assertForbidden();
        }

        $this->assertSame(0, HorarioAtencion::where('medico_id', $this->medico->id)->count());
        $this->assertSame(0, BloqueoAgenda::whereDate('fecha', $this->fechaLejana)->count());
    }

    #[Test]
    public function el_dia_sin_atencion_registrado_por_otro_administrador_muestra_su_autor(): void
    {
        $otro = $this->crearUsuario('t-admin-otro', [Rol::ADMINISTRADOR]);
        $this->crearBloqueo(['fecha' => $this->fechaLejana, 'motivo' => 'T-De otro', 'estado' => true, 'registrado_por' => $otro->id]);

        $this->actingAs($this->admin)->get(route('dias-sin-atencion.index'))
            ->assertOk()->assertSee('T-De otro')->assertSee('t-admin-otro');
        $this->assertSame('t-admin-otro', BloqueoAgenda::whereDate('fecha', $this->fechaLejana)->firstOrFail()->registradoPor->nombre_usuario);
    }

    #[Test]
    public function la_fecha_del_dia_sin_atencion_debe_existir_en_el_calendario(): void
    {
        foreach (['2031-02-31', '31/12/2031', 'mañana', ''] as $fecha) {
            $this->actingAs($this->admin)->post(route('dias-sin-atencion.guardar'), ['fecha' => $fecha, 'motivo' => 'T-Mala fecha'])
                ->assertSessionHasErrors('fecha');
        }
        $this->assertSame(0, BloqueoAgenda::where('motivo', 'T-Mala fecha')->count());
    }

    #[Test]
    public function un_dia_sin_atencion_desactivado_tampoco_se_puede_registrar_otra_vez_y_se_reactiva_desde_el_listado(): void
    {
        $dia = $this->crearBloqueo(['fecha' => $this->fechaLejana, 'motivo' => 'T-Desactivado', 'estado' => false]);

        $this->actingAs($this->admin)->post(route('dias-sin-atencion.guardar'), ['fecha' => $this->fechaLejana, 'motivo' => 'T-Otra vez'])
            ->assertSessionHasErrors(['fecha' => 'La fecha ya está registrada como día sin atención.']);

        $this->get(route('dias-sin-atencion.index'))->assertOk()->assertSee('Desactivado');
        $this->post(route('dias-sin-atencion.activar', $dia))->assertSessionHas('exito');
        $this->assertTrue($dia->fresh()->estado);
    }

    #[Test]
    public function un_dia_sin_atencion_bloquea_la_disponibilidad_y_al_desactivarlo_la_fecha_vuelve_a_estar_disponible(): void
    {
        $lunes = $this->proximaFecha(1, 14);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
        $disponibilidad = app(DisponibilidadService::class);
        $this->assertCount(8, $disponibilidad->calcularLibres($this->medico, $lunes)->libres);

        $this->actingAs($this->admin)->post(route('dias-sin-atencion.guardar'), ['fecha' => $lunes, 'motivo' => 'T-Paro'])->assertSessionHasNoErrors();
        $bloqueada = $disponibilidad->calcularLibres($this->medico, $lunes);
        $this->assertCount(0, $bloqueada->libres);
        $this->assertStringContainsString('T-Paro', $bloqueada->mensaje);

        $dia = BloqueoAgenda::whereDate('fecha', $lunes)->firstOrFail();
        $this->post(route('dias-sin-atencion.desactivar', $dia));
        $this->assertCount(8, $disponibilidad->calcularLibres($this->medico, $lunes)->libres, 'La fecha vuelve a estar disponible.');
    }

    #[Test]
    public function las_citas_afectadas_incluyen_confirmadas_y_las_de_cualquier_medico_y_no_se_modifican(): void
    {
        $otroMedico = $this->crearMedico([], [$this->especialidad]);
        $confirmada = $this->crearCita($otroMedico, $this->crearPaciente(), $this->especialidad, $this->fechaLejana, '08:00', '08:30', Cita::CONFIRMADA);
        $programada = $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->fechaLejana, '09:00', '09:30');

        $this->actingAs($this->admin)->post(route('dias-sin-atencion.guardar'), ['fecha' => $this->fechaLejana, 'motivo' => 'T-Dos medicos'])
            ->assertSessionHas('exito', fn ($m) => str_contains($m, '2 citas programadas afectadas'));

        $dia = BloqueoAgenda::whereDate('fecha', $this->fechaLejana)->firstOrFail();
        $this->get(route('dias-sin-atencion.index', ['afectadas' => $dia->id]))
            ->assertOk()->assertSee($confirmada->codigo)->assertSee($programada->codigo)->assertSee('deben reprogramarse');

        $this->assertSame(Cita::CONFIRMADA, $confirmada->fresh()->estado);
        $this->assertSame(Cita::PROGRAMADA, $programada->fresh()->estado);
    }

    #[Test]
    public function registrar_un_dia_sin_citas_avisa_que_no_hay_citas_afectadas(): void
    {
        $this->actingAs($this->admin)->post(route('dias-sin-atencion.guardar'), ['fecha' => $this->fechaLejana, 'motivo' => 'T-Sin citas'])
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'No hay citas programadas ese día.'));
    }

    #[Test]
    public function el_formulario_de_dias_sin_atencion_pide_fecha_futura_y_motivo(): void
    {
        $this->actingAs($this->admin)->get(route('dias-sin-atencion.index'))
            ->assertOk()->assertSee('name="fecha"', false)->assertSee('min="'.Carbon::today()->toDateString().'"', false)
            ->assertSee('name="motivo"', false)->assertSee('data-validar', false);
    }

    #[Test]
    public function la_agenda_semanal_muestra_los_bloques_con_su_cantidad_de_citas(): void
    {
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
        $this->crearHorario($this->medico, 1, '14:00', '17:00', 20);

        $this->actingAs($this->admin)->get(route('horarios.index', ['medico' => $this->medico->id]))
            ->assertOk()->assertSee('08:00 – 12:00')->assertSee('8 citas')->assertSee('14:00 – 17:00')->assertSee('9 citas');
    }

    /** Datos válidos de un bloque (lunes 08:00 a 12:00, 30 min) para el médico de la prueba. */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'medico_id' => $this->medico->id, 'dia_semana' => 1,
            'hora_inicio' => '08:00', 'hora_fin' => '12:00', 'duracion_min' => 30,
        ];
    }
}
