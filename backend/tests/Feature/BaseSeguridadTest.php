<?php

namespace Tests\Feature;

use App\Modules\Seguridad\Models\Rol;
use App\Modules\Seguridad\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Base de seguridad: el modelo Usuario (password_hash, varios roles), el middleware «rol» y la
 * página de inicio pública. Los casos de uso de P1 tienen sus propias pruebas (CU01Test, ...).
 */
class BaseSeguridadTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'rol:Administrador'])->get('/_prueba/admin', fn () => 'ok');
        Route::middleware(['web', 'auth', 'rol:Administrador,Recepcionista'])->get('/_prueba/personal', fn () => 'ok');
        Route::middleware(['web', 'auth', 'rol:Paciente'])->get('/_prueba/paciente', fn () => 'ok');
    }

    public function test_la_pagina_de_inicio_es_publica(): void
    {
        $this->get('/')->assertOk()->assertSee('Clínica Vida Salud')->assertSee('Iniciar sesión');
    }

    public function test_el_usuario_guarda_la_contrasena_con_hash_en_password_hash(): void
    {
        $usuario = $this->crearUsuario('t-hash', [Rol::PACIENTE], 'Secreta123');

        $this->assertNotSame('Secreta123', $usuario->password_hash);
        $this->assertTrue(Hash::check('Secreta123', $usuario->getAuthPassword()));
        $this->assertArrayNotHasKey('password_hash', $usuario->toArray());
    }

    public function test_sin_sesion_las_paginas_internas_redirigen_al_login(): void
    {
        $this->get('/_prueba/admin')->assertRedirect('/login');
    }

    public function test_un_rol_sin_permiso_recibe_acceso_denegado(): void
    {
        $recepcionista = $this->crearUsuario('t-recep', [Rol::RECEPCIONISTA]);

        $this->actingAs($recepcionista)->get('/_prueba/admin')->assertForbidden();
        $this->assertDatabaseHas('bitacora', ['accion' => 'ACCESO_DENEGADO', 'usuario_id' => $recepcionista->id]);
    }

    public function test_basta_con_uno_de_los_roles_indicados(): void
    {
        $recepcionista = $this->crearUsuario('t-recep', [Rol::RECEPCIONISTA]);

        $this->actingAs($recepcionista)->get('/_prueba/personal')->assertOk();
    }

    public function test_los_permisos_son_la_union_de_los_roles(): void
    {
        $medicoPaciente = $this->crearUsuario('t-medpac', [Rol::MEDICO, Rol::PACIENTE]);

        $this->actingAs($medicoPaciente)->get('/_prueba/paciente')->assertOk();
        $this->actingAs($medicoPaciente)->get('/_prueba/admin')->assertForbidden();
        $this->assertTrue($medicoPaciente->tieneRol(Rol::MEDICO));
        $this->assertTrue($medicoPaciente->tieneRol(Rol::ADMINISTRADOR, Rol::PACIENTE));
        $this->assertFalse($medicoPaciente->tieneRol(Rol::ADMINISTRADOR));
    }

    /** @param list<string> $roles */
    private function crearUsuario(string $nombre, array $roles, string $clave = 'Prueba1234'): Usuario
    {
        $usuario = Usuario::create([
            'nombre_usuario' => $nombre,
            'correo' => $nombre.'@prueba.test',
            'password_hash' => $clave,
        ]);
        $usuario->roles()->attach(Rol::whereIn('nombre', $roles)->pluck('id'));

        return $usuario;
    }
}
