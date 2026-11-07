<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Rol;
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

    /** Versión en texto plano del correo. */
    private function textoPlano(RecordatorioCitaMail $correo): string
    {
        $correo->build();

        return view('gestioncitas::CU22_EnviarRecordatoriosCitas.correo-recordatorio-texto', $correo->buildViewData())->render();
    }
}
