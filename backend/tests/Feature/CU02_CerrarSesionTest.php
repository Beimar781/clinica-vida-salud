<?php

namespace Tests\Feature;

use App\Models\Rol;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

/** CU-02: Cerrar sesión. */
class CU02_CerrarSesionTest extends SistemaTestCase
{
    /** Caso 1: abrir una página interna sin sesión → redirige al inicio de sesión. */
    public function test_ingresar_a_una_pagina_interna_sin_sesion(): void
    {
        $this->get('/panel')->assertRedirect('/login');
    }

    /** Caso 2: cerrar sesión y volver atrás → la sesión termina y no se muestran páginas internas. */
    public function test_cerrar_sesion_y_volver_atras_en_el_navegador(): void
    {
        $usuario = $this->crearUsuario('t-salida', [Rol::RECEPCIONISTA]);

        // Las páginas internas no se guardan en el navegador: al volver atrás debe pedirlas de nuevo.
        $this->actingAs($usuario)->get('/panel')->assertOk();
        $this->assertStringContainsString('no-store', $this->get('/panel')->headers->get('Cache-Control'));

        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertDatabaseHas('bitacora', ['accion' => 'LOGOUT', 'usuario_id' => $usuario->id]);
        $this->get('/panel')->assertRedirect('/login');
    }

    public function test_cerrar_sesion_invalida_la_sesion_y_regenera_el_token(): void
    {
        $usuario = $this->crearUsuario('t-token', [Rol::PACIENTE]);

        $this->actingAs($usuario)->get('/panel');
        $sesion = session()->getId();
        $token = csrf_token();

        $this->post('/logout');

        $this->assertNotSame($sesion, session()->getId());
        $this->assertNotSame($token, csrf_token());
    }

    public function test_si_la_sesion_ya_expiro_solo_redirige_al_login(): void
    {
        $this->post('/logout')->assertRedirect('/login');

        $respuesta = app(ExceptionHandler::class)->render(Request::create('/logout', 'POST'), new TokenMismatchException);
        $this->assertSame('/login', parse_url($respuesta->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_el_login_y_el_panel_no_se_guardan_en_cache(): void
    {
        $this->assertStringContainsString('no-store', $this->get('/login')->headers->get('Cache-Control'));
    }
}
