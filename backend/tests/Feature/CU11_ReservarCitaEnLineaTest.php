<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\DisponibilidadService;
use App\Modules\GestionCitas\Compartido\HorarioOcupadoException;
use App\Modules\GestionCitas\CU11_ReservarCitaEnLinea\ReservarCitaService;
use Illuminate\Support\Carbon;

/** CU-11: Reservar cita en línea. */
class CU11_ReservarCitaEnLineaTest extends SistemaTestCase
{
    private Usuario $usuario;

    private Paciente $paciente;

    private Especialidad $especialidad;

    private Medico $medico;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuario = $this->crearUsuario('t-paciente-res', [Rol::PACIENTE]);
        $this->paciente = $this->crearPaciente(['usuario_id' => $this->usuario->id, 'nombres' => 'Reserva', 'apellidos' => 'Tprueba']);
        $this->especialidad = $this->crearEspecialidad('T-Reservas');
        $this->medico = $this->crearMedico(['ubicacion' => 'T-Sede Norte'], [$this->especialidad]);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
        $this->lunes = $this->proximaFecha(1);
    }

    /** Caso 1: reservar una cita en línea (paciente, especialidad, médico y horario libre) → la cita queda registrada con código y estado Programada. */
    public function test_reservar_una_cita_en_linea(): void
    {
        $respuesta = $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos());

        $cita = Cita::where('medico_id', $this->medico->id)->firstOrFail();
        $respuesta->assertRedirect(route('reservas.comprobante', $cita))->assertSessionHas('exito');

        $this->assertMatchesRegularExpression('/^CM-\d{4}$/', $cita->codigo);
        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertSame($this->paciente->id, $cita->paciente_id, 'La cita queda a nombre del paciente autenticado.');
        $this->assertSame($this->usuario->id, $cita->creado_por);
        $this->assertSame('09:00', $cita->hora_inicio_corta);
        $this->assertSame(Cita::PRESENCIAL, $cita->modalidad);
        $this->assertDatabaseHas('bitacora', ['accion' => 'CITA_RESERVADA', 'usuario_id' => $this->usuario->id]);

        $this->get(route('reservas.comprobante', $cita))->assertOk()
            ->assertSee($cita->codigo)->assertSee('Reserva Tprueba')->assertSee('09:00 – 09:30')->assertSee('Control de rutina')->assertSee('Programada');
    }

    /** Caso 2: reservar un horario ocupado (horario tomado por otro usuario) → el sistema rechaza la reserva y muestra los horarios libres. */
    public function test_reservar_un_horario_ocupado(): void
    {
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos())
            ->assertRedirect($this->urlReservar())
            ->assertSessionHas('error', HorarioOcupadoException::MENSAJE);

        $this->assertSame(0, Cita::where('paciente_id', $this->paciente->id)->count());
        $this->get($this->urlReservar())->assertOk()
            ->assertSee('El horario fue ocupado por otro usuario')->assertSee('09:30 – 10:00')->assertDontSee('09:00 – 09:30');
    }

    /** Caso 3: reservar con una cita en la misma hora (paciente con otra cita activa a esa hora) → el sistema no permite la reserva. */
    public function test_reservar_con_una_cita_en_la_misma_hora(): void
    {
        $otroMedico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($otroMedico, 1, '08:00', '12:00', 30);
        $this->crearCita($otroMedico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos())
            ->assertSessionHas('error', 'Ya tiene una cita activa a esa hora. Elija otro horario.');

        $this->assertSame(0, Cita::where('medico_id', $this->medico->id)->count());
    }

    /** Caso 4: buscar médicos por especialidad y ubicación (especialidad y ubicación) → se listan solo los médicos activos que coinciden. */
    public function test_buscar_medicos_por_especialidad_y_ubicacion(): void
    {
        $this->crearMedico(['nombres' => 'Zona', 'apellidos' => 'Tsur', 'ubicacion' => 'T-Sede Sur'], [$this->especialidad]);
        $this->crearMedico(['nombres' => 'Baja', 'apellidos' => 'Tinactivo', 'ubicacion' => 'T-Sede Norte', 'estado' => false], [$this->especialidad]);
        $otra = $this->crearEspecialidad('T-Otra Especialidad');
        $this->crearMedico(['nombres' => 'Otra', 'apellidos' => 'Tespecialidad', 'ubicacion' => 'T-Sede Norte'], [$otra]);
        $this->actingAs($this->usuario);

        $this->get(route('reservas.crear', ['especialidad' => $this->especialidad->id, 'ubicacion' => 'T-Sede Norte']))->assertOk()
            ->assertSee($this->medico->nombre_completo)
            ->assertDontSee('Zona Tsur')->assertDontSee('Baja Tinactivo')->assertDontSee('Otra Tespecialidad');

        // Sin ubicación: todos los médicos activos de la especialidad.
        $this->get(route('reservas.crear', ['especialidad' => $this->especialidad->id]))->assertOk()
            ->assertSee($this->medico->nombre_completo)->assertSee('Zona Tsur')->assertDontSee('Baja Tinactivo');
    }

    public function test_si_ningun_medico_coincide_lo_indica_y_permite_cambiar_la_busqueda(): void
    {
        $this->actingAs($this->usuario)->get(route('reservas.crear', ['especialidad' => $this->especialidad->id, 'ubicacion' => 'T-Sede Inexistente']))
            ->assertOk()->assertSee(ReservarCitaService::MENSAJE_SIN_MEDICOS)->assertSee('Buscar médicos');
    }

    public function test_la_busqueda_muestra_el_proximo_horario_libre_de_cada_medico(): void
    {
        // El primer lunes que le queda libre al médico dentro de los próximos 14 días (puede ser antes de la fecha de las otras pruebas).
        $proximo = app(DisponibilidadService::class)->proximoHorarioLibre($this->medico);
        $this->assertNotNull($proximo);

        $this->actingAs($this->usuario)->get(route('reservas.crear', ['especialidad' => $this->especialidad->id]))
            ->assertOk()->assertSee('Próximo horario libre')->assertSee(Carbon::parse($proximo['fecha'])->format('d/m/Y').' · '.$proximo['inicio']);

        // Un médico sin horarios libres próximos lo dice.
        $sinHorario = $this->crearMedico(['nombres' => 'Sin', 'apellidos' => 'Thorario'], [$this->especialidad]);
        $this->get(route('reservas.crear', ['especialidad' => $this->especialidad->id]))->assertOk()->assertSee('Sin horarios libres en los próximos 14 días');
        $this->assertNull(app(DisponibilidadService::class)->proximoHorarioLibre($sinHorario));
    }

    public function test_la_cita_siempre_queda_a_nombre_del_paciente_autenticado_aunque_se_envie_otro(): void
    {
        $otro = $this->crearPaciente(['nombres' => 'Ajeno', 'apellidos' => 'Tpaciente']);

        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['paciente_id' => $otro->id]))->assertRedirect();

        $this->assertSame(1, Cita::where('paciente_id', $this->paciente->id)->count());
        $this->assertSame(0, Cita::where('paciente_id', $otro->id)->count());
    }

    public function test_una_cuenta_sin_ficha_de_paciente_o_con_ficha_inactiva_no_puede_reservar(): void
    {
        $sinFicha = $this->crearUsuario('t-pac-sin-ficha', [Rol::PACIENTE]);

        $this->actingAs($sinFicha)->get(route('reservas.crear'))->assertOk()->assertSee(ReservarCitaService::MENSAJE_SIN_FICHA);
        $this->post(route('reservas.guardar'), $this->datos())->assertSessionHas('error', ReservarCitaService::MENSAJE_SIN_FICHA);

        $this->paciente->update(['estado' => false]);
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos())->assertSessionHas('error', ReservarCitaService::MENSAJE_FICHA_INACTIVA);
        $this->assertSame(0, Cita::where('medico_id', $this->medico->id)->count());
    }

    public function test_no_se_reserva_en_fecha_pasada_ni_en_feriado_ni_fuera_del_horario(): void
    {
        $this->actingAs($this->usuario);

        $this->post(route('reservas.guardar'), $this->datos(['fecha' => Carbon::yesterday()->toDateString()]))
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_FECHA_PASADA);

        BloqueoAgenda::create(['fecha' => $this->lunes, 'motivo' => 'T-Feriado', 'estado' => true]);
        $this->post(route('reservas.guardar'), $this->datos())->assertSessionHas('error', fn ($m) => str_contains($m, 'feriado o día sin atención'));
        BloqueoAgenda::whereDate('fecha', $this->lunes)->delete();

        $this->post(route('reservas.guardar'), $this->datos(['hora_inicio' => '15:00']))
            ->assertSessionHas('error', 'La hora elegida está fuera del horario de atención del médico.');
        $this->post(route('reservas.guardar'), $this->datos(['fecha' => $this->proximaFecha(2)]))
            ->assertSessionHas('error', DisponibilidadService::MENSAJE_NO_ATIENDE);

        $this->assertSame(0, Cita::where('medico_id', $this->medico->id)->count());
    }

    public function test_dos_pacientes_reservan_el_mismo_horario_una_gana_y_la_otra_ve_los_horarios_actualizados(): void
    {
        $usuario2 = $this->crearUsuario('t-paciente-res-2', [Rol::PACIENTE]);
        $this->crearPaciente(['usuario_id' => $usuario2->id, 'nombres' => 'Segunda', 'apellidos' => 'Tpaciente']);

        $this->actingAs($this->usuario)->get($this->urlReservar())->assertOk()->assertSee('09:00 – 09:30');
        $this->actingAs($usuario2)->get($this->urlReservar())->assertOk()->assertSee('09:00 – 09:30');

        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos())->assertRedirect();
        $this->actingAs($usuario2)->post(route('reservas.guardar'), $this->datos())->assertSessionHas('error', HorarioOcupadoException::MENSAJE);

        $this->assertSame(1, Cita::where('medico_id', $this->medico->id)->where('estado', '<>', Cita::CANCELADA)->count());
        $this->get($this->urlReservar())->assertOk()->assertDontSee('09:00 – 09:30')->assertSee('09:30 – 10:00');
    }

    public function test_el_formulario_se_valida(): void
    {
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), ['modalidad' => 'X', 'fecha' => 'hoy', 'hora_inicio' => '9'])
            ->assertSessionHasErrors([
                'especialidad_id' => 'Seleccione la especialidad.', 'medico_id' => 'Seleccione el médico.', 'fecha' => 'La fecha no es válida.',
                'hora_inicio' => 'El horario seleccionado no es válido.', 'modalidad' => 'La modalidad debe ser presencial o virtual.',
            ]);
    }

    public function test_una_cita_virtual_queda_con_su_modalidad(): void
    {
        $this->actingAs($this->usuario)->post(route('reservas.guardar'), $this->datos(['modalidad' => 'VIRTUAL']))->assertRedirect();

        $this->assertSame(Cita::VIRTUAL, Cita::where('paciente_id', $this->paciente->id)->firstOrFail()->modalidad);
    }

    public function test_la_pantalla_de_reserva_muestra_al_medico_los_horarios_y_el_formulario(): void
    {
        $this->actingAs($this->usuario)->get($this->urlReservar())->assertOk()
            ->assertSee($this->medico->nombre_completo)->assertSee('T-Sede Norte')->assertSee('Horarios libres (8)')->assertSee('Confirmar reserva');

        $this->get(route('reservas.reservar', ['especialidad' => $this->especialidad->id, 'medico' => $this->medico->id, 'fecha' => $this->proximaFecha(2)]))
            ->assertOk()->assertSee('El médico no atiende en esa fecha')->assertDontSee('Confirmar reserva');

        // Un médico inactivo o desconocido lleva de vuelta a la búsqueda.
        $this->medico->update(['estado' => false]);
        $this->get($this->urlReservar())->assertRedirect(route('reservas.crear'));
    }

    public function test_cada_paciente_solo_ve_el_comprobante_de_sus_citas(): void
    {
        $cita = $this->crearCita($this->medico, $this->paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $otro = $this->crearUsuario('t-paciente-ajeno', [Rol::PACIENTE]);

        $this->actingAs($this->usuario)->get(route('reservas.comprobante', $cita))->assertOk()->assertSee($cita->codigo);
        $this->actingAs($otro)->get(route('reservas.comprobante', $cita))->assertForbidden();
    }

    public function test_un_medico_que_tambien_es_paciente_puede_reservar(): void
    {
        $medicoPaciente = $this->crearUsuario('t-medico-paciente', [Rol::MEDICO, Rol::PACIENTE]);
        $this->crearPaciente(['usuario_id' => $medicoPaciente->id]);

        $this->actingAs($medicoPaciente)->post(route('reservas.guardar'), $this->datos())->assertRedirect();

        $this->assertSame(1, Cita::where('medico_id', $this->medico->id)->count());
    }

    public function test_solo_el_paciente_reserva_en_linea(): void
    {
        $this->get(route('reservas.crear'))->assertRedirect('/login');

        foreach ([Rol::ADMINISTRADOR, Rol::RECEPCIONISTA, Rol::MEDICO] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-res-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('reservas.crear'))->assertForbidden();
            $this->actingAs($usuario)->post(route('reservas.guardar'), $this->datos())->assertForbidden();
        }
        $this->assertSame(0, Cita::where('medico_id', $this->medico->id)->count());
    }

    /** Datos válidos de la reserva de las 09:00 del lunes; cada prueba cambia lo que necesita. */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'especialidad_id' => $this->especialidad->id, 'medico_id' => $this->medico->id, 'fecha' => $this->lunes,
            'hora_inicio' => '09:00', 'modalidad' => 'PRESENCIAL', 'motivo' => 'Control de rutina',
        ];
    }

    private function urlReservar(): string
    {
        return route('reservas.reservar', ['especialidad' => $this->especialidad->id, 'medico' => $this->medico->id, 'fecha' => $this->lunes]);
    }
}
