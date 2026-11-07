<?php

namespace Tests\Feature;

use App\Modules\GestionCitas\Compartido\PagoException;
use App\Modules\GestionCitas\Compartido\PagoStripeService;
use App\Modules\GestionCitas\Compartido\SesionPago;
use App\Support\Dinero;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;

/**
 * Base técnica del pago con tarjeta (CU-09 y CU-11): formato y conversión de montos (Dinero) y el cliente de la API REST
 * de Stripe (PagoStripeService). Todo con Http::fake(): ninguna prueba llama a Stripe de verdad.
 */
class PagosTest extends SistemaTestCase
{
    #[Test]
    public function el_dinero_se_muestra_en_bolivianos_con_coma_decimal(): void
    {
        $this->assertSame('Bs 100,00', Dinero::formato(100));
        $this->assertSame('Bs 100,50', Dinero::formato('100.5'));
        $this->assertSame('Bs 1.250,50', Dinero::formato(1250.5));
        $this->assertSame('Bs 0,00', Dinero::formato(null));
    }

    #[Test]
    public function con_bob_y_tipo_de_cambio_1_el_monto_se_envia_tal_cual_en_centavos(): void
    {
        config(['vidasalud.tipo_cambio' => 1, 'vidasalud.stripe_moneda' => 'bob']);

        $this->assertSame(10000, Dinero::aCentavosStripe('100.00'));
        $this->assertSame(4050, Dinero::aCentavosStripe(40.5));
        $this->assertSame('bob', Dinero::monedaStripe());
    }

    #[Test]
    public function con_usd_y_tipo_de_cambio_696_el_monto_se_convierte(): void
    {
        config(['vidasalud.tipo_cambio' => 6.96, 'vidasalud.stripe_moneda' => 'USD']);

        $this->assertSame(1437, Dinero::aCentavosStripe(100)); // 100 / 6,96 = 14,37
        $this->assertSame(14.37, Dinero::aMonedaStripe(100));
        $this->assertSame('usd', Dinero::monedaStripe());
    }

    #[Test]
    public function un_tipo_de_cambio_invalido_da_un_error_claro(): void
    {
        config(['vidasalud.tipo_cambio' => 0]);

        $this->expectException(InvalidArgumentException::class);
        Dinero::aCentavosStripe(100);
    }

    #[Test]
    public function el_servicio_solo_esta_configurado_con_la_clave_secreta(): void
    {
        $this->assertFalse(app(PagoStripeService::class)->configurado());

        $this->activarStripe();
        $this->assertTrue(app(PagoStripeService::class)->configurado());
    }

