<?php

namespace Tests\Feature;

use App\Support\Recursos;
use Tests\TestCase;

/**
 * Los assets del frontend (frontend/assets) se sirven por la ruta «assets»: tipos correctos,
 * cabeceras de caché y protección contra path traversal.
 */
class RecursosTest extends TestCase
{
    public function test_sirve_el_css_con_su_tipo_y_cache_largo_si_trae_version(): void
    {
        $url = Recursos::url('css/vidasalud.css');
        $this->assertStringContainsString('?v=', $url);

        $respuesta = $this->get($url)->assertOk();
        $this->assertStringStartsWith('text/css', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('immutable', $respuesta->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
    }

    public function test_sin_version_revalida_con_etag_y_responde_304(): void
    {
        $primera = $this->get('/assets/js/vidasalud.js')->assertOk();
        $this->assertStringContainsString('must-revalidate', $primera->headers->get('Cache-Control'));

        $this->get('/assets/js/vidasalud.js', ['If-None-Match' => $primera->headers->get('ETag')])->assertStatus(304);
    }

    public function test_no_abre_sesion_ni_envia_cookies(): void
    {
        $this->get('/assets/css/vidasalud.css')->assertOk()->assertCookieMissing(config('session.cookie'));
    }

    public function test_rechaza_path_traversal_y_archivos_ajenos_al_frontend(): void
    {
        foreach ([
            '/assets/../../backend/.env',
            '/assets/%2e%2e/%2e%2e/backend/.env',
            '/assets/css/..%2f..%2f..%2fbackend%2f.env',
            '/assets/css%5c..%5c..%5cbackend%5c.env',
            '/assets/css/modulos/../vidasalud.css/../../../views/inicio.blade.php',
            '/assets/css/%00.css',
        ] as $ruta) {
            $this->get($ruta)->assertNotFound();
        }

        $this->assertNull(Recursos::resolver('../backend/.env'));
        $this->assertNull(Recursos::resolver('/etc/passwd'));
        $this->assertNull(Recursos::resolver('css/../../views/inicio.blade.php'));
        $this->assertNull(Recursos::resolver('C:/Windows/win.ini'));
    }

    public function test_solo_sirve_extensiones_de_assets(): void
    {
        $this->assertNull(Recursos::resolver('../views/inicio.blade.php'));
        $this->get('/assets/css')->assertNotFound();
        $this->get('/assets/css/no-existe.css')->assertNotFound();
    }
}
