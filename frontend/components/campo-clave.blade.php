@props(['nombre', 'etiqueta', 'ayuda' => null])
{{-- Campo de contraseña con botón de ojito para mostrar u ocultar el texto (el JavaScript está en assets/js/vidasalud.js).
     Mantiene las mismas reglas que <x-campo>: etiqueta visible y mensaje de error del servidor junto al campo. --}}
@php $id = 'campo-'.$nombre; @endphp
<div @class(['campo', 'campo--error' => $errors->has($nombre)])>
    <label for="{{ $id }}">{{ $etiqueta }}</label>
    <div class="campo-clave">
        <input id="{{ $id }}" type="password" name="{{ $nombre }}" value=""
               {{ $attributes }}
               @if ($errors->has($nombre)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
        <button type="button" class="campo-clave__ojo" data-mostrar-clave aria-controls="{{ $id }}"
                aria-label="Mostrar contraseña" aria-pressed="false">
            <x-icono nombre="ojo" class="ojo--mostrar" />
            <x-icono nombre="ojo-tachado" class="ojo--ocultar" />
        </button>
    </div>
    @if ($ayuda)<span class="campo__ayuda">{{ $ayuda }}</span>@endif
    @error($nombre)<span class="campo__error" id="{{ $id }}-error" role="alert">{{ $message }}</span>@enderror
</div>
