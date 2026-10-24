@props(['estado'])
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
