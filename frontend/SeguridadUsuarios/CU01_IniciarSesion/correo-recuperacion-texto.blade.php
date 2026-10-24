{{-- @CU CU-01 (flujo alternativo A3: recuperar contraseña) @CI CILogin — versión en texto plano del correo --}}
Hola, {{ $usuario->nombre_mostrado }}

Recibimos una solicitud para restablecer la contraseña de tu cuenta en la Clínica Vida Salud.
Abre este enlace para elegir una nueva:

{{ $url }}

Este enlace vence en {{ $minutos }} minutos y solo se puede usar una vez.
Si no solicitaste el cambio, ignora este correo: tu contraseña seguirá igual.

Clínica Vida Salud
