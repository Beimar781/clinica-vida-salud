@props(['tipo'])
{{-- Etiqueta del tipo de especialidad o servicio: Consulta médica (celeste), Laboratorio (azul), Enfermería (verde azulado) --}}
@php
    $textos = ['CONSULTA' => 'Consulta médica', 'LABORATORIO' => 'Laboratorio', 'ENFERMERIA' => 'Enfermería'];
@endphp
<span {{ $attributes->class(['etiqueta-estado', 'tipo-'.strtolower($tipo)]) }}>{{ $textos[$tipo] ?? $tipo }}</span>
