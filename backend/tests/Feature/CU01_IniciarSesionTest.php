<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\SeguridadUsuarios\CU01_IniciarSesion\LoginService;
use App\Support\PanelPrincipal;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/** CU-01: Iniciar sesión. */
class CU01_IniciarSesionTest extends SistemaTestCase
{
    /** Caso 1: credenciales válidas → ingresa al panel y ve el menú de su rol. */
    public function test_iniciar_sesion_con_credenciales_validas(): void
    {
        $usuario = $this->crearUsuario('t-recepcion', [Rol::RECEPCIONISTA], 'Clave1234');

        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'Clave1234'])
            ->assertRedirect('/panel');

        $this->assertAuthenticatedAs($usuario);
        $this->assertNotNull($usuario->fresh()->ultimo_acceso);
        $this->assertDatabaseHas('bitacora', ['accion' => 'LOGIN', 'usuario_id' => $usuario->id]);

        $this->get('/panel')->assertOk()->assertSee('Bienvenido, t-recepcion')->assertSee('Recepcionista');
    }

    /** Caso 2: contraseña incorrecta → mensaje genérico y no ingresa. */
    public function test_iniciar_sesion_con_contrasena_incorrecta(): void
    {
        $usuario = $this->crearUsuario('t-clave-mala', [Rol::PACIENTE], 'Clave1234');

        $this->from('/login')->post('/login', ['identificador' => $usuario->correo, 'password' => 'otra-clave'])
            ->assertRedirect('/login')
            ->assertSessionHas('error', 'Credenciales incorrectas.');

        $this->assertGuest();
        $this->assertDatabaseHas('bitacora', ['accion' => 'LOGIN_FALLIDO', 'usuario_id' => $usuario->id]);
    }

    /** Caso 3: 4 intentos fallidos en un minuto con el mismo correo → bloqueo temporal de ese correo. */
    public function test_superar_el_limite_de_intentos(): void
    {
        $usuario = $this->crearUsuario('t-bloqueo', [Rol::PACIENTE], 'Clave1234');

        foreach (range(1, 3) as $intento) {
            $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'mala'.$intento])
                ->assertSessionHas('error', 'Credenciales incorrectas.');
        }

        // El cuarto intento queda bloqueado, aunque la contraseña sea la correcta.
        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'Clave1234'])
            ->assertSessionHas('error');
        $this->assertStringContainsString('Demasiados intentos', session('error'));
        $this->assertGuest();
        $this->assertDatabaseHas('bitacora', ['accion' => 'LOGIN_BLOQUEADO', 'usuario_id' => $usuario->id]);
    }

    /** Caso 4: abrir una página interna sin sesión → redirige al inicio de sesión. */
    public function test_ingresar_a_una_pagina_interna_sin_sesion(): void
    {
        $this->get('/panel')->assertRedirect('/login');
    }

    public function test_ingresa_con_el_nombre_de_usuario_sin_distinguir_mayusculas(): void
    {
        $this->crearUsuario('t-por-usuario', [Rol::MEDICO], 'Clave1234');

        $this->post('/login', ['identificador' => 'T-Por-Usuario', 'password' => 'Clave1234'])->assertRedirect('/panel');
        $this->assertAuthenticated();
    }

    public function test_cuenta_inactiva_recibe_acceso_no_habilitado(): void
    {
        $usuario = $this->crearUsuario('t-inactivo', [Rol::PACIENTE], 'Clave1234', activo: false);

        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'Clave1234'])
            ->assertSessionHas('error', 'El acceso no está habilitado.');

        $this->assertGuest();
        $this->assertDatabaseHas('bitacora', ['accion' => 'LOGIN_RECHAZADO', 'usuario_id' => $usuario->id]);
    }

    public function test_una_cuenta_inexistente_recibe_el_mismo_mensaje_generico(): void
    {
        $this->post('/login', ['identificador' => 't-no-existe@prueba.test', 'password' => 'lo-que-sea'])
            ->assertSessionHas('error', 'Credenciales incorrectas.');
    }

    public function test_el_limite_cuenta_igual_entrando_con_correo_o_con_usuario(): void
    {
        $usuario = $this->crearUsuario('t-mixto', [Rol::PACIENTE], 'Clave1234');

        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'mala1']);
        $this->post('/login', ['identificador' => 't-mixto', 'password' => 'mala2']);
        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'mala3']);

        $this->post('/login', ['identificador' => 't-mixto', 'password' => 'Clave1234']);
        $this->assertStringContainsString('Demasiados intentos', session('error'));
        $this->assertGuest();
    }

    public function test_un_ingreso_correcto_reinicia_el_contador_de_intentos(): void
    {
        $usuario = $this->crearUsuario('t-reinicio', [Rol::PACIENTE], 'Clave1234');

        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'mala']);
        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'Clave1234']);

        $this->assertAuthenticated();
        $this->assertSame(0, RateLimiter::attempts(LoginService::llave($usuario->correo)));
    }

    public function test_regenera_la_sesion_al_ingresar(): void
    {
        $usuario = $this->crearUsuario('t-sesion', [Rol::PACIENTE], 'Clave1234');

        $this->get('/login');
        $antes = session()->getId();
        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'Clave1234']);

        $this->assertNotSame($antes, session()->getId());
    }

    public function test_la_contrasena_se_guarda_con_hash_bcrypt(): void
    {
        $usuario = $this->crearUsuario('t-hash', [Rol::PACIENTE], 'Clave1234');

        $this->assertStringStartsWith('$2y$', $usuario->fresh()->password_hash);
        $this->assertTrue(Hash::check('Clave1234', $usuario->fresh()->password_hash));
    }

    public function test_el_formulario_valida_los_campos_obligatorios(): void
    {
        $this->from('/login')->post('/login', ['identificador' => '', 'password' => ''])
            ->assertSessionHasErrors(['identificador' => 'Ingrese su correo o nombre de usuario.', 'password' => 'Ingrese su contraseña.']);
    }

    public function test_el_panel_muestra_solo_las_funciones_de_los_roles_del_usuario(): void
    {
        $recepcion = $this->crearUsuario('t-panel-r', [Rol::RECEPCIONISTA]);
        $paciente = $this->crearUsuario('t-panel-p', [Rol::PACIENTE]);
        $medicoPaciente = $this->crearUsuario('t-panel-mp', [Rol::MEDICO, Rol::PACIENTE]);

        $nombres = fn (Usuario $u) => collect(PanelPrincipal::paquetes($u, soloImplementadas: false))
            ->flatMap(fn ($p) => collect($p['funciones'])->pluck('nombre'))->all();

        $this->assertContains('Pacientes', $nombres($recepcion));
        $this->assertContains('Registrar cita', $nombres($recepcion));
        $this->assertNotContains('Usuarios y roles', $nombres($recepcion));
        $this->assertNotContains('Registrar cita', $nombres($paciente));
        $this->assertContains('Reservar cita', $nombres($paciente));
        // La unión de roles: el médico que también es paciente ve las opciones del paciente.
        $this->assertSame($nombres($paciente), $nombres($medicoPaciente));
    }

    public function test_un_usuario_con_sesion_que_abre_el_login_va_al_panel(): void
    {
        $usuario = $this->crearUsuario('t-ya-dentro', [Rol::PACIENTE]);

        $this->actingAs($usuario)->get('/login')->assertRedirect('/panel');
    }
}
