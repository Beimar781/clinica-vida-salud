@props(['estado'])
{{-- Etiqueta del estado de pago de la cita: Pendiente (ámbar) o Pagado (verde) --}}
@php
    $textos = ['PENDIENTE' => 'Pendiente', 'PAGADO' => 'Pagado'];
@endphp
<span {{ $attributes->class(['etiqueta-estado', 'pago-'.strtolower($estado)]) }}>{{ $textos[$estado] ?? $estado }}</span>
