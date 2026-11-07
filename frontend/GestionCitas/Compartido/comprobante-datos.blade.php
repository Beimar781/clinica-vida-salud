{{-- @CU CU-09, CU-10, CU-11 @RF RF09, RF10, RF11 @CI CIRegistrarCita, CIReprogramarCita, CIReservarCita (mostrarComprobante) @CE CECita (monto y pago)
     Datos del comprobante de una cita. Variable: $cita (con paciente, médico, especialidad y creador). --}}
<dl class="comprobante">
    <div><dt>Código de la cita</dt><dd class="comprobante__codigo">{{ $cita->codigo }}</dd></div>
    <div><dt>Estado</dt><dd><x-estado-cita :estado="$cita->estado" /></dd></div>
    <div><dt>Paciente</dt><dd>{{ $cita->paciente->nombre_completo }} <small class="texto-suave">· CI {{ $cita->paciente->ci }} · {{ $cita->paciente->codigo }}</small></dd></div>
    <div><dt>Especialidad</dt><dd>{{ $cita->especialidad->nombre }}</dd></div>
    <div><dt>Médico</dt><dd>{{ $cita->medico->nombre_completo }}@if ($cita->medico->ubicacion) <small class="texto-suave">· {{ $cita->medico->ubicacion }}</small>@endif</dd></div>
    <div><dt>Fecha</dt><dd>{{ ucfirst($cita->fecha->isoFormat('dddd')) }} {{ $cita->fecha->format('d/m/Y') }}</dd></div>
    <div><dt>Horario</dt><dd>{{ $cita->hora_inicio_corta }} – {{ $cita->hora_fin_corta }}</dd></div>
    <div><dt>Modalidad</dt><dd>{{ $cita->modalidad === 'VIRTUAL' ? 'Virtual' : 'Presencial' }}</dd></div>
    <div><dt>Motivo</dt><dd>{{ $cita->motivo ?: 'No indicado' }}</dd></div>
    <div><dt>Monto</dt><dd><strong>{{ $cita->monto_texto }}</strong></dd></div>
    <div><dt>Método de pago</dt><dd>{{ $cita->metodo_pago_texto }}</dd></div>
    <div><dt>Estado del pago</dt><dd><x-estado-pago :estado="$cita->estado_pago" />
        @if ($cita->estado_pago === 'PENDIENTE')<small class="texto-suave">· se cobra en efectivo en la clínica</small>@endif
        @if ($cita->fecha_pago)<small class="texto-suave">· {{ $cita->fecha_pago->format('d/m/Y H:i') }}</small>@endif</dd></div>
    @if ($cita->referencia_pago)
        <div><dt>Referencia del pago</dt><dd><small>{{ $cita->referencia_pago }}</small></dd></div>
    @endif
    <div><dt>Registrada el</dt><dd>{{ $cita->created_at->format('d/m/Y H:i') }}@if ($cita->creador) <small class="texto-suave">· por {{ $cita->creador->nombre_mostrado }}</small>@endif</dd></div>
</dl>
