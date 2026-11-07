<?php

namespace App\Modules\ReportesBitacora\CU19_ConsultarBitacora;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * CU-19: Consultar bitácora (paquete P6 Reportes y Bitácora, RF19). Solo el Administrador.
 *
 * Precondición: el usuario autenticado tiene el rol Administrador.
 *
 * Flujo de suceso (ficha 4.3 del documento):
 *  1. El administrador accede a «Bitácora».                                -> index() (la ruta exige el rol Administrador)
 *  2. Indica, si lo desea, el usuario, la acción y el rango de fechas.     -> BitacoraRequest + formulario de index.blade.php
 *  3. El sistema consulta los eventos que cumplen los filtros.             -> ConsultaBitacoraService::consultar()
 *  4. El sistema muestra los eventos ordenados del más reciente al más
 *     antiguo, con el usuario, la acción, el detalle, la fecha y la hora.  -> index.blade.php (de 25 en 25, con los filtros conservados al paginar;
 *                                                                            además se muestran la entidad y la IP, y se puede filtrar por entidad o texto)
 *
 * Excepciones:
 *  - No hay eventos que cumplan los filtros: «No hay registros».
 *  - El rango de fechas es inválido (hasta anterior a desde): el sistema lo indica y no consulta (BitacoraRequest).
 *  - Un usuario sin el rol Administrador recibe «Acceso denegado» (403) y el intento queda registrado (ACCESO_DENEGADO);
 *    sin sesión se redirige al inicio de sesión.
 *  - Los registros no pueden modificarse ni eliminarse: no hay botones, pantallas ni rutas para editar o borrar y el trigger de la
 *    base de datos rechaza cualquier UPDATE o DELETE.
 *
 * @CU CU-19 @RF RF19 @CI CIBitacora @CC CCBitacora @CE CEBitacora, CEUsuario
 */
class BitacoraController extends Controller
{
    public function __construct(private readonly ConsultaBitacoraService $consulta) {}

    /**
     * Pasos 1 a 4: muestra la bitácora con los filtros aplicados.
     *
     * @CI CIBitacora (mostrarListado, filtrar) @CC CCBitacora (consultar) @CE CEBitacora (buscar)
     */
    public function index(BitacoraRequest $request): View
    {
        $filtros = $request->filtros();

        return view('reportesbitacora::CU19_ConsultarBitacora.index', [
            'registros' => $this->consulta->consultar($filtros),
            'filtros' => $filtros,
            'opciones' => $this->consulta->opciones(),
        ]);
    }
}
