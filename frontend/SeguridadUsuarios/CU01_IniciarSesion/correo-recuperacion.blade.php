{{-- @CU CU-01 (flujo alternativo A3: recuperar contraseña) @CI CILogin — correo HTML con estilos en línea (sin imágenes externas) --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recupera tu contraseña · Clínica Vida Salud</title>
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
                <p style="margin:0 0 12px;color:#0B3D75;font-size:18px;font-weight:700;">Hola, {{ $usuario->nombre_usuario }}</p>
                <p style="margin:0 0 12px;">Recibimos una solicitud para restablecer la contraseña de tu cuenta. Pulsa el botón para elegir una nueva.</p>
                <p style="margin:20px 0;text-align:center;">
                    <a href="{{ $url }}" style="display:inline-block;background:#0A7BC4;color:#FFFFFF;text-decoration:none;font-weight:700;padding:12px 24px;border-radius:8px;">Restablecer contraseña</a>
                </p>
                <p style="margin:0 0 12px;">Este enlace vence en {{ $minutos }} minutos y solo se puede usar una vez.</p>
                <p style="margin:0 0 12px;color:#5B7287;font-size:13px;">Si el botón no funciona, copia y pega esta dirección en tu navegador:<br><span style="word-break:break-all;">{{ $url }}</span></p>
                <p style="margin:0;color:#5B7287;font-size:13px;">Si no solicitaste el cambio, ignora este correo: tu contraseña seguirá igual.</p>
            </td>
        </tr>
        <tr>
            <td style="background:#DCEFFB;padding:12px 24px;color:#0B3D75;font-size:13px;">Clínica Vida Salud · Gestión de citas médicas</td>
        </tr>
    </table>
</body>
</html>
