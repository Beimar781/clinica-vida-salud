@props(['estado', 'vencida' => false])
{{-- Etiqueta de estado de la cita: Programada (celeste), Confirmada (verde), Atendida (azul oscuro), Cancelada (rojo), Inasistencia (ámbar) --}}
@php
    $textos = [
        'PROGRAMADA' => 'Programada',
        'CONFIRMADA' => 'Confirmada',
        'ATENDIDA' => 'Atendida',
        'CANCELADA' => 'Cancelada',
        'INASISTENCIA' => 'Inasistencia',
    ];
@endphp
<span {{ $attributes->class(['etiqueta-estado', 'estado-'.strtolower($estado)]) }}>{{ $textos[$estado] ?? $estado }}</span>
@if ($vencida)
    <span class="etiqueta-estado etiqueta-vencida" title="La hora de la cita ya pasó y sigue sin cerrarse: registre la atención, la inasistencia o la cancelación.">Vencida · pendiente de cierre</span>
@endif
