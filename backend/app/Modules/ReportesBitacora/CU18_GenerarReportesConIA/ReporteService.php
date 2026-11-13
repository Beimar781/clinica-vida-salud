<?php

namespace App\Modules\ReportesBitacora\CU18_GenerarReportesConIA;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Support\Dinero;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Cálculo de los ocho reportes de CU-18: el detalle (una fila por cita o por paciente) y los totales (por estado, ingresos por método
 * de pago, por médico y por especialidad). Todas las consultas son de solo lectura y parametrizadas, con los agregados calculados en
 * PostgreSQL (una sola consulta de totales y una por cada desglose) para cumplir RNF03.
 *
 * Definiciones: «Programadas» son las citas en estado PROGRAMADA y «Confirmadas» las CONFIRMADA (se muestran por separado);
 * los ingresos son las citas con estado_pago = PAGADO (en el reporte de ingresos, por la fecha del pago `fecha_pago`; en los demás, de las
 * citas listadas). Los pacientes registrados se filtran por `historias_clinicas.fecha_apertura` (la tabla pacientes no guarda fecha de registro).
 *
 * @CU CU-18 @RF RF18 @CI CIReportes @CC CCReportes (generar, calcularTotales, exportar) @CE CECita (citasPorPeriodo, totalesPorPeriodo), CEPaciente (listarRegistrados), CEMedico (listar)
 */
class ReporteService
{
    public const TIPOS = [
        'citas_fecha' => 'Citas de una fecha',
        'por_medico' => 'Citas por médico',
        'por_especialidad' => 'Citas por especialidad',
        'por_estado' => 'Citas por estado',
        'pacientes' => 'Pacientes registrados',
        'periodo' => 'Atendidas, canceladas e inasistencias de un período',
        'ingresos' => 'Ingresos por método de pago',
        'agenda_medico' => 'Agenda diaria del médico',
    ];

    /** Reportes de un solo día (usan solo «Fecha»): el rango se fija en ese día. */
    public const UN_DIA = ['citas_fecha', 'agenda_medico'];

    /** Máximo de días de un rango (inclusive). */
    public const MAX_DIAS = 366;

    /** Filas del detalle que se muestran en pantalla (el CSV las trae todas y el PDF hasta LIMITE_PDF). */
    public const LIMITE_PANTALLA = 200;

    /** dompdf usa unos 56 MB más 0,3 MB por fila: con el límite de memoria de 256 MB del Dockerfile, 300 filas (~144 MB, ~2,4 s) es seguro y 1000 no. */
    public const LIMITE_PDF = 300;

    public const MENSAJE_VACIO = 'No hay citas en el período seleccionado.';

    public const MENSAJE_VACIO_PACIENTES = 'No hay pacientes registrados en el período seleccionado.';

    private const ESTADOS = [
        Cita::PROGRAMADA => 'Programada', Cita::CONFIRMADA => 'Confirmada', Cita::ATENDIDA => 'Atendida',
        Cita::CANCELADA => 'Cancelada', Cita::INASISTENCIA => 'Inasistencia',
    ];

    /** @return array<string, string> estados de cita para el filtro */
    public static function estados(): array
    {
        return self::ESTADOS;
    }

    /**
     * Genera el reporte: detalle (hasta $limite filas; null = todas) y totales.
     *
     * @CC CCReportes (generar, calcularTotales) @CE CECita (citasPorPeriodo, totalesPorPeriodo), CEPaciente (listarRegistrados)
     *
     * @param  array{tipo: string, desde: string, hasta: string, medico: ?int, especialidad: ?int, estado: ?string}  $f
     * @return array<string, mixed>
     */
    public function generar(array $f, ?int $limite = self::LIMITE_PANTALLA): array
    {
        $reporte = [
            'tipo' => $f['tipo'],
            'titulo' => self::TIPOS[$f['tipo']],
            'desde' => $f['desde'],
            'hasta' => $f['hasta'],
            'periodo' => $this->periodoTexto($f),
            'filtros' => $this->filtrosTexto($f),
            'generado' => now()->format('d/m/Y H:i'),
        ];

        return $f['tipo'] === 'pacientes'
            ? $reporte + $this->pacientes($f, $limite)
            : $reporte + $this->citas($f, $limite);
    }

