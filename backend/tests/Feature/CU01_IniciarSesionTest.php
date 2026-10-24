<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use App\Modules\SeguridadUsuarios\CU01_IniciarSesion\LoginService;
use App\Modules\SeguridadUsuarios\CU01_IniciarSesion\RecuperarClaveService;
use App\Support\PanelPrincipal;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\Exception\TransportException;

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

        $this->get('/panel')->assertOk()->assertSee('Bienvenido, T-recepcion')->assertSee('Recepcionista');
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

    // ---------- Bloqueo de 60 segundos tras 3 intentos fallidos (regla de CU-01: la cuenta queda bloqueada 60 segundos) ----------

    private function fallar(string $identificador, int $veces): void
    {
        foreach (range(1, $veces) as $n) {
            $this->post('/login', ['identificador' => $identificador, 'password' => 'mala'.$n])
                ->assertSessionHas('error', 'Credenciales incorrectas.');
        }
    }

    public function test_el_bloqueo_dura_60_segundos_y_luego_se_puede_ingresar(): void
    {
        $usuario = $this->crearUsuario('t-bloqueo-60', [Rol::PACIENTE], 'Clave1234');
        Carbon::setTestNow('2030-03-04 10:00:00');

        $this->fallar($usuario->correo, 3);

        // Con la contraseña correcta tampoco entra mientras dura el bloqueo, ni con el nombre de usuario.
        foreach ([$usuario->correo, 't-bloqueo-60'] as $identificador) {
            $this->post('/login', ['identificador' => $identificador, 'password' => 'Clave1234']);
            $this->assertStringContainsString('Demasiados intentos fallidos. Espere 60 segundos', session('error'));
            $this->assertGuest();
        }

        Carbon::setTestNow('2030-03-04 10:00:30');
        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'Clave1234']);
        $this->assertStringContainsString('Espere 30 segundos', session('error'));
        $this->assertGuest();

        // Pasados los 60 segundos ya se puede ingresar y queda el rastro del bloqueo en la bitácora.
        Carbon::setTestNow('2030-03-04 10:01:01');
        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'Clave1234'])->assertRedirect('/panel');
        $this->assertAuthenticatedAs($usuario);
        $this->assertDatabaseHas('bitacora', ['accion' => 'LOGIN_BLOQUEADO', 'usuario_id' => $usuario->id]);
        $this->assertSame(0, RateLimiter::attempts(LoginService::llave($usuario->correo)));
    }

    public function test_los_60_segundos_se_cuentan_desde_el_tercer_fallo(): void
    {
        $usuario = $this->crearUsuario('t-bloqueo-tercero', [Rol::PACIENTE], 'Clave1234');

        Carbon::setTestNow('2030-03-04 10:00:00');
        $this->fallar($usuario->correo, 1);
        Carbon::setTestNow('2030-03-04 10:00:40');
        $this->fallar($usuario->correo, 2);

        // Pasaron 61 s desde el primer fallo, pero solo 21 s desde el tercero: sigue bloqueada.
        Carbon::setTestNow('2030-03-04 10:01:01');
        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'Clave1234']);
        $this->assertStringContainsString('Demasiados intentos', session('error'));
        $this->assertGuest();

        Carbon::setTestNow('2030-03-04 10:01:41');
        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'Clave1234'])->assertRedirect('/panel');
    }

    public function test_el_bloqueo_es_por_cuenta_y_no_afecta_a_otra(): void
    {
        $bloqueada = $this->crearUsuario('t-bloq-a', [Rol::PACIENTE], 'Clave1234');
        $libre = $this->crearUsuario('t-bloq-b', [Rol::PACIENTE], 'Clave1234');

        $this->fallar($bloqueada->correo, 3);

        $this->post('/login', ['identificador' => $libre->correo, 'password' => 'Clave1234'])->assertRedirect('/panel');
        $this->assertAuthenticatedAs($libre);
    }

    // ---------- Flujo alternativo A3: recuperar contraseña por enlace al correo ----------

    private function tokenPara(Usuario $usuario): string
    {
        return Password::broker()->createToken($usuario);
    }

    public function test_el_login_ofrece_el_enlace_para_recuperar_la_contrasena(): void
    {
        $this->get('/login')->assertOk()->assertSee('¿Olvidaste tu contraseña?')->assertSee(route('password.request'), false);
        $this->get('/olvido-clave')->assertOk()->assertSee('Recuperar contraseña')->assertSee('Enviar enlace');
    }

    public function test_el_login_tiene_el_boton_para_mostrar_la_contrasena(): void
    {
        $this->get('/login')->assertOk()
            ->assertSee('data-mostrar-clave', false)
            ->assertSee('aria-label="Mostrar contraseña"', false)
            ->assertSee('type="button"', false)
            ->assertSee('type="password" name="password"', false);
    }

    public function test_la_pantalla_de_contrasena_nueva_tiene_el_boton_en_sus_dos_campos(): void
    {
        $usuario = $this->crearUsuario('t-ojo-restablece', [Rol::PACIENTE]);
        $token = $this->tokenPara($usuario);

        $html = $this->get(route('password.reset', ['token' => $token, 'correo' => $usuario->correo]))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'data-mostrar-clave'));
        $this->assertStringContainsString('aria-controls="campo-password"', $html);
        $this->assertStringContainsString('aria-controls="campo-password_confirmation"', $html);
    }

    public function test_el_nombre_de_usuario_se_muestra_con_mayuscula_inicial_sin_cambiar_el_valor_guardado(): void
    {
        $usuario = $this->crearUsuario('t-minuscula', [Rol::RECEPCIONISTA], 'Clave1234');

        $this->post('/login', ['identificador' => 'T-MINUSCULA', 'password' => 'Clave1234'])->assertRedirect('/panel');
        $this->assertAuthenticatedAs($usuario);

        $this->get('/panel')->assertSee('Bienvenido, T-minuscula')->assertSee('<span><svg', false);
        $this->assertSame('t-minuscula', $usuario->fresh()->nombre_usuario);
        $this->assertSame('T-minuscula', $usuario->fresh()->nombre_mostrado);
    }

    public function test_solicitar_el_enlace_con_una_cuenta_activa_envia_el_correo(): void
    {
        Notification::fake();
        $usuario = $this->crearUsuario('t-olvido', [Rol::PACIENTE], 'Clave1234');

        $this->from('/olvido-clave')->post('/olvido-clave', ['correo' => $usuario->correo])
            ->assertRedirect('/olvido-clave')
            ->assertSessionHas('exito', 'Si el correo está registrado, te enviamos un enlace de recuperación.');

        Notification::assertSentTo($usuario, ResetPassword::class);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $usuario->correo]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'RECUPERACION_SOLICITADA', 'usuario_id' => $usuario->id]);
    }

    public function test_el_correo_se_reconoce_sin_distinguir_mayusculas(): void
    {
        Notification::fake();
        $usuario = $this->crearUsuario('t-olvido-may', [Rol::PACIENTE], 'Clave1234');

        $this->post('/olvido-clave', ['correo' => strtoupper($usuario->correo)]);

        Notification::assertSentTo($usuario, ResetPassword::class);
    }

    public function test_solicitar_con_un_correo_inexistente_responde_igual_y_no_envia_nada(): void
    {
        Notification::fake();
        $existente = $this->crearUsuario('t-olvido-igual', [Rol::PACIENTE], 'Clave1234');

        $this->post('/olvido-clave', ['correo' => $existente->correo]);
        $conCuenta = session('exito');
        $this->flushSession();
        $this->post('/olvido-clave', ['correo' => 't-no-existe@prueba.test'])
            ->assertSessionHas('exito', $conCuenta)
            ->assertSessionHasNoErrors();

        Notification::assertSentTimes(ResetPassword::class, 1);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 't-no-existe@prueba.test']);
    }

    public function test_solicitar_con_una_cuenta_inactiva_responde_igual_y_no_envia_nada(): void
    {
        Notification::fake();
        $inactivo = $this->crearUsuario('t-olvido-inactivo', [Rol::PACIENTE], 'Clave1234', activo: false);

        $this->post('/olvido-clave', ['correo' => $inactivo->correo])
            ->assertSessionHas('exito', 'Si el correo está registrado, te enviamos un enlace de recuperación.');

        Notification::assertNothingSent();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $inactivo->correo]);
    }

    public function test_el_formulario_de_olvido_valida_el_correo(): void
    {
        $this->from('/olvido-clave')->post('/olvido-clave', ['correo' => ''])
            ->assertSessionHasErrors(['correo' => 'Ingresa el correo de tu cuenta.']);
        $this->from('/olvido-clave')->post('/olvido-clave', ['correo' => 'no-es-un-correo'])
            ->assertSessionHasErrors(['correo' => 'Ingresa un correo válido.']);
    }

    public function test_restablecer_con_un_token_valido_cambia_la_contrasena_y_el_token_no_se_reutiliza(): void
    {
        $usuario = $this->crearUsuario('t-restablece', [Rol::PACIENTE], 'Clave1234');
        $token = $this->tokenPara($usuario);
        $datos = ['token' => $token, 'correo' => $usuario->correo, 'password' => 'NuevaClave99', 'password_confirmation' => 'NuevaClave99'];

        $this->get(route('password.reset', ['token' => $token, 'correo' => $usuario->correo]))
            ->assertOk()->assertSee('Contraseña nueva')->assertSee($usuario->correo);

        $this->post('/restablecer-clave', $datos)
            ->assertRedirect('/login')
            ->assertSessionHas('exito', 'Contraseña actualizada. Ya puedes iniciar sesión.');

        $this->assertStringStartsWith('$2y$', $usuario->fresh()->password_hash);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $usuario->correo]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'RECUPERACION_COMPLETADA', 'usuario_id' => $usuario->id]);

        // La clave anterior ya no sirve y la nueva sí.
        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'Clave1234'])
            ->assertSessionHas('error', 'Credenciales incorrectas.');
        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'NuevaClave99'])->assertRedirect('/panel');

        // El mismo enlace no se puede usar dos veces.
        $this->post('/logout');
        $this->post('/restablecer-clave', array_merge($datos, ['password' => 'OtraClave000', 'password_confirmation' => 'OtraClave000']))
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('error', RecuperarClaveService::MENSAJE_ENLACE_INVALIDO);
        $this->assertFalse(Hash::check('OtraClave000', $usuario->fresh()->password_hash));
    }

    public function test_un_token_invalido_no_permite_restablecer(): void
    {
        $usuario = $this->crearUsuario('t-token-malo', [Rol::PACIENTE], 'Clave1234');
        $this->tokenPara($usuario);

        $this->post('/restablecer-clave', ['token' => 'token-inventado', 'correo' => $usuario->correo, 'password' => 'NuevaClave99', 'password_confirmation' => 'NuevaClave99'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('error', RecuperarClaveService::MENSAJE_ENLACE_INVALIDO);
        $this->assertTrue(Hash::check('Clave1234', $usuario->fresh()->password_hash));

        $this->get(route('password.reset', ['token' => 'token-inventado', 'correo' => $usuario->correo]))
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('error', RecuperarClaveService::MENSAJE_ENLACE_INVALIDO);
    }

    public function test_un_enlace_vencido_a_los_60_minutos_ofrece_pedir_otro(): void
    {
        $usuario = $this->crearUsuario('t-token-vencido', [Rol::PACIENTE], 'Clave1234');
        $token = $this->tokenPara($usuario);

        Carbon::setTestNow(now()->addMinutes(59));
        $this->get(route('password.reset', ['token' => $token, 'correo' => $usuario->correo]))->assertOk();

        Carbon::setTestNow(now()->addMinutes(2));
        $this->post('/restablecer-clave', ['token' => $token, 'correo' => $usuario->correo, 'password' => 'NuevaClave99', 'password_confirmation' => 'NuevaClave99'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('error', RecuperarClaveService::MENSAJE_ENLACE_INVALIDO);
        $this->assertTrue(Hash::check('Clave1234', $usuario->fresh()->password_hash));

        $this->followingRedirects()->get(route('password.reset', ['token' => $token, 'correo' => $usuario->correo]))
            ->assertSee('Solicita uno nuevo')->assertSee('Enviar enlace');
    }

    public function test_la_contrasena_nueva_exige_8_caracteres_y_confirmacion(): void
    {
        $usuario = $this->crearUsuario('t-clave-corta', [Rol::PACIENTE], 'Clave1234');
        $token = $this->tokenPara($usuario);
        $base = ['token' => $token, 'correo' => $usuario->correo];

        $this->post('/restablecer-clave', $base + ['password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertSessionHasErrors(['password' => 'La contraseña debe tener al menos 8 caracteres.']);
        $this->post('/restablecer-clave', $base + ['password' => 'NuevaClave99', 'password_confirmation' => 'OtraDistinta1'])
            ->assertSessionHasErrors(['password' => 'La confirmación no coincide con la contraseña.']);
        $this->post('/restablecer-clave', $base + ['password' => 'NuevaClave99'])
            ->assertSessionHasErrors('password');

        // Nada cambió y el enlace sigue vigente para volver a intentarlo.
        $this->assertTrue(Hash::check('Clave1234', $usuario->fresh()->password_hash));
        $this->assertTrue(Password::tokenExists($usuario, $token));
    }

    public function test_restablecer_la_contrasena_limpia_el_bloqueo_por_intentos_fallidos(): void
    {
        $usuario = $this->crearUsuario('t-limpia-bloqueo', [Rol::PACIENTE], 'Clave1234');
        $this->fallar($usuario->correo, 3);
        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'Clave1234']);
        $this->assertStringContainsString('Demasiados intentos', session('error'));

        $this->post('/restablecer-clave', ['token' => $this->tokenPara($usuario), 'correo' => $usuario->correo, 'password' => 'NuevaClave99', 'password_confirmation' => 'NuevaClave99'])
            ->assertRedirect('/login');

        $this->assertSame(0, RateLimiter::attempts(LoginService::llave($usuario->correo)));
        $this->post('/login', ['identificador' => $usuario->correo, 'password' => 'NuevaClave99'])->assertRedirect('/panel');
    }

    public function test_una_cuenta_desactivada_despues_de_pedir_el_enlace_no_puede_restablecer(): void
    {
        $usuario = $this->crearUsuario('t-desactivada', [Rol::PACIENTE], 'Clave1234');
        $token = $this->tokenPara($usuario);
        $usuario->update(['estado' => false]);

        $this->post('/restablecer-clave', ['token' => $token, 'correo' => $usuario->correo, 'password' => 'NuevaClave99', 'password_confirmation' => 'NuevaClave99'])
            ->assertSessionHas('error', RecuperarClaveService::MENSAJE_ENLACE_INVALIDO);
        $this->assertTrue(Hash::check('Clave1234', $usuario->fresh()->password_hash));
    }

    public function test_la_solicitud_del_enlace_tiene_limite_de_5_por_minuto(): void
    {
        Notification::fake();

        // Abrir las pantallas no gasta el límite de los envíos.
        foreach (range(1, 8) as $n) {
            $this->get('/olvido-clave')->assertOk();
        }

        foreach (range(1, 5) as $n) {
            $this->post('/olvido-clave', ['correo' => "t-limite{$n}@prueba.test"])->assertSessionHas('exito');
        }

        $this->post('/olvido-clave', ['correo' => 't-limite6@prueba.test'])->assertStatus(429);
    }

    public function test_si_el_proveedor_de_correo_falla_se_avisa_sin_error_500(): void
    {
        config(['mail.default' => 'brevo', 'services.brevo.key' => 'clave-de-prueba']);
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'Key not found'], 401)]);
        Log::spy();
        $usuario = $this->crearUsuario('t-proveedor-falla', [Rol::PACIENTE], 'Clave1234');

        $this->from('/olvido-clave')->post('/olvido-clave', ['correo' => $usuario->correo])
            ->assertRedirect('/olvido-clave')
            ->assertSessionHas('error', 'No pudimos enviar el correo en este momento. Inténtalo de nuevo en unos minutos.');

        Log::shouldHaveReceived('error')->once();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $usuario->correo]);
        $this->assertDatabaseHas('bitacora', ['accion' => 'RECUPERACION_FALLIDA', 'usuario_id' => $usuario->id]);
    }

    public function test_el_correo_esta_en_espanol_y_trae_el_enlace(): void
    {
        $usuario = $this->crearUsuario('t-correo-es', [Rol::PACIENTE], 'Clave1234');

        $correo = (new ResetPassword('abc123'))->toMail($usuario);
        $html = (string) $correo->render();

        $this->assertSame('Recupera tu contraseña · Clínica Vida Salud', $correo->subject);
        $this->assertStringContainsString('Restablecer contraseña', $html);
        $this->assertStringContainsString('Clínica Vida Salud', $html);
        $this->assertStringContainsString('vence en 60 minutos', $html);
        $this->assertStringContainsString(route('password.reset', ['token' => 'abc123', 'correo' => $usuario->correo]), $html);
    }

    public function test_en_local_el_enlace_queda_en_una_linea_del_log(): void
    {
        $usuario = $this->crearUsuario('t-log-local', [Rol::PACIENTE], 'Clave1234');
        Log::spy();

        (new ResetPassword('abc123'))->toMail($usuario);
        Log::shouldNotHaveReceived('info');

        $this->app->detectEnvironment(fn () => 'local');
        (new ResetPassword('abc123'))->toMail($usuario);
        Log::shouldHaveReceived('info')->withArgs(fn ($mensaje) => str_starts_with($mensaje, 'ENLACE DE RECUPERACION: ') && str_contains($mensaje, 'abc123'))->once();
    }

    // ---------- BrevoTransport (correo de producción en Railway) ----------

    private function enviarPorBrevo(): void
    {
        Mail::mailer('brevo')->raw('Hola desde la clínica', function ($mensaje) {
            $mensaje->to('paciente@prueba.test', 'Paciente')->from('citas@clinicavidasalud.test', 'Clínica Vida Salud')->subject('Prueba');
        });
    }

    public function test_brevo_envia_el_correo_a_la_api_con_la_cabecera_api_key(): void
    {
        config(['services.brevo.key' => 'clave-de-prueba']);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => '<abc@brevo>'], 201)]);

        $this->enviarPorBrevo();

        Http::assertSent(function ($peticion) {
            return $peticion->url() === 'https://api.brevo.com/v3/smtp/email'
                && $peticion->hasHeader('api-key', 'clave-de-prueba')
                && $peticion['sender']['email'] === 'citas@clinicavidasalud.test'
                && $peticion['to'][0]['email'] === 'paciente@prueba.test'
                && $peticion['subject'] === 'Prueba'
                && str_contains($peticion['textContent'], 'Hola desde la clínica');
        });
    }

    public function test_brevo_lanza_error_si_la_api_responde_401(): void
    {
        config(['services.brevo.key' => 'clave-invalida']);
        Http::fake(['api.brevo.com/*' => Http::response(['message' => 'Key not found'], 401)]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Brevo respondió 401');

        $this->enviarPorBrevo();
    }

    public function test_brevo_lanza_error_si_falta_la_clave(): void
    {
        config(['services.brevo.key' => null]);
        Http::fake();

        try {
            $this->enviarPorBrevo();
            $this->fail('Debió lanzar TransportException');
        } catch (TransportException $e) {
            $this->assertStringContainsString('Falta BREVO_API_KEY', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