    #[Test]
    public function sin_clave_ninguna_operacion_sale_a_internet(): void
    {
        Http::fake();
        $servicio = app(PagoStripeService::class);

        foreach ([
            fn () => $servicio->crearSesion('100.00', 'Cita', 'Detalle', [], 'https://x.test/ok', 'https://x.test/no'),
            fn () => $servicio->obtenerSesion('cs_1'),
            fn () => $servicio->capturar('pi_1'),
            fn () => $servicio->cancelar('pi_1'),
        ] as $operacion) {
            try {
                $operacion();
                $this->fail('Sin STRIPE_SECRET no debe haber pago con tarjeta.');
            } catch (PagoException $e) {
                $this->assertSame(PagoException::MENSAJE_NO_DISPONIBLE, $e->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    #[Test]
    public function crear_la_sesion_envia_checkout_alojado_con_captura_manual_y_el_tiempo_maximo(): void
    {
        $this->activarStripe();
        $this->fingirStripe();

        $sesion = app(PagoStripeService::class)->crearSesion('100.00', 'Cita médica · Medicina', 'Dr. Prueba · 10/11/2026 09:00', ['token' => 'abc', 'fecha' => '2026-11-10'], 'https://x.test/ok?session_id={CHECKOUT_SESSION_ID}', 'https://x.test/no');

        $this->assertSame('cs_test_123', $sesion->id);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_test_123', $sesion->url);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer sk_test_clave_de_prueba')
            && $r['mode'] === 'payment' && $r['locale'] === 'es'
            && $r['payment_intent_data']['capture_method'] === 'manual'
            && $r['payment_intent_data']['metadata']['token'] === 'abc' && $r['metadata']['fecha'] === '2026-11-10'
            && (int) $r['line_items'][0]['price_data']['unit_amount'] === 10000
            && $r['line_items'][0]['price_data']['product_data']['name'] === 'Cita médica · Medicina'
            && ! str_contains($r->body(), 'card'));
    }

    #[Test]
    public function los_errores_de_stripe_se_convierten_en_un_aviso_claro_sin_detalles(): void
    {
        $this->activarStripe();
        $error = ['error' => ['type' => 'invalid_request_error', 'message' => 'No such thing: sk_test_clave_de_prueba']];
        Http::fake(['api.stripe.com/*' => Http::sequence()->push($error, 401)->push($error, 402)->push($error, 429)->push($error, 500)]);
        $servicio = app(PagoStripeService::class);

        foreach ([401, 402, 429, 500] as $http) {
            try {
                $servicio->capturar('pi_1');
                $this->fail("Un error HTTP {$http} de Stripe debe lanzar PagoException.");
            } catch (PagoException $e) {
                $this->assertSame(PagoException::MENSAJE_GENERAL, $e->getMessage());
                $this->assertStringNotContainsString('sk_test', $e->getMessage());
            }
        }
        $this->assertCount(4, Http::recorded());
    }

    #[Test]
    public function si_no_hay_conexion_con_stripe_el_aviso_es_el_mismo(): void
    {
        $this->activarStripe();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->expectExceptionObject(PagoException::general());
        app(PagoStripeService::class)->obtenerSesion('cs_1');
    }

    #[Test]
    public function una_sesion_sin_url_de_pago_se_trata_como_error(): void
    {
        $this->activarStripe();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_sin_url'])]);

        $this->expectExceptionObject(PagoException::general());
        app(PagoStripeService::class)->crearSesion('100.00', 'Cita', '', [], 'https://x.test/ok', 'https://x.test/no');
    }

    #[Test]
    public function la_captura_solo_vale_si_stripe_responde_succeeded(): void
    {
        $this->activarStripe();
        Http::fake(['api.stripe.com/*' => Http::sequence()
            ->push(['id' => 'pi_1', 'status' => 'succeeded'])
            ->push(['id' => 'pi_1', 'status' => 'requires_action'])]);
        $servicio = app(PagoStripeService::class);

        $servicio->capturar('pi_1');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/payment_intents/pi_1/capture' && $r->method() === 'POST');

        $this->expectExceptionObject(PagoException::general());
        $servicio->capturar('pi_1');
    }

    #[Test]
    public function cancelar_la_autorizacion_llama_al_endpoint_de_cancelacion(): void
    {
        $this->activarStripe();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'pi_9', 'status' => 'canceled'])]);

        app(PagoStripeService::class)->cancelar('pi_9');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/payment_intents/pi_9/cancel' && $r->method() === 'POST');
    }

    #[Test]
    public function consultar_la_sesion_lee_el_estado_del_pago_en_el_servidor(): void
    {
        $this->activarStripe();
        Http::fake(['api.stripe.com/*' => Http::response([
            'id' => 'cs_77', 'metadata' => ['token' => 't1', 'medico_id' => 5],
            'payment_intent' => ['id' => 'pi_77', 'status' => 'requires_capture', 'amount' => 15000, 'currency' => 'BOB'],
        ])]);

        $sesion = app(PagoStripeService::class)->obtenerSesion('cs_77');

        $this->assertTrue($sesion->autorizado());
        $this->assertSame('pi_77', $sesion->paymentIntentId);
        $this->assertSame(15000, $sesion->centavos);
        $this->assertSame('bob', $sesion->moneda);
        $this->assertSame(['token' => 't1', 'medico_id' => '5'], $sesion->metadata);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.stripe.com/v1/checkout/sessions/cs_77') && $r->method() === 'GET' && str_contains(urldecode($r->url()), 'expand[0]=payment_intent'));
    }

    #[Test]
    public function un_pago_que_no_esta_en_requires_capture_no_se_considera_autorizado(): void
    {
        foreach (['requires_payment_method', 'requires_action', 'canceled', 'succeeded', null] as $estado) {
            $sesion = SesionPago::desdeRespuesta(['id' => 'cs_1', 'payment_intent' => ['id' => 'pi_1', 'status' => $estado, 'amount' => 100, 'currency' => 'bob']]);
            $this->assertFalse($sesion->autorizado(), 'Estado: '.var_export($estado, true));
        }

        $sinIntento = SesionPago::desdeRespuesta(['id' => 'cs_1', 'payment_intent' => null]);
        $this->assertFalse($sinIntento->autorizado());
        $this->assertNull($sinIntento->paymentIntentId);
    }
}
