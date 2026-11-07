<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\AdministradorSeeder;
use Database\Seeders\CitaSeeder;
use Database\Seeders\ConsultaSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\EspecialidadSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Preparación del despliegue en Railway: los seeders pueden correr en cada arranque sin duplicar nada,
 * el administrador sale de ADMIN_PASSWORD y el sitio detrás del proxy genera enlaces con https.
 * Corre sobre la base ya sembrada; cada prueba se revierte al terminar.
 */
class DespliegueTest extends SistemaTestCase
{
    private const TABLAS = [
        'roles', 'usuarios', 'rol_usuario', 'especialidades', 'medicos', 'medico_especialidad', 'horarios_atencion',
        'bloqueos_agenda', 'pacientes', 'historias_clinicas', 'antecedentes_externos', 'citas', 'consultas',
    ];

    /** @return array<string, int> */
    private function conteos(): array
    {
        return collect(self::TABLAS)->mapWithKeys(fn ($tabla) => [$tabla => DB::table($tabla)->count()])->all();
    }

    public function test_los_seeders_se_pueden_ejecutar_otra_vez_sin_duplicar_nada(): void
    {
        $antes = $this->conteos();

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($antes, $this->conteos());
    }

    public function test_los_datos_base_traen_tipo_y_precio_de_cada_especialidad_y_servicio(): void
    {
        $esperado = [
            'medicina general' => ['CONSULTA', 100], 'pediatria' => ['CONSULTA', 150], 'ginecologia' => ['CONSULTA', 150],
            'odontologia' => ['CONSULTA', 150], 'cardiologia' => ['CONSULTA', 150],
            'laboratorio' => ['LABORATORIO', 80], 'enfermeria' => ['ENFERMERIA', 40],
        ];

        foreach ($esperado as $normalizado => [$tipo, $precio]) {
            $fila = DB::table('especialidades')->where('nombre_normalizado', $normalizado)->first();
            $this->assertNotNull($fila, "Falta {$normalizado}");
            $this->assertSame($tipo, $fila->tipo, $normalizado);
            $this->assertEquals($precio, $fila->precio, $normalizado);
        }
    }

    public function test_los_datos_base_no_pisan_lo_que_el_administrador_cambio_en_cu04(): void
    {
        DB::table('especialidades')->where('nombre_normalizado', 'pediatria')->update(['precio' => 175, 'estado' => false]);
        DB::table('especialidades')->where('nombre_normalizado', 'odontologia')->update(['precio' => 0]); // como la dejó la migración

        $this->seed(EspecialidadSeeder::class);

        $pediatria = DB::table('especialidades')->where('nombre_normalizado', 'pediatria')->first();
        $this->assertEquals(175, $pediatria->precio, 'Un precio ya definido no se pisa.');
        $this->assertFalse((bool) $pediatria->estado, 'Una especialidad desactivada sigue desactivada.');
        $this->assertEquals(150, DB::table('especialidades')->where('nombre_normalizado', 'odontologia')->value('precio'), 'Una fila con precio 0 recibe el precio base.');
    }

    public function test_las_citas_con_monto_cero_copian_el_precio_de_su_especialidad_y_las_demas_no_cambian(): void
    {
        $medicina = Especialidad::where('nombre_normalizado', 'medicina general')->firstOrFail();
        $medico = $this->crearMedico([], [$medicina]);
        $paciente = $this->crearPaciente();
        $antigua = $this->crearCita($medico, $paciente, $medicina, '2031-03-03', '08:00', '08:30');
        $conMonto = $this->crearCita($medico, $paciente, $medicina, '2031-03-03', '09:00', '09:30');
        $conMonto->update(['monto' => 90]);
        $this->assertEquals(0, $antigua->fresh()->monto);

        $this->seed(EspecialidadSeeder::class);

        $this->assertEquals(100, $antigua->fresh()->monto);
        $this->assertEquals(90, $conMonto->fresh()->monto, 'Una cita con monto guardado no cambia aunque el precio actual sea otro.');
    }

    public function test_la_demostracion_cubre_los_estados_y_pagos_del_v30(): void
    {
        $demo = fn () => DB::table('citas')->where('codigo', 'not like', 'T-%');

        $this->assertTrue($demo()->where('estado', 'PROGRAMADA')->where('metodo_pago', 'EFECTIVO')->where('estado_pago', 'PENDIENTE')->exists(), 'Programada con efectivo pendiente');
        $this->assertTrue($demo()->where('estado', 'CONFIRMADA')->where('metodo_pago', 'TARJETA')->where('estado_pago', 'PAGADO')->whereNotNull('referencia_pago')->whereNotNull('fecha_pago')->exists(), 'Confirmada con tarjeta pagada');
        $this->assertTrue($demo()->where('estado', 'INASISTENCIA')->where('estado_pago', 'PAGADO')->exists(), 'Inasistencia pagada');
        $this->assertTrue($demo()->where('estado', 'INASISTENCIA')->where('estado_pago', 'PENDIENTE')->exists(), 'Inasistencia sin pago');
        $this->assertTrue($demo()->where('estado', 'ATENDIDA')->exists(), 'Atendida');
        $this->assertTrue($demo()->where('estado', 'CANCELADA')->where('estado_pago', 'PAGADO')->exists(), 'Cancelada con pago');
        $this->assertSame(0, $demo()->where('monto', 0)->count(), 'Todas las citas de demostración llevan su monto.');
        $this->assertSame(0, $demo()->where('estado_pago', 'PAGADO')->whereNull('fecha_pago')->count(), 'Todo pago registrado tiene su fecha.');
        $this->assertSame(0, $demo()->where('metodo_pago', 'EFECTIVO')->whereNotNull('referencia_pago')->count(), 'Solo la tarjeta lleva referencia.');
    }

