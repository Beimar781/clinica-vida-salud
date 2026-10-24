@props(['nombre', 'etiqueta', 'tipo' => 'text', 'valor' => null, 'ayuda' => null])
{{-- Campo de formulario con etiqueta visible y el mensaje de error del servidor junto al campo --}}
@php $id = 'campo-'.$nombre; @endphp
<div @class(['campo', 'campo--error' => $errors->has($nombre)])>
    <label for="{{ $id }}">{{ $etiqueta }}</label>
    <input id="{{ $id }}" type="{{ $tipo }}" name="{{ $nombre }}"
           value="{{ $tipo === 'password' ? '' : old($nombre, $valor) }}"
           {{ $attributes }}
           @if ($errors->has($nombre)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
    @if ($ayuda)<span class="campo__ayuda">{{ $ayuda }}</span>@endif
    @error($nombre)<span class="campo__error" id="{{ $id }}-error" role="alert">{{ $message }}</span>@enderror
</div>
