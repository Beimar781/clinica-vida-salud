{{-- @CU CU-12 @RF RF12 @CI CICancelarCita (buscarCita, pedirMotivo, confirmarCancelacion, mostrarResultado) @CC CCCancelarCita @CE CECita
     Pasos 2 a 4 del flujo: CancelarCitaController::mostrar() muestra la cita, verifica que pueda cancelarse (y que sea del paciente) y
     pide el motivo; guardar() confirma. Si la cita está pagada avisa que el pago no se devuelve y ofrece reprogramar (CU-10). --}}
@extends('layouts.app')

@section('titulo', 'Cancelar cita')

@push('estilos')
    <link rel="stylesheet" href="@recurso('css/modulos/gestion-citas.css')">
@endpush

@push('scripts')
    <script src="@recurso('js/modulos/gestion-citas.js')" defer></script>
@endpush

@section('contenido')
    @php $volver = $esPersonal ? route('citas.index') : (\Illuminate\Support\Facades\Route::has('historial.index') ? route('historial.index') : route('panel')); @endphp

    <div class="encabezado-pagina">
        <h1>Cancelar cita <small class="texto-suave">{{ $cita->codigo }}</small></h1>
        <a class="btn btn-secundario" href="{{ $volver }}">{{ $esPersonal ? 'Volver a las citas' : 'Volver a mis citas' }}</a>
    </div>

    <div class="tarjeta">
        <div class="tarjeta__encabezado"><x-icono nombre="documento" /><h2>Datos de la cita</h2></div>
        <div class="tarjeta__cuerpo">
            @include('gestioncitas::Compartido.comprobante-datos')

            @if (! $puede)
                <div class="aviso aviso-alerta" role="alert"><x-icono nombre="alerta" /> <span>{{ $motivoNo }}</span></div>
                <div class="acciones"><a class="btn btn-secundario" href="{{ $volver }}">Volver</a></div>
            @elseif (! $anticipacion)
                <div class="aviso aviso-alerta" role="alert">
                    <x-icono nombre="alerta" />
                    <span>{{ \App\Modules\GestionCitas\CU12_CancelarCitaMedica\CancelarCitaService::MENSAJE_ANTICIPACION }}</span>
                </div>
                <div class="acciones"><a class="btn btn-secundario" href="{{ $volver }}">Volver a mis citas</a></div>
            @endif
        </div>
    </div>

    @if ($puede && $anticipacion)
        @if ($cita->estaPagada())
            <div class="aviso aviso-alerta" role="alert">
                <x-icono nombre="alerta" />
                <span>
                    Esta cita ya está pagada ({{ $cita->monto_texto }}, {{ $cita->metodo_pago_texto }}) y <strong>el pago no se devuelve</strong> si se cancela.
                    @if ($ofrecerReprogramar)
                        Puede reprogramarla a otra fecha y conservar el pago.
                    @elseif (! $esPersonal)
                        Si prefieres conservar tu pago, pide en recepción que reprogramen tu cita.
                    @endif
                </span>
            </div>
        @endif

        <div class="tarjeta">
            <div class="tarjeta__encabezado"><x-icono nombre="bloquear" /><h2>Motivo de la cancelación</h2></div>
            <div class="tarjeta__cuerpo">
                <form method="POST" action="{{ route('citas.cancelar.guardar', $cita) }}" data-validar novalidate>
                    @csrf
                    <div class="formulario">
                        <x-areatexto nombre="motivo" etiqueta="Motivo de la cancelación" required maxlength="200" rows="3" autofocus
                                     ayuda="Queda guardado en la cita y en la bitácora. Máximo 200 caracteres." />
                    </div>

                    @if ($cita->estaPagada())
                        <div @class(['campo', 'campo--check', 'campo--error' => $errors->has('confirmo_sin_devolucion')])>
                            <input type="hidden" name="confirmo_sin_devolucion" value="0">
                            <input type="checkbox" id="campo-confirmo_sin_devolucion" name="confirmo_sin_devolucion" value="1" required
                                   data-mensaje-requerido="Marque la casilla para cancelar sin devolución del pago." @checked(old('confirmo_sin_devolucion') === '1')>
                            <label for="campo-confirmo_sin_devolucion">Entiendo que el pago no se devuelve y confirmo la cancelación.</label>
                        </div>
                    @endif

                    <div class="acciones">
                        <button type="submit" class="btn btn-peligro"><x-icono nombre="bloquear" /> <span>Confirmar cancelación</span></button>
                        @if ($ofrecerReprogramar)
                            <a class="btn btn-primario" href="{{ route('citas.reprogramar', $cita) }}"><x-icono nombre="calendario" /> Reprogramar en su lugar</a>
                        @endif
                        <a class="btn btn-secundario" href="{{ $volver }}">No cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