    // ---------- Reportes de citas ----------

    /** @return array<string, mixed> */
    private function citas(array $f, ?int $limite): array
    {
        $base = $this->citasBase($f);

        $t = (clone $base)->selectRaw("
            count(*) as total,
            count(*) filter (where c.estado = 'PROGRAMADA') as programadas,
            count(*) filter (where c.estado = 'CONFIRMADA') as confirmadas,
            count(*) filter (where c.estado = 'ATENDIDA') as atendidas,
            count(*) filter (where c.estado = 'CANCELADA') as canceladas,
            count(*) filter (where c.estado = 'INASISTENCIA') as inasistencias,
            count(*) filter (where c.estado_pago = 'PAGADO' and c.metodo_pago = 'EFECTIVO') as pagos_efectivo,
            coalesce(sum(c.monto) filter (where c.estado_pago = 'PAGADO' and c.metodo_pago = 'EFECTIVO'), 0) as ingreso_efectivo,
            count(*) filter (where c.estado_pago = 'PAGADO' and c.metodo_pago = 'TARJETA') as pagos_tarjeta,
            coalesce(sum(c.monto) filter (where c.estado_pago = 'PAGADO' and c.metodo_pago = 'TARJETA'), 0) as ingreso_tarjeta,
            count(*) filter (where c.estado in ('PROGRAMADA', 'CONFIRMADA') and (c.fecha + c.hora_fin) < ?) as vencidas,
            count(*) filter (where c.estado = 'ATENDIDA' and c.estado_pago = 'PENDIENTE' and c.monto > 0) as atendidas_sin_cobro,
            coalesce(sum(c.monto) filter (where c.estado = 'ATENDIDA' and c.estado_pago = 'PENDIENTE' and c.monto > 0), 0) as por_cobrar
        ", [Carbon::now()->format('Y-m-d H:i:s')])->first();

        $totales = [
            'total' => (int) $t->total, 'programadas' => (int) $t->programadas, 'confirmadas' => (int) $t->confirmadas,
            'atendidas' => (int) $t->atendidas, 'canceladas' => (int) $t->canceladas, 'inasistencias' => (int) $t->inasistencias,
            'pagos_efectivo' => (int) $t->pagos_efectivo, 'ingreso_efectivo' => (float) $t->ingreso_efectivo,
            'pagos_tarjeta' => (int) $t->pagos_tarjeta, 'ingreso_tarjeta' => (float) $t->ingreso_tarjeta,
            // Control: citas que siguen Programadas o Confirmadas con la hora ya pasada, y atendidas todavía sin cobrar.
            'vencidas' => (int) $t->vencidas, 'atendidas_sin_cobro' => (int) $t->atendidas_sin_cobro, 'por_cobrar_atendidas' => (float) $t->por_cobrar,
        ];
        $totales['ingreso_total'] = $totales['ingreso_efectivo'] + $totales['ingreso_tarjeta'];

        $vacio = $totales['total'] === 0;
        $detalle = $vacio ? collect() : $this->detalleCitas($base, $f)->when($limite, fn ($q) => $q->limit($limite))->get();

        return [
            'columnas' => $this->columnasCitas($f['tipo']),
            'filas' => $detalle->map(fn ($fila) => $this->filaCita($fila, $f['tipo']))->all(),
            'total_filas' => $totales['total'],
            'truncado' => $limite !== null && $totales['total'] > $limite,
            'limite' => $limite,
            'totales' => $totales,
            'resumen' => $this->resumenCitas($totales, $f['tipo']),
            'grupos' => $vacio ? [] : [
                $this->grupo('Por médico', 'Médico', $base, 'm.id', "m.nombres || ' ' || m.apellidos", 'm.apellidos, m.nombres'),
                $this->grupo('Por especialidad', 'Especialidad', $base, 'e.id', 'e.nombre', 'e.nombre'),
            ],
            'vacio' => $vacio,
            'mensaje_vacio' => self::MENSAJE_VACIO,
        ];
    }

    /** Consulta base (sin columnas ni orden): las citas del reporte según el tipo y los filtros. */
    private function citasBase(array $f): Builder
    {
        $consulta = DB::table('citas as c')
            ->join('pacientes as p', 'p.id', '=', 'c.paciente_id')
            ->join('medicos as m', 'm.id', '=', 'c.medico_id')
            ->join('especialidades as e', 'e.id', '=', 'c.especialidad_id');

        if ($f['tipo'] === 'ingresos') {
            $consulta->where('c.estado_pago', Cita::PAGO_PAGADO)
                ->whereBetween(DB::raw('c.fecha_pago::date'), [$f['desde'], $f['hasta']]);
        } else {
            $consulta->whereBetween('c.fecha', [$f['desde'], $f['hasta']]);
        }

        $estadosPeriodo = [Cita::ATENDIDA, Cita::CANCELADA, Cita::INASISTENCIA];
        if ($f['tipo'] === 'periodo') {
            $consulta->whereIn('c.estado', $estadosPeriodo);
        }
        if ($f['medico']) {
            $consulta->where('c.medico_id', $f['medico']);
        }
        if ($f['especialidad']) {
            $consulta->where('c.especialidad_id', $f['especialidad']);
        }
        // En el reporte del período el filtro de estado solo distingue entre los tres estados del propio reporte.
        if ($f['estado'] && ($f['tipo'] !== 'periodo' || in_array($f['estado'], $estadosPeriodo, true))) {
            $consulta->where('c.estado', $f['estado']);
        }

        return $consulta;
    }

    private function detalleCitas(Builder $base, array $f): Builder
    {
        $consulta = (clone $base)->select([
            'c.id', 'c.codigo', 'c.fecha', 'c.hora_inicio', 'c.hora_fin', 'c.modalidad', 'c.estado', 'c.metodo_pago', 'c.estado_pago',
            'c.monto', 'c.fecha_pago', 'p.ci',
            DB::raw("p.nombres || ' ' || p.apellidos as paciente"),
            DB::raw("m.nombres || ' ' || m.apellidos as medico"),
            'e.nombre as especialidad',
        ]);

        match ($f['tipo']) {
            'por_medico' => $consulta->orderBy('m.apellidos')->orderBy('m.nombres')->orderBy('c.fecha')->orderBy('c.hora_inicio'),
            'por_especialidad' => $consulta->orderBy('e.nombre')->orderBy('c.fecha')->orderBy('c.hora_inicio'),
            'por_estado' => $consulta->orderByRaw("array_position(array['PROGRAMADA','CONFIRMADA','ATENDIDA','CANCELADA','INASISTENCIA'], c.estado::text)")
                ->orderBy('c.fecha')->orderBy('c.hora_inicio'),
            'ingresos' => $consulta->orderBy('c.fecha_pago'),
            default => $consulta->orderBy('c.fecha')->orderBy('c.hora_inicio'),
        };

        return $consulta->orderBy('c.id');
    }

    /** Detalle completo como flujo perezoso (para el CSV: no carga todas las filas en memoria). */
    public function detalleCompleto(array $f): LazyCollection
    {
        if ($f['tipo'] === 'pacientes') {
            return $this->consultaPacientes($f)->cursor()->map(fn ($fila) => $this->filaPaciente($fila));
        }

        return $this->detalleCitas($this->citasBase($f), $f)->cursor()->map(fn ($fila) => $this->filaCita($fila, $f['tipo']));
    }

    /** @return list<array{clave: string, etiqueta: string, tipo: string}> */
    private function columnasCitas(string $tipo): array
    {
        $c = fn (string $clave, string $etiqueta, string $t = 'texto') => ['clave' => $clave, 'etiqueta' => $etiqueta, 'tipo' => $t];

        return match ($tipo) {
            'ingresos' => [
                $c('codigo', 'Código'), $c('fecha_pago', 'Fecha del pago', 'fechahora'), $c('paciente', 'Paciente'), $c('ci', 'CI'),
                $c('medico', 'Médico'), $c('especialidad', 'Especialidad'), $c('metodo', 'Método de pago'), $c('monto', 'Monto', 'monto'),
            ],
            'agenda_medico' => [
                $c('horario', 'Horario'), $c('codigo', 'Código'), $c('paciente', 'Paciente'), $c('ci', 'CI'), $c('especialidad', 'Especialidad'),
                $c('modalidad', 'Modalidad'), $c('estado', 'Estado', 'estado'), $c('pago', 'Pago'), $c('monto', 'Monto', 'monto'),
            ],
            default => [
                $c('codigo', 'Código'), $c('fecha', 'Fecha', 'fecha'), $c('horario', 'Horario'), $c('paciente', 'Paciente'), $c('ci', 'CI'),
                $c('medico', 'Médico'), $c('especialidad', 'Especialidad'), $c('estado', 'Estado', 'estado'), $c('pago', 'Pago'), $c('monto', 'Monto', 'monto'),
            ],
        };
    }

    /** @return array<string, mixed> valores sin formato visual, por clave de columna */
    private function filaCita(object $f, string $tipo): array
    {
        return [
            'codigo' => $f->codigo,
            'fecha' => $f->fecha,
            'fecha_pago' => $f->fecha_pago,
            'horario' => substr($f->hora_inicio, 0, 5).' – '.substr($f->hora_fin, 0, 5),
            'paciente' => $f->paciente,
            'ci' => $f->ci,
            'medico' => $f->medico,
            'especialidad' => $f->especialidad,
            'modalidad' => $f->modalidad === Cita::VIRTUAL ? 'Virtual' : 'Presencial',
            // «PROGRAMADA|VENCIDA»: la cita sigue abierta aunque su hora ya pasó (señal, no un estado).
            'estado' => $f->estado.(in_array($f->estado, [Cita::PROGRAMADA, Cita::CONFIRMADA], true) && Carbon::parse($f->fecha.' '.$f->hora_fin)->lt(Carbon::now()) ? '|VENCIDA' : ''),
            'metodo' => $f->metodo_pago === Cita::METODO_TARJETA ? 'Tarjeta (Stripe)' : 'Efectivo',
            'pago' => ($f->metodo_pago === Cita::METODO_TARJETA ? 'Tarjeta' : 'Efectivo').' · '.($f->estado_pago === Cita::PAGO_PAGADO ? 'Pagado' : 'Pendiente'),
            'monto' => $f->monto,
        ];
    }

    /**
     * Desglose de los totales por una dimensión (médico o especialidad): citas por estado y lo cobrado en cada una.
     *
     * @return array{titulo: string, columnas: list<string>, filas: list<list<string>>}
     */
    private function grupo(string $titulo, string $etiqueta, Builder $base, string $clave, string $nombre, string $orden): array
    {
        $filas = (clone $base)
            ->selectRaw("$nombre as nombre, count(*) as total,
                count(*) filter (where c.estado = 'PROGRAMADA') as programadas,
                count(*) filter (where c.estado = 'CONFIRMADA') as confirmadas,
                count(*) filter (where c.estado = 'ATENDIDA') as atendidas,
                count(*) filter (where c.estado = 'CANCELADA') as canceladas,
                count(*) filter (where c.estado = 'INASISTENCIA') as inasistencias,
                coalesce(sum(c.monto) filter (where c.estado_pago = 'PAGADO'), 0) as cobrado")
            ->groupByRaw("$clave, $nombre, $orden")
            ->orderByRaw($orden)
            ->get();

        return [
            'titulo' => $titulo,
            'columnas' => [$etiqueta, 'Total', 'Programadas', 'Confirmadas', 'Atendidas', 'Canceladas', 'Inasistencias', 'Cobrado'],
            'filas' => $filas->map(fn ($g) => [
                $g->nombre, (string) $g->total, (string) $g->programadas, (string) $g->confirmadas, (string) $g->atendidas,
                (string) $g->canceladas, (string) $g->inasistencias, Dinero::formato($g->cobrado),
            ])->all(),
            // Solo cifras, para el resumen de la IA (sin ningún dato de pacientes).
            'cifras' => $filas->map(fn ($g) => [
                'nombre' => $g->nombre, 'total' => (int) $g->total, 'atendidas' => (int) $g->atendidas, 'canceladas' => (int) $g->canceladas,
                'inasistencias' => (int) $g->inasistencias, 'cobrado_bs' => round((float) $g->cobrado, 2),
            ])->all(),
        ];
    }

    /** @return list<array{0: string, 1: string}> etiqueta y valor de cada total (pantalla, CSV y PDF muestran los mismos) */
    private function resumenCitas(array $t, string $tipo): array
    {
        $filas = [
            ['Total de citas', (string) $t['total']],
            ['Programadas', (string) $t['programadas']],
            ['Confirmadas', (string) $t['confirmadas']],
            ['Atendidas', (string) $t['atendidas']],
            ['Canceladas', (string) $t['canceladas']],
            ['Inasistencias', (string) $t['inasistencias']],
            ['Vencidas (pendientes de cierre)', (string) $t['vencidas']],
            ['Atendidas con el pago pendiente', $t['atendidas_sin_cobro'].' · '.Dinero::formato($t['por_cobrar_atendidas'])],
        ];
        $cobrado = $tipo === 'ingresos' ? 'Ingresos' : 'Cobrado en estas citas';

        return array_merge($filas, [
            ["$cobrado · efectivo ({$t['pagos_efectivo']} pagos)", Dinero::formato($t['ingreso_efectivo'])],
            ["$cobrado · tarjeta ({$t['pagos_tarjeta']} pagos)", Dinero::formato($t['ingreso_tarjeta'])],
            ["$cobrado · total", Dinero::formato($t['ingreso_total'])],
        ]);
    }

    // ---------- Pacientes registrados ----------

    private function consultaPacientes(array $f): Builder
    {
        return DB::table('historias_clinicas as h')
            ->join('pacientes as p', 'p.id', '=', 'h.paciente_id')
            ->whereBetween('h.fecha_apertura', [$f['desde'], $f['hasta']])
            ->select(['p.codigo', 'p.ci', DB::raw("p.nombres || ' ' || p.apellidos as paciente"), 'p.telefono', 'p.correo', 'p.estado', 'h.fecha_apertura'])
            ->orderBy('h.fecha_apertura')->orderBy('p.apellidos')->orderBy('p.nombres')->orderBy('p.id');
    }

    /** @return array<string, mixed> */
    private function pacientes(array $f, ?int $limite): array
    {
        $t = DB::table('historias_clinicas as h')
            ->join('pacientes as p', 'p.id', '=', 'h.paciente_id')
            ->whereBetween('h.fecha_apertura', [$f['desde'], $f['hasta']])
            ->selectRaw('count(*) as total, count(*) filter (where p.estado) as activos, count(*) filter (where not p.estado) as inactivos')
            ->first();
        $totales = ['total' => (int) $t->total, 'activos' => (int) $t->activos, 'inactivos' => (int) $t->inactivos];
        $vacio = $totales['total'] === 0;
        $detalle = $vacio ? collect() : $this->consultaPacientes($f)->when($limite, fn ($q) => $q->limit($limite))->get();
        $c = fn (string $clave, string $etiqueta, string $tipo = 'texto') => ['clave' => $clave, 'etiqueta' => $etiqueta, 'tipo' => $tipo];

        return [
            'columnas' => [
                $c('fecha_apertura', 'Fecha de registro', 'fecha'), $c('codigo', 'Código'), $c('ci', 'CI'), $c('paciente', 'Paciente'),
                $c('telefono', 'Teléfono'), $c('correo', 'Correo'), $c('estado', 'Estado'),
            ],
            'filas' => $detalle->map(fn ($fila) => $this->filaPaciente($fila))->all(),
            'total_filas' => $totales['total'],
            'truncado' => $limite !== null && $totales['total'] > $limite,
            'limite' => $limite,
            'totales' => $totales,
            'resumen' => [
                ['Pacientes registrados', (string) $totales['total']],
                ['Activos', (string) $totales['activos']],
                ['Inactivos', (string) $totales['inactivos']],
            ],
            'grupos' => [],
            'vacio' => $vacio,
            'mensaje_vacio' => self::MENSAJE_VACIO_PACIENTES,
        ];
    }

    /** @return array<string, mixed> */
    private function filaPaciente(object $f): array
    {
        return [
            'fecha_apertura' => $f->fecha_apertura, 'codigo' => $f->codigo, 'ci' => $f->ci, 'paciente' => $f->paciente,
            'telefono' => $f->telefono, 'correo' => $f->correo ?? '—', 'estado' => $f->estado ? 'Activo' : 'Inactivo',
        ];
    }

    // ---------- Textos y formato ----------

    /** «05/11/2026» o «del 01/11/2026 al 07/11/2026». */
    public function periodoTexto(array $f): string
    {
        $desde = Carbon::parse($f['desde'])->format('d/m/Y');
        $hasta = Carbon::parse($f['hasta'])->format('d/m/Y');
        $prefijo = $f['tipo'] === 'pacientes' ? 'registrados ' : ($f['tipo'] === 'ingresos' ? 'pagados ' : '');

        return $desde === $hasta ? $prefijo.$desde : "{$prefijo}del $desde al $hasta";
    }

    /** @return list<string> los filtros opcionales que se aplicaron */
    private function filtrosTexto(array $f): array
    {
        if ($f['tipo'] === 'pacientes') {
            return [];
        }
        $textos = [];
        if ($f['medico'] && ($medico = Medico::find($f['medico']))) {
            $textos[] = 'Médico: '.$medico->nombre_completo;
        }
        if ($f['especialidad'] && ($especialidad = Especialidad::find($f['especialidad']))) {
            $textos[] = 'Especialidad: '.$especialidad->nombre;
        }
        if ($f['estado'] && ($f['tipo'] !== 'periodo' || in_array($f['estado'], [Cita::ATENDIDA, Cita::CANCELADA, Cita::INASISTENCIA], true))) {
            $textos[] = 'Estado: '.self::ESTADOS[$f['estado']];
        }

        return $textos;
    }

    /**
     * Texto plano de una celda del detalle (CSV y PDF; la pantalla da su propio formato a estados y montos).
     *
     * @param  string  $destino  'csv' (monto como número con coma decimal) o 'pdf'
     */
    public static function textoCelda(string $tipo, mixed $valor, string $destino = 'pdf'): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        return match ($tipo) {
            'fecha' => Carbon::parse($valor)->format('d/m/Y'),
            'fechahora' => Carbon::parse($valor)->format('d/m/Y H:i'),
            'estado' => (self::ESTADOS[explode('|', (string) $valor)[0]] ?? (string) $valor).(str_contains((string) $valor, '|VENCIDA') ? ' (vencida: pendiente de cierre)' : ''),
            'monto' => $destino === 'csv' ? number_format((float) $valor, 2, ',', '') : Dinero::formato($valor),
            default => (string) $valor,
        };
    }

    // ---------- Exportación ----------

    /**
     * Escribe el reporte como CSV (UTF-8 con BOM, separador «;»): título, período y filtros, el detalle completo, los totales, los
     * desgloses y, si se generó, el resumen de IA. Recibe un recurso de escritura (php://output en la descarga por streaming).
     *
     * @CC CCReportes (exportar)
     *
     * @param  resource  $salida
     * @param  array<string, mixed>  $reporte  resultado de generar()
     */
    public function escribirCsv($salida, array $reporte, iterable $filas, ?string $resumenIA = null): void
    {
        $linea = function (array $celdas) use ($salida) {
            $celdas = array_map(function ($celda) {
                $celda = (string) $celda;
                // Una celda que empieza con = + - @ se podría interpretar como fórmula al abrir el CSV en una hoja de cálculo.
                if (preg_match('/^[=+\-@\t\r]/', $celda) && ! is_numeric(str_replace(',', '.', $celda))) {
                    $celda = "'".$celda;
                }

                // Solo se entrecomilla lo necesario (separador, comillas o saltos de línea).
                return preg_match('/[;"\r\n]/', $celda) ? '"'.str_replace('"', '""', $celda).'"' : $celda;
            }, $celdas);
            fwrite($salida, implode(';', $celdas)."\r\n");
        };

        fwrite($salida, "\xEF\xBB\xBF");
        $linea([$reporte['titulo']]);
        $linea(['Período', $reporte['periodo']]);
        foreach ($reporte['filtros'] as $filtro) {
            $linea(explode(': ', $filtro, 2));
        }
        $linea(['Generado', $reporte['generado']]);
        $linea([]);

        $linea(array_column($reporte['columnas'], 'etiqueta'));
        foreach ($filas as $fila) {
            $linea(array_map(fn ($col) => self::textoCelda($col['tipo'], $fila[$col['clave']] ?? null, 'csv'), $reporte['columnas']));
        }

        $linea([]);
        $linea(['Totales']);
        foreach ($reporte['resumen'] as [$etiqueta, $valor]) {
            $linea([$etiqueta, $valor]);
        }
        foreach ($reporte['grupos'] as $grupo) {
            $linea([]);
            $linea([$grupo['titulo']]);
            $linea($grupo['columnas']);
            foreach ($grupo['filas'] as $fila) {
                $linea($fila);
            }
        }

        if ($resumenIA !== null) {
            $linea([]);
            $linea(['Resumen generado por IA']);
            $linea([$resumenIA]);
        }
    }

    /** Nombre del archivo exportado: reporte_<tipo>_<desde>_<hasta>.<extension>. */
    public function nombreArchivo(array $reporte, string $extension): string
    {
        $fecha = $reporte['desde'] === $reporte['hasta'] ? $reporte['desde'] : $reporte['desde'].'_'.$reporte['hasta'];

        return "reporte_{$reporte['tipo']}_{$fecha}.{$extension}";
    }

    // ---------- Para la IA ----------

    /**
     * SOLO cifras agregadas para el resumen de la IA (RNF09): tipo, período, totales y el total de cada médico y de cada especialidad.
     * No incluye ninguna fila del detalle: nada de nombres de pacientes, CI, correos, teléfonos ni datos clínicos.
     *
     * @param  array<string, mixed>  $reporte  resultado de generar()
     * @return array<string, mixed>
     */
    public function totalesParaIA(array $reporte): array
    {
        $datos = [
            'tipo_de_reporte' => $reporte['titulo'],
            'periodo' => $reporte['periodo'],
            'filtros_aplicados' => $reporte['filtros'] ?: 'ninguno',
            'totales' => $reporte['totales'],
        ];

        foreach ($reporte['grupos'] as $grupo) {
            $datos[$grupo['titulo'] === 'Por médico' ? 'por_medico' : 'por_especialidad'] = array_slice($grupo['cifras'], 0, 10);
        }

        return $datos;
    }
}
