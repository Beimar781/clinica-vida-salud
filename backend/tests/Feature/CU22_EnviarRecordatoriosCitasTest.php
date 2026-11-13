<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\GestionCitas\CU22_EnviarRecordatoriosCitas\RecordatorioCitaMail;
use App\Modules\GestionCitas\CU22_EnviarRecordatoriosCitas\RecordatorioService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/**
 * CU-22: Enviar recordatorios de citas. Los métodos paso_N son las filas del plan de pruebas 8.1; los demás son reglas internas.
 * «Hoy» se fija en el 04/03/2035, así «mañana» es el 05/03/2035 (lunes) y las pruebas no tocan las citas de la demostración;
 * el correo siempre se simula (Mail::fake() o un transporte de prueba): ninguna prueba envía nada de verdad.
 */
class CU22_EnviarRecordatoriosCitasTest extends SistemaTestCase
{
    private const HOY = '2035-03-04 10:00:00';

    private const MANANA = '2035-03-05';

    private Medico $medico;

    private Especialidad $especialidad;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::HOY));
        $this->especialidad = $this->crearEspecialidad('T-Rec-General', precio: 100);
        $this->medico = $this->crearMedico(['nombres' => 'Rosario', 'apellidos' => 'Trecordatorio', 'ubicacion' => 'Consultorio 4, Sede Centro'], [$this->especialidad]);
        $this->crearHorario($this->medico, 1, '08:00', '12:00', 30);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function paciente(string $sufijo, array $datos = []): Paciente
    {
        return $this->crearPaciente($datos + [
            'nombres' => 'Paciente'.$sufijo, 'apellidos' => 'Trec', 'correo' => "t-rec-{$sufijo}@prueba.test",
            'recibe_recordatorios' => true, 'estado' => true,
        ]);
    }

    private function cita(Paciente $paciente, string $inicio, string $fecha = self::MANANA, string $estado = Cita::PROGRAMADA, array $extra = []): Cita
    {
        $cita = $this->crearCita($this->medico, $paciente, $this->especialidad, $fecha, $inicio, date('H:i', strtotime($inicio) + 1800), $estado);
        $cita->update($extra + ['monto' => 100, 'metodo_pago' => 'EFECTIVO', 'estado_pago' => 'PENDIENTE', 'recordatorio_enviado' => false]);

        return $cita->fresh();
    }

    private function ejecutar(): PendingCommand
    {
        return $this->artisan('citas:enviar-recordatorios');
    }

    private function bitacora(string $accion): Collection
    {
        return DB::table('bitacora')->where('accion', $accion)->where('detalle', 'like', '%'.'T-%')->orWhere(fn ($q) => $q->where('accion', $accion)->where('detalle', 'like', '%PAC-%'))->get();
    }

    // ---------- Plan de pruebas 8.1 ----------

    /** Paso 1: enviar los recordatorios del día siguiente (citas de mañana de pacientes con notificaciones activadas) → se envía un aviso por paciente y las citas quedan marcadas. */
    #[Test]
    public function paso_1_enviar_los_recordatorios_del_dia_siguiente(): void
    {
        Mail::fake();
        $ana = $this->paciente('ana');
        $beto = $this->paciente('beto');
        $anaCita1 = $this->cita($ana, '08:00', extra: ['estado' => Cita::PROGRAMADA]);
        $anaCita2 = $this->cita($ana, '09:00', estado: Cita::CONFIRMADA);
        $betoCita = $this->cita($beto, '10:00', estado: Cita::CONFIRMADA);

        $this->ejecutar()->expectsOutputToContain('2 avisos enviados (3 citas marcadas)')->assertExitCode(0);

        // Un aviso por paciente (Ana recibe uno solo con sus dos citas) y las citas quedan marcadas.
        Mail::assertSent(RecordatorioCitaMail::class, fn ($m) => $m->hasTo('t-rec-ana@prueba.test') && $m->citas->pluck('codigo')->sort()->values()->all() === collect([$anaCita1->codigo, $anaCita2->codigo])->sort()->values()->all());
        Mail::assertSent(RecordatorioCitaMail::class, fn ($m) => $m->hasTo('t-rec-beto@prueba.test') && $m->citas->count() === 1);
        $this->assertTrue($anaCita1->fresh()->recordatorio_enviado);
        $this->assertTrue($anaCita2->fresh()->recordatorio_enviado);
        $this->assertTrue($betoCita->fresh()->recordatorio_enviado);

        // Quedó en la bitácora (sin usuario: lo hace el sistema) y sin el correo del paciente.
        $registros = DB::table('bitacora')->where('accion', 'RECORDATORIO_ENVIADO')->where('detalle', 'like', '%'.$anaCita1->codigo.'%')->get();
        $this->assertCount(1, $registros);
        $this->assertNull($registros[0]->usuario_id);
        $this->assertStringContainsString($ana->codigo, $registros[0]->detalle);
        $this->assertStringContainsString($anaCita2->codigo, $registros[0]->detalle);
        $this->assertStringNotContainsString('prueba.test', $registros[0]->detalle);
    }

    /** Paso 2: paciente con las notificaciones desactivadas (cita de mañana con notificaciones desactivadas) → no se envía el aviso. */
    #[Test]
    public function paso_2_paciente_con_las_notificaciones_desactivadas(): void
    {
        Mail::fake();
        $paciente = $this->paciente('sinavisos', ['recibe_recordatorios' => false]);
        $cita = $this->cita($paciente, '08:00');

        $this->ejecutar()->expectsOutputToContain('el paciente tiene las notificaciones desactivadas')->assertExitCode(0);

        Mail::assertNotSent(RecordatorioCitaMail::class, fn ($m) => $m->hasTo('t-rec-sinavisos@prueba.test'));
        $this->assertFalse($cita->fresh()->recordatorio_enviado, 'La cita se omite: no se marca.');
        $this->assertDatabaseMissing('bitacora', ['accion' => 'RECORDATORIO_ENVIADO', 'detalle' => 'Recordatorio enviado a '.$paciente->codigo.' de la cita '.$cita->codigo.' del 05/03/2035']);
    }

    // ---------- Reglas internas ----------

    #[Test]
    public function test_un_paciente_sin_correo_se_omite(): void
    {
        Mail::fake();
        $paciente = $this->paciente('sincorreo', ['correo' => null]);
        $cita = $this->cita($paciente, '08:00');

        $this->ejecutar()->expectsOutputToContain('el paciente no tiene correo')->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertFalse($cita->fresh()->recordatorio_enviado);
    }

    #[Test]
    public function test_un_paciente_inactivo_se_omite(): void
    {
        Mail::fake();
        $paciente = $this->paciente('inactivo', ['estado' => false]);
        $cita = $this->cita($paciente, '08:00');

        $this->ejecutar()->expectsOutputToContain('el paciente está inactivo')->assertExitCode(0);

        Mail::assertNothingSent();
        $this->assertFalse($cita->fresh()->recordatorio_enviado);
    }

    #[Test]
    public function test_solo_se_recuerdan_las_citas_programadas_o_confirmadas_de_manana_sin_aviso_previo(): void
    {
        Mail::fake();
        $paciente = $this->paciente('estados');
        $buena = $this->cita($paciente, '08:00');
        $canceladas = $this->cita($paciente, '08:30', estado: Cita::CANCELADA);
        $atendida = $this->cita($paciente, '09:00', estado: Cita::ATENDIDA);
        $inasistencia = $this->cita($paciente, '09:30', estado: Cita::INASISTENCIA);
        $yaAvisada = $this->cita($paciente, '10:00', extra: ['recordatorio_enviado' => true]);
        $hoy = $this->cita($paciente, '10:30', fecha: '2035-03-04');
        $pasadoManana = $this->cita($paciente, '11:00', fecha: '2035-03-06');

        $this->ejecutar()->assertExitCode(0);

        Mail::assertSent(RecordatorioCitaMail::class, 1);
        Mail::assertSent(RecordatorioCitaMail::class, fn ($m) => $m->citas->pluck('codigo')->all() === [$buena->codigo]);
        foreach ([$canceladas, $atendida, $inasistencia, $hoy, $pasadoManana] as $otra) {
            $this->assertFalse($otra->fresh()->recordatorio_enviado, "La cita {$otra->estado} del {$otra->fecha->format('d/m')} no se avisa.");
        }
        $this->assertTrue($yaAvisada->fresh()->recordatorio_enviado);
    }

    #[Test]
    public function test_ejecutarlo_otra_vez_no_repite_los_avisos(): void
    {
        Mail::fake();
        $paciente = $this->paciente('dos');
        $this->cita($paciente, '08:00');

        $this->ejecutar()->assertExitCode(0);
        $this->ejecutar()->expectsOutputToContain('no hay citas Programadas o Confirmadas pendientes de aviso')->assertExitCode(0);

        Mail::assertSent(RecordatorioCitaMail::class, 1);
    }

    #[Test]
    public function test_si_el_envio_falla_la_cita_queda_sin_marcar_y_se_sigue_con_los_demas(): void
    {
        // Transporte de prueba: falla solo para un paciente y guarda los demás envíos en memoria.
        $enviados = new \ArrayObject;
        Mail::extend('prueba-falla', fn () => new class($enviados) extends AbstractTransport
        {
            public function __construct(private \ArrayObject $enviados)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $mensaje): void
            {
                foreach ($mensaje->getEnvelope()->getRecipients() as $destino) {
                    if ($destino->getAddress() === 't-rec-malo@prueba.test') {
                        throw new TransportException('Brevo rechazó la clave API secreta-123');
                    }
                    $this->enviados[] = $destino->getAddress();
                }
            }

            public function __toString(): string
            {
                return 'prueba-falla';
            }
        });
        config(['mail.mailers.prueba-falla' => ['transport' => 'prueba-falla'], 'mail.default' => 'prueba-falla']);

        $malo = $this->paciente('malo');
        $bueno = $this->paciente('bueno');
        $citaMala = $this->cita($malo, '08:00');
        $citaBuena = $this->cita($bueno, '09:00');

        $this->ejecutar()
            ->expectsOutputToContain('Falló el envío a')->expectsOutputToContain('1 avisos enviados (1 citas marcadas), 0 citas omitidas y 1 avisos fallidos')
            ->doesntExpectOutputToContain('Brevo')->doesntExpectOutputToContain('secreta-123')->doesntExpectOutputToContain('TransportException')
            ->assertExitCode(0);

        $this->assertSame(['t-rec-bueno@prueba.test'], $enviados->getArrayCopy());
        $this->assertFalse($citaMala->fresh()->recordatorio_enviado, 'La cita con envío fallido queda sin marcar para reintentarla.');
        $this->assertTrue($citaBuena->fresh()->recordatorio_enviado, 'El fallo de uno no detiene a los demás.');
        $this->assertDatabaseHas('bitacora', ['accion' => 'RECORDATORIO_FALLIDO']);
        $this->assertStringNotContainsString('secreta-123', DB::table('bitacora')->where('accion', 'RECORDATORIO_FALLIDO')->latest('id')->value('detalle'));

        // Al arreglar el correo, la siguiente ejecución lo reintenta.
        $enviados->exchangeArray([]);
        Mail::fake();
        $this->ejecutar()->expectsOutputToContain('1 avisos enviados')->assertExitCode(0);
        $this->assertTrue($citaMala->fresh()->recordatorio_enviado);
        Mail::assertSent(RecordatorioCitaMail::class, fn ($m) => $m->hasTo('t-rec-malo@prueba.test'));
    }

    #[Test]
    public function test_sin_clave_de_brevo_el_fallo_queda_en_el_log_y_no_hay_error_en_pantalla(): void
    {
        config(['mail.default' => 'brevo', 'mail.mailers.brevo' => ['transport' => 'brevo'], 'services.brevo.key' => '']);
        $paciente = $this->paciente('brevo');
        $cita = $this->cita($paciente, '08:00');

        $this->ejecutar()->expectsOutputToContain('Falló el envío')->doesntExpectOutputToContain('BREVO_API_KEY')->assertExitCode(0);

        $this->assertFalse($cita->fresh()->recordatorio_enviado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'RECORDATORIO_FALLIDO']);
    }

    #[Test]
    public function test_una_cita_reprogramada_recibe_el_recordatorio_con_su_nueva_fecha_y_hora(): void
    {
        Mail::fake();
        $recepcion = $this->crearUsuario('t-rec-recep', [Rol::RECEPCIONISTA]);
        $paciente = $this->paciente('repro');
        // Ya había recibido el aviso de su fecha anterior (miércoles 07/03) y la recepción la mueve para mañana (lunes 05/03, 10:00).
        $cita = $this->cita($paciente, '09:00', fecha: '2035-03-07', extra: ['recordatorio_enviado' => true]);

        $this->actingAs($recepcion)->put(route('citas.actualizar', $cita), ['fecha' => self::MANANA, 'hora_inicio' => '10:00', 'motivo_cambio' => 'El paciente pidió otro día'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($cita->fresh()->recordatorio_enviado, 'Reprogramar vuelve a dejar el recordatorio pendiente.');

        $this->ejecutar()->assertExitCode(0);

        Mail::assertSent(RecordatorioCitaMail::class, function ($m) use ($cita) {
            $html = $m->render();

            return $m->hasTo('t-rec-repro@prueba.test') && str_contains($html, $cita->codigo) && str_contains($html, '10:00 a 10:30') && str_contains($html, '05/03/2035');
        });
        $this->assertTrue($cita->fresh()->recordatorio_enviado);
    }

    #[Test]
    public function test_el_correo_trae_codigo_medico_fecha_horario_y_modalidad_y_no_datos_clinicos(): void
    {
        Mail::fake();
        $paciente = $this->paciente('contenido', ['nombres' => 'Valentina']);
        $presencial = $this->cita($paciente, '08:00', extra: ['motivo' => 'Dolor de cabeza muy fuerte', 'monto' => 100]);
        $virtual = $this->cita($paciente, '09:00', estado: Cita::CONFIRMADA, extra: ['modalidad' => 'VIRTUAL', 'metodo_pago' => 'TARJETA', 'estado_pago' => 'PAGADO', 'monto' => 100]);

        $this->ejecutar()->assertExitCode(0);

        Mail::assertSent(RecordatorioCitaMail::class, function (RecordatorioCitaMail $m) use ($presencial, $virtual) {
            $this->assertSame('Recordatorio de tus citas médicas · Clínica Vida Salud', $m->build()->subject);
            foreach ([$m->render(), $this->textoPlano($m)] as $cuerpo) {
                foreach ([$presencial->codigo, $virtual->codigo, 'Rosario Trecordatorio', 'T-Rec-General', 'lunes 05/03/2035', '08:00 a 08:30', '09:00 a 09:30', 'Hola, Valentina',
                    'Presencial · Consultorio 4, Sede Centro', 'Virtual (por videollamada)'] as $dato) {
                    $this->assertStringContainsString($dato, $cuerpo, "El correo debe traer «{$dato}».");
                }
                $this->assertStringNotContainsString('Dolor de cabeza', $cuerpo, 'Nada de datos clínicos.');
            }
            // Monto pendiente solo en la cita de efectivo sin cobrar.
            $this->assertSame(1, substr_count($m->render(), 'Pago pendiente:'));
            $this->assertStringContainsString('Bs 100,00', $m->render());

            return true;
        });
    }

    #[Test]
    public function test_una_sola_cita_usa_el_asunto_en_singular(): void
    {
        Mail::fake();
        $this->cita($this->paciente('uno'), '08:00');

        $this->ejecutar()->assertExitCode(0);

        Mail::assertSent(RecordatorioCitaMail::class, fn (RecordatorioCitaMail $m) => str_starts_with((string) $m->build()->subject, 'Recordatorio de tu cita médica'));
    }

    #[Test]
    public function test_sin_citas_el_comando_lo_dice_y_no_envia_nada(): void
    {
        Mail::fake();

        $this->ejecutar()->expectsOutputToContain('Recordatorios para el 05/03/2035: no hay citas Programadas o Confirmadas pendientes de aviso')->assertExitCode(0);

        Mail::assertNothingSent();
    }

    #[Test]
    public function test_la_opcion_fecha_elige_otro_dia_y_valida_el_formato(): void
    {
        Mail::fake();
        $paciente = $this->paciente('fecha');
        $cita = $this->cita($paciente, '08:00', fecha: '2035-03-06');

        $this->artisan('citas:enviar-recordatorios', ['--fecha' => '2035-03-06'])->expectsOutputToContain('Recordatorios para el 06/03/2035: 1 avisos enviados')->assertExitCode(0);
        $this->assertTrue($cita->fresh()->recordatorio_enviado);

        foreach (['06/03/2035', '2035-02-31', 'mañana', '2035-13-01'] as $mala) {
            $this->artisan('citas:enviar-recordatorios', ['--fecha' => $mala])->expectsOutputToContain('La fecha no es válida')->assertExitCode(1);
        }
    }

    #[Test]
    public function test_esta_programado_una_vez_al_dia_a_las_18_horas_de_la_paz_sin_solaparse(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'citas:enviar-recordatorios'));

        $this->assertNotNull($evento, 'El comando debe estar programado en routes/console.php.');
        $this->assertSame('0 18 * * *', $evento->expression);
        $this->assertSame('America/La_Paz', (string) $evento->timezone);
        $this->assertTrue($evento->withoutOverlapping);
    }

    #[Test]
    public function test_el_servicio_expone_los_metodos_del_diagrama(): void
    {
        $servicio = app(RecordatorioService::class);
        $paciente = $this->paciente('diagrama');
        $this->cita($paciente, '08:00');

        $this->assertCount(1, $servicio->citasParaRecordar(Carbon::parse(self::MANANA))->where('paciente_id', $paciente->id));
        $this->assertNull($servicio->verificarNotificaciones($paciente));
        $paciente->recibe_recordatorios = false;
        $this->assertSame(RecordatorioService::OMITIDO_SIN_NOTIFICACIONES, $servicio->verificarNotificaciones($paciente));
        $paciente->correo = null;
        $paciente->recibe_recordatorios = true;
        $this->assertSame(RecordatorioService::OMITIDO_SIN_CORREO, $servicio->verificarNotificaciones($paciente));
        $paciente->estado = false;
        $this->assertSame(RecordatorioService::OMITIDO_INACTIVO, $servicio->verificarNotificaciones($paciente));
    }

    #[Test]
    public function test_en_local_el_mailer_por_defecto_es_log_y_el_correo_queda_en_el_log(): void
    {
        $this->assertSame('log', config('mail.mailers.log.transport'));
        $this->assertContains(config('mail.default'), ['log', 'array'], 'En local y en las pruebas el correo no sale a ningún proveedor.');
    }

    // ---------- Envío manual de un recordatorio (extra aprobado por el equipo) ----------

    private function recepcionista(): Usuario
    {
        return $this->crearUsuario('t-rec-manual', [Rol::RECEPCIONISTA]);
    }

    #[Test]
    public function test_manual_la_recepcion_envia_el_recordatorio_de_una_cita_lo_marca_y_lo_registra(): void
    {
        Mail::fake();
        $recepcion = $this->recepcionista();
        $cita = $this->cita($this->paciente('man1'), '09:00');

        $this->actingAs($recepcion)->from('/control-citas')->post(route('citas.recordatorio', $cita))
            ->assertRedirect('/control-citas')->assertSessionHas('exito', 'Recordatorio enviado a t***@p***.test.');

        Mail::assertSent(RecordatorioCitaMail::class, 1);
        Mail::assertSent(RecordatorioCitaMail::class, fn ($m) => $m->hasTo('t-rec-man1@prueba.test') && $m->citas->pluck('codigo')->all() === [$cita->codigo]);
        $this->assertTrue($cita->fresh()->recordatorio_enviado);
        $registro = DB::table('bitacora')->where('accion', 'RECORDATORIO_MANUAL')->latest('id')->first();
        $this->assertSame($recepcion->id, $registro->usuario_id);
        $this->assertStringContainsString($cita->codigo, $registro->detalle);
        $this->assertStringContainsString($cita->paciente->codigo, $registro->detalle);
        $this->assertStringContainsString('t-rec-manual', $registro->detalle);
        $this->assertStringNotContainsString('prueba.test', $registro->detalle, 'La bitácora no guarda el correo.');
    }

    #[Test]
    public function test_manual_el_administrador_hereda_el_permiso_de_recepcion(): void
    {
        Mail::fake();
        $admin = $this->crearUsuario('t-rec-adm', [Rol::ADMINISTRADOR]);
        $cita = $this->cita($this->paciente('man2'), '09:00');

        $this->actingAs($admin)->post(route('citas.recordatorio', $cita))->assertSessionHas('exito');

        Mail::assertSent(RecordatorioCitaMail::class, 1);
    }

    #[Test]
    public function test_manual_el_correo_dice_de_hoy_o_de_mañana_segun_la_fecha_de_la_cita(): void
    {
        Mail::fake();
        $recepcion = $this->recepcionista();
        $hoy = $this->cita($this->paciente('man3'), '15:00', fecha: '2035-03-04');
        $manana = $this->cita($this->paciente('man4'), '09:00');
        $lejos = $this->cita($this->paciente('man5'), '09:00', fecha: '2035-03-09');

        foreach ([$hoy, $manana, $lejos] as $cita) {
            $this->actingAs($recepcion)->post(route('citas.recordatorio', $cita))->assertSessionHas('exito');
        }

        Mail::assertSent(RecordatorioCitaMail::class, fn ($m) => $m->citas->first()->id === $hoy->id && str_contains($m->render(), 'de hoy,'));
        Mail::assertSent(RecordatorioCitaMail::class, fn ($m) => $m->citas->first()->id === $manana->id && str_contains($m->render(), 'de mañana,'));
        Mail::assertSent(RecordatorioCitaMail::class, fn ($m) => $m->citas->first()->id === $lejos->id && str_contains($m->render(), 'del día') && ! str_contains($m->render(), 'de mañana'));
    }

    #[Test]
    public function test_manual_el_comando_de_las_18_horas_no_repite_el_aviso_de_la_cita_ya_enviada(): void
    {
        Mail::fake();
        $cita = $this->cita($this->paciente('man6'), '09:00');
        $this->actingAs($this->recepcionista())->post(route('citas.recordatorio', $cita))->assertSessionHas('exito');

        $this->ejecutar()->assertExitCode(0);

        Mail::assertSent(RecordatorioCitaMail::class, fn ($m) => $m->hasTo('t-rec-man6@prueba.test'));
        Mail::assertSent(RecordatorioCitaMail::class, 1);
    }

    #[Test]
    public function test_manual_si_ya_se_envio_antes_pide_confirmar_y_con_confirmacion_lo_reenvia(): void
    {
        Mail::fake();
        $recepcion = $this->recepcionista();
        $cita = $this->cita($this->paciente('man7'), '09:00', extra: ['recordatorio_enviado' => true]);

        $this->actingAs($recepcion)->post(route('citas.recordatorio', $cita))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'ya se le envió el recordatorio antes'));
        Mail::assertNothingSent();

        $this->post(route('citas.recordatorio', $cita), ['reenviar' => '1'])->assertSessionHas('exito');
        Mail::assertSent(RecordatorioCitaMail::class, 1);
        $this->assertTrue($cita->fresh()->recordatorio_enviado);
    }

    #[Test]
    public function test_manual_no_se_envia_si_el_paciente_no_tiene_correo_esta_inactivo_o_desactivo_los_recordatorios(): void
    {
        Mail::fake();
        $recepcion = $this->recepcionista();
        $casos = [
            'sin correo' => [['correo' => null], 'el paciente no tiene correo'],
            'sin avisos' => [['recibe_recordatorios' => false], 'el paciente tiene las notificaciones desactivadas'],
            'inactivo' => [['estado' => false], 'el paciente está inactivo'],
        ];

        $hora = 8;
        foreach ($casos as $nombre => [$datos, $motivo]) {
            $cita = $this->cita($this->paciente('man8'.md5($nombre), $datos), sprintf('%02d:00', $hora++));
            $this->actingAs($recepcion)->post(route('citas.recordatorio', $cita))->assertSessionHas('error', "No se envió: {$motivo}.");
            $this->assertFalse($cita->fresh()->recordatorio_enviado, $nombre);
        }
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('bitacora', ['accion' => 'RECORDATORIO_MANUAL', 'usuario_id' => $recepcion->id]);
    }

    #[Test]
    public function test_manual_no_se_envia_de_una_cita_cancelada_atendida_con_inasistencia_o_pasada(): void
    {
        Mail::fake();
        $recepcion = $this->recepcionista();
        $paciente = $this->paciente('man9');
        $citas = [
            'cancelada' => $this->cita($paciente, '08:00', estado: Cita::CANCELADA),
            'atendida' => $this->cita($paciente, '08:30', estado: Cita::ATENDIDA),
            'inasistencia' => $this->cita($paciente, '09:00', estado: Cita::INASISTENCIA),
        ];
        foreach ($citas as $cita) {
            $this->actingAs($recepcion)->post(route('citas.recordatorio', $cita))
                ->assertSessionHas('error', fn ($m) => str_starts_with($m, 'No se envió: la cita está ') && str_contains($m, 'Programadas o Confirmadas'));
        }

        $pasada = $this->cita($paciente, '08:00', fecha: '2035-03-04'); // son las 10:00 del 04/03/2035
        $this->post(route('citas.recordatorio', $pasada))->assertSessionHas('error', 'No se envió: la cita ya pasó (solo se recuerdan citas que todavía no empezaron).');

        Mail::assertNothingSent();
    }

    #[Test]
    public function test_manual_si_el_correo_falla_se_avisa_sin_detalles_tecnicos_y_la_cita_no_se_marca(): void
    {
        config(['mail.default' => 'brevo', 'mail.mailers.brevo' => ['transport' => 'brevo'], 'services.brevo.key' => '']);
        $recepcion = $this->recepcionista();
        $cita = $this->cita($this->paciente('man10'), '09:00');

        $this->actingAs($recepcion)->post(route('citas.recordatorio', $cita))
            ->assertSessionHas('error', 'No se pudo enviar el recordatorio en este momento. Inténtelo de nuevo en unos minutos.')
            ->assertSessionMissing('exito');

        $this->assertFalse($cita->fresh()->recordatorio_enviado);
        $this->assertDatabaseHas('bitacora', ['accion' => 'RECORDATORIO_FALLIDO', 'usuario_id' => $recepcion->id]);
        $this->assertStringNotContainsString('BREVO', session('error') ?? '');
    }

    #[Test]
    public function test_manual_el_medico_y_el_paciente_reciben_403_y_sin_sesion_va_al_login(): void
    {
        Mail::fake();
        $cita = $this->cita($this->paciente('man11'), '09:00');

        $this->post(route('citas.recordatorio', $cita))->assertRedirect(route('login'));
        foreach ([Rol::MEDICO, Rol::PACIENTE] as $rol) {
            $usuario = $this->crearUsuario('t-rec-'.mb_strtolower(substr($rol, 0, 3)).'-x', [$rol]);
            $this->actingAs($usuario)->post(route('citas.recordatorio', $cita))->assertForbidden();
        }

        Mail::assertNothingSent();
        $this->assertFalse($cita->fresh()->recordatorio_enviado);
    }

    #[Test]
    public function test_manual_tiene_limite_de_10_envios_por_minuto(): void
    {
        Mail::fake();
        $recepcion = $this->recepcionista();
        $cita = $this->cita($this->paciente('man12'), '09:00');

        for ($i = 1; $i <= 10; $i++) {
            $this->actingAs($recepcion)->post(route('citas.recordatorio', $cita), ['reenviar' => '1'])->assertRedirect();
        }
        $this->post(route('citas.recordatorio', $cita), ['reenviar' => '1'])->assertStatus(429);
    }

    #[Test]
    public function test_manual_el_boton_solo_lo_ve_la_recepcion_y_solo_en_citas_elegibles(): void
    {
        $recepcion = $this->recepcionista();
        $medicoUsuario = $this->crearUsuario('t-rec-medvista', [Rol::MEDICO]);
        $elegible = $this->cita($this->paciente('man13'), '09:00');
        $enviada = $this->cita($this->paciente('man14'), '10:30', extra: ['recordatorio_enviado' => true]);
        $cancelada = $this->cita($this->paciente('man15'), '11:00', estado: Cita::CANCELADA);
        $pasada = $this->cita($this->paciente('man16'), '08:00', fecha: '2035-03-04');

        $control = $this->actingAs($recepcion)->get(route('control.index', ['fecha' => '2035-03-05']))->assertOk()
            ->assertSee(route('citas.recordatorio', $elegible), false)->assertSee('Enviar recordatorio')
            ->assertSee(route('citas.recordatorio', $enviada), false)->assertSee('Reenviar recordatorio')
            ->assertSee('ya se le envió el recordatorio antes', false);
        $this->assertStringNotContainsString(route('citas.recordatorio', $cancelada), $control->getContent());
        $this->get(route('control.index', ['fecha' => '2035-03-04']))->assertOk()->assertDontSee(route('citas.recordatorio', $pasada), false);

        $this->get(route('citas.index', ['q' => $elegible->codigo]))->assertOk()->assertSee(route('citas.recordatorio', $elegible), false);

        $this->actingAs($medicoUsuario)->get(route('control.index', ['fecha' => '2035-03-05']))->assertOk()->assertDontSee('Enviar recordatorio');
    }

    #[Test]
    public function test_manual_el_correo_se_enmascara_para_la_pantalla(): void
    {
        $this->assertSame('m***@c***.test', RecordatorioService::enmascarar('maria.rojas@correo.test'));
        $this->assertSame('a***@g***.com', RecordatorioService::enmascarar('ana@gmail.com'));
        $this->assertSame('j***@e***.bo', RecordatorioService::enmascarar('juan@empresa.com.bo'));
    }

    /** Versión en texto plano del correo. */
    private function textoPlano(RecordatorioCitaMail $correo): string
    {
        $correo->build();

        return view('gestioncitas::CU22_EnviarRecordatoriosCitas.correo-recordatorio-texto', $correo->buildViewData())->render();
    }
}
