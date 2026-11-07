<?php

namespace App\Modules\ReportesBitacora\CU18_GenerarReportesConIA;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resumen en lenguaje natural de un reporte con IA generativa (Google Gemini, API generateContent por HTTP).
 *
 * Privacidad (RNF09): al modelo solo se le envían el tipo de reporte, el período y los TOTALES calculados por el sistema
 * (cantidades, ingresos y el total de cada médico y de cada especialidad). Nunca se envían nombres, CI, correos, teléfonos ni datos
 * clínicos de los pacientes: el contenido sale de `ReporteService::totalesParaIA()`, que solo arma cifras.
 *
 * Si no hay clave, la IA tarda más de 20 s, responde con error o devuelve un texto vacío, `resumir()` devuelve null y el reporte
 * se muestra sin resumen (el error queda en el log; nunca se lanza una excepción hacia la pantalla).
 *
 * @CU CU-18 @RF RF18 @CI CIReportes (generarConIA) @CC CCReportes (resumirConIA) @CE CECita (totalesPorPeriodo)
 */
class ResumenIAService
{
    public const AVISO_SIN_RESUMEN = 'No se pudo generar el resumen con IA. El reporte se muestra completo, sin resumen.';

    public const TIEMPO_MAXIMO = 20;

    public const MODELO_POR_DEFECTO = 'gemini-3.5-flash-lite';

    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const INSTRUCCIONES = 'Eres el asistente de la Clínica Vida Salud. Recibirás en JSON el tipo, el período y los totales de un reporte '
        .'administrativo de citas médicas. Redacta, en español y con un tono profesional y claro, un resumen de 3 a 5 oraciones para la '
        .'dirección de la clínica. Usa SOLO las cifras que aparecen en el JSON: no inventes datos, no calcules cifras nuevas ni porcentajes '
        .'que no estén y no menciones información que no esté allí. Copia el período tal como aparece en el JSON. Los montos están en bolivianos (Bs). No des consejos médicos ni '
        .'menciones datos personales. Responde únicamente con el texto del resumen, sin títulos ni listas.';

    /** ¿Hay clave configurada? Sin ella la modalidad con IA no intenta ninguna petición. */
    public function disponible(): bool
    {
        return (string) config('services.ia.gemini_key') !== '';
    }

    /**
     * Pide el resumen al modelo.
     *
     * @CC CCReportes (resumirConIA)
     *
     * @param  array<string, mixed>  $totales  solo cifras agregadas (ver ReporteService::totalesParaIA)
     * @return string|null el resumen, o null si no se pudo generar
     */
    public function resumir(array $totales): ?string
    {
        if (! $this->disponible()) {
            Log::warning('CU-18: no hay GEMINI_API_KEY; el reporte se muestra sin resumen de IA.');

            return null;
        }

        $modelo = (string) config('services.ia.modelo', self::MODELO_POR_DEFECTO);

        try {
            $respuesta = $this->pedir($modelo, $totales);

            // Google retira modelos con el tiempo (404 «ya no está disponible»): si el configurado ya no existe, se reintenta una sola vez con
            // el modelo por defecto en lugar de dejar la IA caída hasta que alguien corrija la variable IA_MODELO.
            if ($respuesta->status() === 404 && $modelo !== self::MODELO_POR_DEFECTO) {
                Log::warning("CU-18: el modelo de IA «{$modelo}» ya no está disponible (404); se reintenta con «".self::MODELO_POR_DEFECTO.'». Actualice IA_MODELO.');
                $respuesta = $this->pedir(self::MODELO_POR_DEFECTO, $totales);
            }

            if ($respuesta->failed()) {
                Log::error('CU-18: la IA respondió con error '.$respuesta->status().': '.mb_substr((string) $respuesta->body(), 0, 300));

                return null;
            }

            $partes = $respuesta->json('candidates.0.content.parts') ?? [];
            $texto = trim(collect($partes)->pluck('text')->filter()->implode(' '));
        } catch (Throwable $e) {
            Log::error('CU-18: no se pudo consultar la IA: '.get_class($e).': '.mb_substr($e->getMessage(), 0, 300));

            return null;
        }

        if ($texto === '') {
            Log::error('CU-18: la IA respondió sin texto (bloqueada o vacía).');

            return null;
        }

        return mb_substr($texto, 0, 1500);
    }

    /** @param  array<string, mixed>  $totales */
    private function pedir(string $modelo, array $totales): Response
    {
        // La clave va en el encabezado (no en la URL) para que nunca aparezca en un mensaje de error ni en el log.
        return Http::timeout(self::TIEMPO_MAXIMO)
            ->connectTimeout(10)
            ->acceptJson()
            ->withHeaders(['x-goog-api-key' => (string) config('services.ia.gemini_key')])
            ->post(self::URL.rawurlencode($modelo).':generateContent', [
                'systemInstruction' => ['parts' => [['text' => self::INSTRUCCIONES]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => json_encode($totales, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]]],
                'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 1000],
            ]);
    }
}
