@props(['nombre'])
{{-- Iconos SVG en línea (trazo, 24x24). Uso: <x-icono nombre="calendario" /> --}}
@php
    $iconos = [
        'usuario' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
        'usuarios' => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-1a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v1"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/><path d="M22 21v-1a5 5 0 0 0-3-4.6"/>',
        'escudo' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'llave' => '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L20 3"/><path d="M16 7l3 3"/>',
        'correo' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'medico' =>'<path d="M6 3H5a1 1 0 0 0-1 1v5a5 5 0 0 0 10 0V4a1 1 0 0 0-1-1h-1"/><path d="M9 14v1a5 5 0 0 0 10 0v-2"/><circle cx="19" cy="11" r="2"/>',
        'cruz' => '<path d="M9 3h6v6h6v6h-6v6H9v-6H3V9h6z"/>',
        'calendario' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4"/><path d="M8 3v4"/><path d="M3 11h18"/>',
        'cita' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4"/><path d="M8 3v4"/><path d="M3 11h18"/><path d="M9 16l2 2 4-4"/>',
        'reloj' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'paciente' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 2h6v4H9z"/><path d="M12 10v6"/><path d="M9 13h6"/>',
        'historial' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
        'buscar' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'panel' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'salir' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'entrar' => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/>',
        'mas' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'editar' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'check' => '<path d="M5 13l4 4L19 7"/>',
        'cerrar' => '<path d="M18 6L6 18"/><path d="M6 6l12 12"/>',
        'bloquear' => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
        'alerta' => '<path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
        'ubicacion' => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
        'video' => '<rect x="2" y="6" width="13" height="12" rx="2"/><path d="M22 8l-5 4 5 4z"/>',
        'documento' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6"/><path d="M9 17h6"/>',
        'flecha' => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
        'corazon' => '<path d="M20.8 5.6a5.2 5.2 0 0 0-7.4 0L12 7l-1.4-1.4a5.2 5.2 0 0 0-7.4 7.4L12 21.8l8.8-8.8a5.2 5.2 0 0 0 0-7.4z"/>',
    ];
@endphp
<svg {{ $attributes->class('icono') }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $iconos[$nombre] ?? $iconos['info'] !!}</svg>