    public function test_la_demostracion_trae_servicios_con_su_profesional_una_ficha_sin_cuenta_y_dias_sin_atencion_con_autor(): void
    {
        foreach (['laboratorio', 'enfermeria'] as $servicio) {
            $profesionales = DB::table('medico_especialidad')
                ->join('especialidades', 'especialidades.id', '=', 'medico_especialidad.especialidad_id')
                ->join('medicos', 'medicos.id', '=', 'medico_especialidad.medico_id')
                ->where('especialidades.nombre_normalizado', $servicio)->where('medicos.estado', true)->pluck('medicos.id');

            $this->assertNotEmpty($profesionales, "El servicio {$servicio} necesita un profesional.");
            $this->assertTrue(DB::table('horarios_atencion')->whereIn('medico_id', $profesionales)->where('estado', true)->exists(), "El profesional de {$servicio} necesita horarios.");
        }

        $this->assertTrue(DB::table('pacientes')->whereNull('usuario_id')->exists(), 'Hace falta una ficha sin cuenta para probar CU-20.');
        $this->assertSame(0, DB::table('bloqueos_agenda')->whereNull('registrado_por')->count());
        $this->assertGreaterThan(0, DB::table('bloqueos_agenda')->join('usuarios', 'usuarios.id', '=', 'bloqueos_agenda.registrado_por')->count());
    }

    public function test_la_demostracion_trae_consultas_para_las_citas_atendidas_y_antecedentes_de_otros_hospitales(): void
    {
        // Cada cita Atendida de la demostración tiene su consulta, en la historia clínica de su paciente y con su médico.
        $atendidas = DB::table('citas')->where('codigo', 'not like', 'T-%')->where('estado', 'ATENDIDA')->pluck('id');
        $this->assertNotEmpty($atendidas);
        foreach ($atendidas as $citaId) {
            $consulta = DB::table('consultas')->where('cita_id', $citaId)->first();
            $this->assertNotNull($consulta, 'Falta la consulta de la cita atendida '.$citaId);
            $cita = DB::table('citas')->find($citaId);
            $this->assertSame($cita->medico_id, $consulta->medico_id);
            $this->assertSame($cita->paciente_id, DB::table('historias_clinicas')->where('id', $consulta->historia_clinica_id)->value('paciente_id'));
            $this->assertNotSame('', trim($consulta->diagnostico));
        }

        // Antecedentes de otros hospitales de más de un paciente.
        $this->assertGreaterThanOrEqual(2, DB::table('antecedentes_externos')->distinct('historia_clinica_id')->count('historia_clinica_id'));
    }

    public function test_las_consultas_de_la_demostracion_no_se_duplican_al_sembrar_otra_vez(): void
    {
        $consultas = DB::table('consultas')->count();
        $antecedentes = DB::table('antecedentes_externos')->count();

        $this->seed(ConsultaSeeder::class);
        $this->seed(ConsultaSeeder::class);

        $this->assertSame($consultas, DB::table('consultas')->count());
        $this->assertSame($antecedentes, DB::table('antecedentes_externos')->count());
    }

    public function test_la_cita_de_manana_de_la_demostracion_es_para_una_paciente_que_recibe_recordatorios(): void
    {
        // Se vuelve a sembrar esa cita para que su fecha sea «mañana» sin importar cuándo se sembró la base.
        $cita = Cita::where('motivo', 'Curación de herida')->firstOrFail();
        $cita->delete();

        $this->seed(CitaSeeder::class);

        $cita = Cita::with('paciente')->where('motivo', 'Curación de herida')->firstOrFail();
        $this->assertSame(Carbon::tomorrow()->toDateString(), $cita->fecha->toDateString());
        $this->assertSame(Cita::PROGRAMADA, $cita->estado);
        $this->assertTrue($cita->paciente->recibe_recordatorios);
        $this->assertNotEmpty($cita->paciente->correo);
        $this->assertFalse($cita->recordatorio_enviado);
    }

