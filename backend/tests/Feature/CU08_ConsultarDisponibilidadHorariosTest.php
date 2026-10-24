<?php

namespace Tests\Feature;

use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\Compartido\Disponibilidad;
use App\Modules\GestionCitas\Compartido\DisponibilidadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** CU-08: Consultar disponibilidad de horarios. */
class CU08_ConsultarDisponibilidadHorariosTest extends SistemaTestCase
{
    private Usuario $recepcion;

    private Especialidad $especialidad;

    private Medico $medico;

    private string $lunes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recepcion = $this->crearUsuario('t-recep-disp', [Rol::RECEPCIONISTA]);
        $this->especialidad = $this->crearEspecialidad('T-Disponibilidad');
        $this->medico = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30); // lunes 08:00 a 12:00: 8 bloques de 30 minutos
        $this->lunes = $this->proximaFecha(1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Caso 1: consultar horarios en un día sin atención (médico con horario ese día de la semana) → el sistema no ofrece horarios para esa fecha. */
    public function test_consultar_horarios_en_un_dia_sin_atencion(): void
    {
        BloqueoAgenda::create(['fecha' => $this->lunes, 'motivo' => 'T-Feriado de prueba', 'estado' => true]);

        $this->actingAs($this->recepcion)->get($this->url($this->lunes))
            ->assertOk()
            ->assertSee('feriado o día sin atención')->assertSee('T-Feriado de prueba')
            ->assertDontSee('08:00 – 08:30');

        $resultado = $this->servicio()->calcularLibres($this->medico, $this->lunes);
        $this->assertFalse($resultado->hayLibres());
        $this->assertSame(Disponibilidad::DIA_SIN_ATENCION, $resultado->motivo);
    }

    /** Caso 2: consultar los horarios libres de un médico (horario 08:00 a 12:00 y una cita a las 09:00) → se muestran los bloques libres y no aparece el de las 09:00. */
    public function test_consultar_los_horarios_libres_de_un_medico(): void
    {
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '09:00', '09:30');

        $this->actingAs($this->recepcion)->get($this->url($this->lunes))
            ->assertOk()
            ->assertSee('Horarios libres (7)')
            ->assertSee('08:00 – 08:30')->assertSee('08:30 – 09:00')->assertSee('09:30 – 10:00')->assertSee('11:30 – 12:00')
            ->assertDontSee('09:00 – 09:30');

        $inicios = array_column($this->servicio()->calcularLibres($this->medico, $this->lunes)->libres, 'inicio');
        $this->assertSame(['08:00', '08:30', '09:30', '10:00', '10:30', '11:00', '11:30'], $inicios);
    }

    /** Caso 3: consultar un día en que el médico no atiende (fecha de un día sin horario del médico) → el sistema indica que el médico no atiende en esa fecha. */
    public function test_consultar_un_dia_en_que_el_medico_no_atiende(): void
    {
        $martes = $this->proximaFecha(2);

        $this->actingAs($this->recepcion)->get($this->url($martes))
            ->assertOk()->assertSee('El médico no atiende en esa fecha')->assertDontSee('08:00 – 08:30');

        $this->assertSame(Disponibilidad::NO_ATIENDE, $this->servicio()->calcularLibres($this->medico, $martes)->motivo);
    }

    /** Caso 4: consultar horarios en una fecha pasada (fecha anterior a hoy) → el sistema no muestra horarios. */
    public function test_consultar_horarios_en_una_fecha_pasada(): void
    {
        $ayer = Carbon::yesterday()->toDateString();

        $this->actingAs($this->recepcion)->get($this->url($ayer))
            ->assertOk()->assertSee('La fecha elegida ya pasó')->assertDontSee('Horarios libres');

        $this->assertSame(Disponibilidad::FECHA_PASADA, $this->servicio()->calcularLibres($this->medico, $ayer)->motivo);
    }

    /** Caso 5: un horario cancelado vuelve a estar libre (médico con una cita cancelada) → el bloque de la cita cancelada aparece libre. */
    public function test_un_horario_cancelado_vuelve_a_estar_libre(): void
    {
        $paciente = $this->crearPaciente();
        $cita = $this->crearCita($this->medico, $paciente, $this->especialidad, $this->lunes, '09:00', '09:30');
        $this->assertNull($this->servicio()->calcularLibres($this->medico, $this->lunes)->libre('09:00'));

        $cita->update(['estado' => Cita::CANCELADA]);

        $this->actingAs($this->recepcion)->get($this->url($this->lunes))->assertOk()->assertSee('09:00 – 09:30')->assertSee('Horarios libres (8)');
        $this->assertNotNull($this->servicio()->calcularLibres($this->medico, $this->lunes)->libre('09:00'));
    }

    public function test_si_la_fecha_es_hoy_se_descartan_los_bloques_que_ya_pasaron(): void
    {
        Carbon::setTestNow(Carbon::parse($this->lunes.' 10:15:00'));

        $inicios = array_column($this->servicio()->calcularLibres($this->medico, $this->lunes)->libres, 'inicio');

        $this->assertSame(['10:30', '11:00', '11:30'], $inicios);
    }

    public function test_cuando_no_quedan_bloques_libres_dice_que_no_hay_horarios_disponibles(): void
    {
        Carbon::setTestNow(Carbon::parse($this->lunes.' 11:45:00')); // hoy y ya pasó el último bloque
        $this->actingAs($this->recepcion)->get($this->url($this->lunes))->assertOk()->assertSee('No hay horarios disponibles');
        Carbon::setTestNow();

        // O porque todos los bloques están ocupados.
        $paciente = $this->crearPaciente();
        $this->crearCita($this->medico, $paciente, $this->especialidad, $this->lunes, '08:00', '12:00');
        $resultado = $this->servicio()->calcularLibres($this->medico, $this->lunes);

        $this->assertSame(Disponibilidad::SIN_HORARIOS, $resultado->motivo);
        $this->assertSame('No hay horarios disponibles', $resultado->mensaje);
        $this->assertCount(8, $resultado->todos);
    }

    public function test_una_cita_que_cruza_dos_bloques_ocupa_los_dos(): void
    {
        $this->crearCita($this->medico, $this->crearPaciente(), $this->especialidad, $this->lunes, '09:15', '09:45');

        $inicios = array_column($this->servicio()->calcularLibres($this->medico, $this->lunes)->libres, 'inicio');

        $this->assertNotContains('09:00', $inicios);
        $this->assertNotContains('09:30', $inicios);
        $this->assertContains('08:30', $inicios);
        $this->assertContains('10:00', $inicios);
    }

    public function test_divide_cada_bloque_segun_su_duracion_y_une_varios_horarios_del_dia(): void
    {
        $otro = $this->crearMedico([], [$this->especialidad]);
        $this->crearHorario($otro, 1, '08:00', '09:00', 20);   // 3 bloques de 20 minutos
        $this->crearHorario($otro, 1, '14:00', '15:00', 30);   // 2 bloques de 30 minutos

        $libres = $this->servicio()->calcularLibres($otro, $this->lunes)->libres;

        $this->assertSame(
            ['08:00 08:20', '08:20 08:40', '08:40 09:00', '14:00 14:30', '14:30 15:00'],
            array_map(fn ($b) => $b['inicio'].' '.$b['fin'], $libres)
        );
    }

    public function test_un_bloque_desactivado_no_se_ofrece_y_un_dia_sin_atencion_desactivado_vuelve_a_estar_disponible(): void
    {
        $this->crearHorario($this->medico, 1, '14:00', '16:00', 60, activo: false);
        BloqueoAgenda::create(['fecha' => $this->lunes, 'motivo' => 'T-Temporal', 'estado' => false]);

        $inicios = array_column($this->servicio()->calcularLibres($this->medico, $this->lunes)->libres, 'inicio');

        $this->assertNotContains('14:00', $inicios);
        $this->assertContains('08:00', $inicios);
    }

    public function test_un_medico_inactivo_no_recibe_citas(): void
    {
        $this->medico->update(['estado' => false]);

        $resultado = $this->servicio()->calcularLibres($this->medico->fresh(), $this->lunes);

        $this->assertSame(Disponibilidad::MEDICO_INACTIVO, $resultado->motivo);
        $this->assertFalse($resultado->hayLibres());
    }

    public function test_la_consulta_no_modifica_ningun_dato(): void
    {
        $citas = Cita::count();
        $bitacora = DB::table('bitacora')->count();

        $this->actingAs($this->recepcion)->get($this->url($this->lunes))->assertOk();

        $this->assertSame($citas, Cita::count());
        $this->assertSame($bitacora, DB::table('bitacora')->count());
    }

    public function test_el_medico_y_la_fecha_son_obligatorios_juntos_y_el_medico_debe_estar_activo(): void
    {
        $this->actingAs($this->recepcion);

        $this->get(route('disponibilidad.index', ['fecha' => $this->lunes]))->assertSessionHasErrors(['medico' => 'Elija el médico.']);
        $this->get(route('disponibilidad.index', ['medico' => $this->medico->id]))->assertSessionHasErrors(['fecha' => 'Elija la fecha.']);
        $this->get(route('disponibilidad.index', ['medico' => 999999999, 'fecha' => $this->lunes]))->assertSessionHasErrors('medico');
        $this->get(route('disponibilidad.index'))->assertOk()->assertSee('Elija el médico y la fecha');
    }

    public function test_acceden_la_recepcionista_y_el_paciente_pero_no_el_medico_ni_el_administrador_sin_ese_rol(): void
    {
        $this->get(route('disponibilidad.index'))->assertRedirect('/login');

        $this->actingAs($this->recepcion)->get(route('disponibilidad.index'))->assertOk();
        $paciente = $this->crearUsuario('t-pac-disp', [Rol::PACIENTE]);
        $this->actingAs($paciente)->get($this->url($this->lunes))->assertOk()->assertSee('Horarios libres');

        foreach ([Rol::MEDICO, Rol::ADMINISTRADOR] as $rol) {
            $usuario = $this->crearUsuario('t-sin-permiso-disp-'.strtolower(substr($rol, 0, 3)), [$rol]);
            $this->actingAs($usuario)->get(route('disponibilidad.index'))->assertForbidden();
        }
    }

    private function servicio(): DisponibilidadService
    {
        return app(DisponibilidadService::class);
    }

    private function url(string $fecha): string
    {
        return route('disponibilidad.index', ['medico' => $this->medico->id, 'fecha' => $fecha]);
    }
}
