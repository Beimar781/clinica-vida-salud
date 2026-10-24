@props(['nombre', 'etiqueta', 'opciones', 'valor' => null, 'vacio' => null, 'ayuda' => null])
{{-- Lista desplegable con etiqueta visible y el error del servidor junto al campo. $opciones: [valor => texto] --}}
@php $id = 'campo-'.$nombre; @endphp
<div @class(['campo', 'campo--error' => $errors->has($nombre)])>
    <label for="{{ $id }}">{{ $etiqueta }}</label>
    <select id="{{ $id }}" name="{{ $nombre }}" {{ $attributes }}
            @if ($errors->has($nombre)) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
        @if ($vacio !== null)<option value="">{{ $vacio }}</option>@endif
        @foreach ($opciones as $clave => $texto)
            <option value="{{ $clave }}" @selected((string) old($nombre, $valor) === (string) $clave)>{{ $texto }}</option>
        @endforeach
    </select>
    @if ($ayuda)<span class="campo__ayuda">{{ $ayuda }}</span>@endif
    @error($nombre)<span class="campo__error" id="{{ $id }}-error" role="alert">{{ $message }}</span>@enderror
</div>
