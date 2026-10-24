<?php

namespace App\Http\Controllers;

use App\Support\Recursos;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Sirve frontend/assets (css, js, img) sin enlaces simbólicos ni Node: funciona igual en Windows.
 * La ruta no pasa por el grupo «web» (no abre sesión ni envía cookies).
 */
class RecursoController extends Controller
{
    public function __invoke(Request $request, string $ruta): BinaryFileResponse
    {
        $archivo = Recursos::resolver($ruta);
        abort_if($archivo === null, 404);

        $respuesta = response()->file($archivo, [
            'Content-Type' => Recursos::tipo($archivo),
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Con ?v=<fecha de modificación> el navegador puede guardarlo un año; sin versión revalida con ETag.
        $respuesta->headers->set(
            'Cache-Control',
            $request->query->has('v') ? 'public, max-age=31536000, immutable' : 'public, max-age=0, must-revalidate'
        );
        $respuesta->setAutoEtag();
        $respuesta->isNotModified($request);

        return $respuesta;
    }
}
