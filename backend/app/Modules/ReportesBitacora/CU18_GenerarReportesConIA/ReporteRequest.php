<?php

namespace App\Modules\ReportesBitacora\CU18_GenerarReportesConIA;

use App\Models\Especialidad;
use App\Models\Medico;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Validación de los filtros del reporte (paso 3 del flujo): tipo, fecha o rango (la final no puede ser anterior a la inicial),
 * médico, especialidad y estado opcionales, y la modalidad sin IA o con IA. Se usa para ver el reporte y para exportarlo (mismos filtros).
 * Es un envío GET: la primera vez que se abre «Reportes» (sin ningún filtro) no se valida nada y solo se muestra el formulario.
 *
 * @CU CU-18 @RF RF18 @CI CIReportes (elegirFiltros) @CC CCReportes (generar) @CE CECita, CEMedico
 */
class ReporteRequest extends FormRequest
{
    public const MENSAJE_RANGO = 'La fecha final no puede ser anterior a la fecha inicial.';

    public function authorize(): bool
    {
        return true; // el acceso ya está limitado a Administrador y Recepcionista en routes.php
    }

    /** Con filtros inválidos se vuelve al formulario limpio (no al reporte anterior ni a esta misma dirección). */
    protected function getRedirectUrl(): string
    {
        return route('reportes.index');
    }

    /** ¿Llegaron filtros? (si no, es la primera visita a «Reportes»). */
    public function conFiltros(): bool
    {
        return $this->query->has('tipo');
    }

    protected function prepareForValidation(): void
    {
        $datos = ['ia' => $this->input('ia') ?: 'sin'];

        // Los reportes de un solo día usan únicamente «Fecha»: el rango se fija en ese día.
        if (in_array($this->input('tipo'), ReporteService::UN_DIA, true)) {
            $datos['hasta'] = $this->input('desde');
        }

        $this->merge($datos);
    }

    public function rules(): array
    {
        if (! $this->conFiltros()) {
            return [];
        }

        return [
            'tipo' => ['required', Rule::in(array_keys(ReporteService::TIPOS))],
            'desde' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
            'hasta' => [
                'nullable', 'date_format:Y-m-d', 'after_or_equal:desde',
                function (string $atributo, mixed $valor, Closure $fallar) {
                    $desde = $this->input('desde');
                    if ($valor && $desde && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)
                        && Carbon::parse($desde)->diffInDays(Carbon::parse($valor)) > ReporteService::MAX_DIAS) {
                        $fallar('El rango no puede superar los '.ReporteService::MAX_DIAS.' días.');
                    }
                },
            ],
            'medico' => [Rule::requiredIf(fn () => $this->input('tipo') === 'agenda_medico'), 'nullable', 'integer', Rule::exists(Medico::class, 'id')],
            'especialidad' => ['nullable', 'integer', Rule::exists(Especialidad::class, 'id')],
            'estado' => ['nullable', Rule::in(array_keys(ReporteService::estados()))],
            'ia' => ['required', Rule::in(['sin', 'con'])],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required' => 'Seleccione el tipo de reporte.',
            'tipo.in' => 'Seleccione un tipo de reporte de la lista.',
            'desde.required' => 'Ingrese la fecha (o la fecha inicial).',
            'desde.date_format' => 'La fecha no es válida.',
            'desde.after_or_equal' => 'La fecha no es válida.',
            'desde.before_or_equal' => 'La fecha no es válida.',
            'hasta.date_format' => 'La fecha final no es válida.',
            'hasta.after_or_equal' => self::MENSAJE_RANGO,
            'medico.required' => 'Seleccione el médico de la agenda.',
            'medico.exists' => 'El médico seleccionado no existe.',
            'especialidad.exists' => 'La especialidad seleccionada no existe.',
            'estado.in' => 'Seleccione un estado de la lista.',
            'ia.in' => 'Elija la modalidad sin IA o con IA.',
        ];
    }

    /**
     * Filtros ya normalizados para el servicio (null si es la primera visita).
     *
     * @return array{tipo: string, desde: string, hasta: string, medico: ?int, especialidad: ?int, estado: ?string, ia: string}|null
     */
    public function filtros(): ?array
    {
        if (! $this->conFiltros()) {
            return null;
        }
        $v = $this->validated();

        return [
            'tipo' => $v['tipo'],
            'desde' => $v['desde'],
            'hasta' => $v['hasta'] ?? $v['desde'],
            'medico' => ! empty($v['medico']) ? (int) $v['medico'] : null,
            'especialidad' => ! empty($v['especialidad']) ? (int) $v['especialidad'] : null,
            'estado' => $v['estado'] ?? null,
            'ia' => $v['ia'],
        ];
    }
}
