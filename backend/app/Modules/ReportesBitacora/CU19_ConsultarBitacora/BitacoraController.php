<?php

namespace App\Modules\ReportesBitacora\CU19_ConsultarBitacora;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * CU-19: Consultar bitácora (paquete P6 Reportes y Bitácora, RF19). Solo el Administrador.
 *
 * RF19: «Registra y permite consultar los eventos relevantes del sistema (quién hizo qué y cuándo)». El
 * documento todavía no trae la ficha detallada de este caso de uso (es del Ciclo II); este es el flujo propuesto:
 *
 * Flujo de suceso:
 *  1. El administrador accede a la sección «Bitácora».                      -> index() (la ruta exige el rol Administrador)
 *  2. El sistema muestra los registros del más reciente al más antiguo, con
 *     fecha y hora, usuario, acción, entidad, detalle e IP, de 25 en 25.   -> ConsultaBitacoraService::consultar()
 *  3. Opcionalmente filtra por usuario, entidad, acción, rango de fechas o
 *     texto del detalle o la IP.                                            -> BitacoraRequest + formulario de index.blade.php
 *  4. El sistema muestra los registros que coinciden y conserva los filtros
 *     al paginar.                                                           -> index() (withQueryString)
 *
 * Excepciones:
 *  - Sin registros con esos filtros: «No hay registros con esos filtros.»
 *  - Un usuario sin el rol Administrador recibe «Acceso denegado» (403) y el intento queda registrado
 *    (ACCESO_DENEGADO); sin sesión se redirige al inicio de sesión.
 *  - La bitácora es de solo lectura: no hay pantallas ni rutas para editar o borrar registros.
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
