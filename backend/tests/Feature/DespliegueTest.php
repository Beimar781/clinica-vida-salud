<?php

namespace Tests\Feature;

use App\Models\Cita;
use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\AdministradorSeeder;
use Database\Seeders\DatabaseSeeder;
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
        'bloqueos_agenda', 'pacientes', 'historias_clinicas', 'antecedentes_externos', 'citas',
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
