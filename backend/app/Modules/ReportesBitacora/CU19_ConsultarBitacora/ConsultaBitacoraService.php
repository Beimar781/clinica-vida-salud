<?php

namespace App\Modules\ReportesBitacora\CU19_ConsultarBitacora;

use App\Models\Bitacora;
use App\Models\Usuario;
use App\Support\Texto;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Arma la consulta de la bitácora: del registro más reciente al más antiguo, con filtros por usuario,
 * entidad, acción, rango de fechas (el «hasta» incluye todo el día) y texto en el detalle o la IP.
 * Solo lee: no modifica nada.
 *
 * @CU CU-19 @RF RF19 @CI CIBitacora @CC CCBitacora (consultar) @CE CEBitacora
 */
class ConsultaBitacoraService
{
    public const POR_PAGINA = 25;

    /**
     * @param  array<string, string>  $filtros  usuario, entidad, accion, desde, hasta y q (todos opcionales)
     */
    public function consultar(array $filtros): LengthAwarePaginator
    {
        $usuario = $filtros['usuario'] ?? null;
        $texto = Texto::normalizar($filtros['q'] ?? '');
        $patron = Texto::patronLike($texto);

        return Bitacora::with('usuario:id,nombre_usuario')
            ->when($usuario === 'sin', fn ($q) => $q->whereNull('usuario_id'))
            ->when($usuario !== null && ctype_digit($usuario), fn ($q) => $q->where('usuario_id', (int) $usuario))
            ->when(filled($filtros['entidad'] ?? null), fn ($q) => $q->where('entidad', $filtros['entidad']))
            ->when(filled($filtros['accion'] ?? null), fn ($q) => $q->where('accion', $filtros['accion']))
            ->when(filled($filtros['desde'] ?? null), fn ($q) => $q->where('fecha_hora', '>=', Carbon::parse($filtros['desde'])->startOfDay()))
            ->when(filled($filtros['hasta'] ?? null), fn ($q) => $q->where('fecha_hora', '<', Carbon::parse($filtros['hasta'])->addDay()->startOfDay()))
            ->when($texto !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw(Texto::sqlSinTildes('detalle').' like ?', [$patron])
                ->orWhereRaw('lower(ip) like ?', [$patron])))
            ->orderByDesc('fecha_hora')->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * Valores para las listas del formulario: los usuarios y las entidades que aparecen en la bitácora y las
     * acciones registradas con su texto legible.
     *
     * @return array{usuarios: Collection<int, Usuario>, entidades: Collection<int, string>, acciones: Collection<string, string>}
     */
    public function opciones(): array
    {
        $idsUsuarios = Bitacora::query()->whereNotNull('usuario_id')->distinct()->pluck('usuario_id');

        return [
            'usuarios' => Usuario::whereIn('id', $idsUsuarios)->orderBy('nombre_usuario')->get(['id', 'nombre_usuario']),
            'entidades' => Bitacora::query()->whereNotNull('entidad')->distinct()->orderBy('entidad')->pluck('entidad'),
            'acciones' => Bitacora::query()->distinct()->pluck('accion')
                ->mapWithKeys(fn (string $accion) => [$accion => AccionesBitacora::etiqueta($accion)])
                ->sort(),
        ];
    }
}
