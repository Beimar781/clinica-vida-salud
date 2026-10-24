@props(['nombre', 'etiqueta', 'valor' => null, 'ayuda' => null])
{{-- Área de texto con etiqueta visible y el error del servidor junto al campo --}}
@php $id = 'campo-'.$nombre; @endphp
<div @class(['campo', 'campo--error' => $errors->has($nombre)])>
    <label for="{{ $id }}">{{ $etiqueta }}</label>
    <textarea id="{{ $id }}" name="{{ $nombre }}" {{ $attributes }}
              @if ($errors->has($nombre)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>{{ old($nombre, $valor) }}</textarea>
    @if ($ayuda)<span class="campo__ayuda">{{ $ayuda }}</span>@endif
    @error($nombre)<span class="campo__error" id="{{ $id }}-error" role="alert">{{ $message }}</span>@enderror
</div>