    public function test_sin_admin_password_el_administrador_usa_la_contrasena_de_demostracion(): void
    {
        Usuario::where('nombre_usuario', 'admin')->update(['nombre_usuario' => 't-admin-viejo', 'correo' => 't-admin-viejo@prueba.test']);
        config(['vidasalud.admin_password' => null]);

        $this->seed(AdministradorSeeder::class);

        $admin = Usuario::where('nombre_usuario', 'admin')->firstOrFail();
        $this->assertTrue(Hash::check('Demo1234*', $admin->password_hash));
        $this->assertSame(['Administrador'], $admin->nombresRoles()->all());
    }

    public function test_con_admin_password_vacia_tambien_usa_la_contrasena_de_demostracion(): void
    {
        Usuario::where('nombre_usuario', 'admin')->update(['nombre_usuario' => 't-admin-viejo', 'correo' => 't-admin-viejo@prueba.test']);
        config(['vidasalud.admin_password' => '']);

        $this->seed(AdministradorSeeder::class);

        $this->assertTrue(Hash::check('Demo1234*', Usuario::where('nombre_usuario', 'admin')->firstOrFail()->password_hash));
    }

    public function test_las_cuentas_de_demostracion_usan_la_contrasena_de_demostracion(): void
    {
        $cuentas = ['recepcion', 'cmontano', 'lsoliz', 'pvargas', 'mrojas', 'jperez'];

        foreach (Usuario::whereIn('nombre_usuario', $cuentas)->get() as $usuario) {
            $this->assertTrue(Hash::check('Demo1234*', $usuario->password_hash), "{$usuario->nombre_usuario} debe usar Demo1234*");
        }
        $this->assertSame(count($cuentas), Usuario::whereIn('nombre_usuario', $cuentas)->count());
    }

    public function test_el_seeder_deja_a_admin_con_un_solo_rol_aunque_antes_tuviera_dos(): void
    {
        $admin = Usuario::where('nombre_usuario', 'admin')->firstOrFail();
        $admin->roles()->sync(Rol::whereIn('nombre', [Rol::ADMINISTRADOR, Rol::RECEPCIONISTA])->pluck('id')->all());
        $this->assertCount(2, $admin->fresh()->roles);

        $this->seed(AdministradorSeeder::class);
        $this->seed(AdministradorSeeder::class);

        $this->assertSame(['Administrador'], $admin->fresh()->nombresRoles()->all());
        $this->assertTrue($admin->fresh()->tieneRol(Rol::RECEPCIONISTA));
    }

    public function test_el_seeder_no_pisa_la_contrasena_del_administrador_existente(): void
    {
        $admin = Usuario::where('nombre_usuario', 'admin')->firstOrFail();
        $admin->update(['password_hash' => 'ClaveCambiada1']);
        config(['vidasalud.admin_password' => 'OtraClaveDistinta9']);

        $this->seed(AdministradorSeeder::class);

        $admin->refresh();
        $this->assertTrue(Hash::check('ClaveCambiada1', $admin->password_hash));
        $this->assertSame(['Administrador'], $admin->nombresRoles()->all());
        $this->assertSame(1, Usuario::where('nombre_usuario', 'admin')->count());
    }

    public function test_el_administrador_nuevo_usa_admin_password_y_admin_correo(): void
    {
        // El administrador de demostración se aparta (tiene bitácora y citas asociadas) para que el seeder cree uno nuevo.
        Usuario::where('nombre_usuario', 'admin')->update(['nombre_usuario' => 't-admin-viejo', 'correo' => 't-admin-viejo@prueba.test']);
        config(['vidasalud.admin_password' => 'ClaveInicial123', 'vidasalud.admin_correo' => 'directora@clinica.test']);

        $this->seed(AdministradorSeeder::class);

        $admin = Usuario::where('nombre_usuario', 'admin')->firstOrFail();
        $this->assertSame('directora@clinica.test', $admin->correo);
        $this->assertTrue(Hash::check('ClaveInicial123', $admin->password_hash));
        $this->assertTrue($admin->estado);
        $this->assertSame(['Administrador'], $admin->nombresRoles()->all());
    }

    public function test_sin_sembrar_demo_no_se_cargan_los_datos_de_demostracion(): void
    {
        DB::table('consultas')->delete(); // las consultas dependen de las citas (llave foránea)
        Cita::query()->delete();
        config(['vidasalud.sembrar_demo' => false]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Cita::count(), 'Con SEMBRAR_DEMO desactivado no deben crearse citas de demostración.');
    }

    public function test_detras_del_proxy_los_formularios_y_enlaces_salen_con_https(): void
    {
        $cabeceras = ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'clinica.example.test', 'X-Forwarded-Port' => '443'];

        $this->withHeaders($cabeceras)->get('/login')
            ->assertOk()
            ->assertSee('action="https://clinica.example.test/login"', false)
            ->assertSee('href="https://clinica.example.test/olvido-clave"', false);
    }

    public function test_los_assets_se_piden_con_ruta_relativa_para_no_mezclar_http_y_https(): void
    {
        $this->get('/login')->assertOk()->assertSee('href="/assets/css/vidasalud.css?v=', false);
    }

    public function test_la_ruta_de_salud_responde_para_el_monitoreo(): void
    {
        $this->get('/up')->assertOk();
    }
}
