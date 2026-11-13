@props(['cita', 'personal' => false])
{{-- Historial de cambios de la cita (extra aprobado de CU-10, CU-12, CU-13 y CU-15): insignia «Reprogramada» y «Ver cambios», del más antiguo al más
     reciente. Al paciente no se le muestra el nombre del personal (solo «recepción» o «usted»); al personal sí. Los eventos llegan con eager loading. --}}
@php
    $eventos = $cita->eventos;
    $reprogramada = $eventos->contains('tipo', 'REPROGRAMADA');
    $nombres = ['PROGRAMADA' => 'Programada', 'CONFIRMADA' => 'Confirmada', 'ATENDIDA' => 'Atendida', 'CANCELADA' => 'Cancelada', 'INASISTENCIA' => 'Inasistencia'];
    $duenoId = $cita->paciente?->usuario_id;
    $cuando = fn ($e) => $e->created_at?->format('d/m H:i');
    $quien = fn ($e) => $personal && $e->usuario ? ' · por '.$e->usuario->nombre_usuario : '';
@endphp
@if ($reprogramada)
    <span class="etiqueta-estado etiqueta-reprogramada" title="Esta cita cambió de fecha u hora; conserva su código y su pago.">Reprogramada</span>
@endif
@if ($eventos->count() > 1 || $reprogramada)
    <details class="cambios-cita">
        <summary>Ver cambios</summary>
        <ul>
            @foreach ($eventos as $e)
                <li>
                    @switch($e->tipo)
                        @case('REGISTRADA')
                            {{ $personal ? 'Registrada' : 'Reservada' }}{{ $quien($e) }}
                            @break
                        @case('CONFIRMADA')
                            Confirmada{{ $quien($e) }}
                            @break
                        @case('ATENDIDA')
                            Atendida{{ $quien($e) }}
                            @break
                        @case('INASISTENCIA')
                            Inasistencia registrada{{ $cita->estado_pago === 'PAGADO' ? ' · pago conservado' : '' }}{{ $quien($e) }}
                            @break
                        @case('REPROGRAMADA')
                            @if ($e->fecha_anterior)
                                Reprogramada: de {{ $e->fecha_anterior->format('d/m') }} {{ $e->hora_anterior_corta }} a {{ $e->fecha_nueva->format('d/m') }} {{ $e->hora_nueva_corta }}
                            @else
                                Reprogramada a {{ $e->fecha_nueva?->format('d/m') }} {{ $e->hora_nueva_corta }}
                            @endif
                            @if ($e->motivo) · motivo: {{ $e->motivo }}@endif{{ $quien($e) }}
                            @break
                        @case('CANCELADA')
                            Cancelada{{ $e->motivo ? ' · motivo: '.$e->motivo : '' }}{{ $personal ? $quien($e) : ' · '.($duenoId && $e->usuario_id === $duenoId ? 'por usted' : 'por recepción') }}
                            @break
                        @case('ESTADO_CORREGIDO')
                            Estado corregido: de {{ $nombres[$e->estado_anterior] ?? $e->estado_anterior }} a {{ $nombres[$e->estado_nuevo] ?? $e->estado_nuevo }}{{ $personal && $e->motivo ? ' · '.$e->motivo : '' }}{{ $quien($e) }}
                            @break
                    @endswitch
                    <small>{{ $cuando($e) }}</small>
                </li>
            @endforeach
        </ul>
    </details>
@endif
