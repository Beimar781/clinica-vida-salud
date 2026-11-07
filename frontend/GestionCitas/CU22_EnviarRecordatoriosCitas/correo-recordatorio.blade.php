{{-- @CU CU-22 @RF RF22 @CI CIRecordatorio (armarAviso) — correo HTML con estilos en línea (sin imágenes externas). Variables: $paciente, $lista (arreglos), $dia, $una --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recordatorio de {{ $una ? 'tu cita' : 'tus citas' }} · Clínica Vida Salud</title>
</head>
<body style="margin:0;padding:24px 12px;background:#F1F8FD;font-family:'Segoe UI',Arial,sans-serif;color:#1F3347;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #D5E5F2;border-radius:10px;overflow:hidden;">
        <tr>
            <td style="background:#0A7BC4;padding:18px 24px;color:#FFFFFF;font-size:20px;font-weight:700;">
                ✚ Clínica Vida Salud
            </td>
        </tr>
        <tr>
            <td style="padding:24px;font-size:15px;line-height:1.55;">
                <p style="margin:0 0 12px;color:#0B3D75;font-size:18px;font-weight:700;">Hola, {{ $paciente->nombres }}</p>
                <p style="margin:0 0 16px;">Te recordamos {{ $una ? 'tu cita médica' : 'tus citas médicas' }} de mañana, <strong>{{ $dia->translatedFormat('l d/m/Y') }}</strong>:</p>

                @foreach ($lista as $cita)
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;background:#F1F8FD;border:1px solid #D5E5F2;border-radius:8px;">
                        <tr>
                            <td style="padding:12px 16px;font-size:14px;line-height:1.6;">
                                <div style="color:#0B3D75;font-weight:700;font-size:16px;">Cita {{ $cita['codigo'] }} · {{ $cita['horario'] }}</div>
                                <div><strong>Médico:</strong> {{ $cita['medico'] }} ({{ $cita['especialidad'] }})</div>
                                <div><strong>Fecha:</strong> {{ $cita['fecha'] }}</div>
                                <div><strong>Horario:</strong> {{ $cita['horario'] }}</div>
                                <div><strong>Modalidad:</strong> {{ $cita['modalidad'] }}</div>
                                @if ($cita['pendiente'])
                                    <div><strong>Pago pendiente:</strong> {{ $cita['pendiente'] }} (efectivo en la clínica)</div>
                                @endif
                            </td>
                        </tr>
                    </table>
                @endforeach

                <p style="margin:0 0 12px;">Te pedimos llegar unos minutos antes. Si no puedes asistir, cancela o reprograma desde «Mis citas» con al menos 2 horas de anticipación, o comunícate con recepción.</p>
                <p style="margin:0;color:#5B7287;font-size:13px;">Recibes este aviso porque tienes activados los recordatorios. Puedes desactivarlos en «Mi perfil».</p>
            </td>
        </tr>
        <tr>
            <td style="background:#DCEFFB;padding:12px 24px;color:#0B3D75;font-size:13px;">Clínica Vida Salud · Gestión de citas médicas</td>
        </tr>
    </table>
</body>
</html>
