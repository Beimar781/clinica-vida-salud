{{-- @CU CU-22 @RF RF22 @CI CIRecordatorio (armarAviso) — versión en texto plano del correo --}}
Hola, {{ $paciente->nombres }}

Te recordamos {{ $una ? 'tu cita médica' : 'tus citas médicas' }} de mañana, {{ $dia->translatedFormat('l d/m/Y') }}:
@foreach ($lista as $cita)

Cita {{ $cita['codigo'] }}
  Médico: {{ $cita['medico'] }} ({{ $cita['especialidad'] }})
  Fecha: {{ $cita['fecha'] }}
  Horario: {{ $cita['horario'] }}
  Modalidad: {{ $cita['modalidad'] }}
@if ($cita['pendiente'])
  Pago pendiente: {{ $cita['pendiente'] }} (efectivo en la clínica)
@endif
@endforeach

Te pedimos llegar unos minutos antes. Si no puedes asistir, cancela o reprograma desde «Mis citas» con al menos 2 horas de anticipación, o comunícate con recepción.
Recibes este aviso porque tienes activados los recordatorios; puedes desactivarlos en «Mi perfil».

Clínica Vida Salud
