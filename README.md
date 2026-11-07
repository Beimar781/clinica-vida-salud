# Clínica Vida Salud · Gestión de citas médicas

Sistema web para gestionar las citas médicas de la Clínica Vida Salud: administración de usuarios, médicos, servicios, horarios y pacientes; registro, reprogramación, reserva en línea, cancelación y control de citas con cobro en efectivo o con tarjeta; atención clínica (consulta e historia clínica); reportes con resumen de IA; registro y perfil del paciente; y recordatorios por correo. Lo desarrollamos para la materia Ingeniería de Requisitos (INF-219-SC), Grupo 13, UAGRM, siguiendo el Proceso Unificado (PUDS) y UML 2.5.

**Sistema en línea:** https://clinica-vida-salud-production-aa9a.up.railway.app

Autores: Juan Simeón García Cervantes y Beimar Ríos Benavides.

## Estado del proyecto

| Ciclo | Casos de uso | Estado |
|---|---|---|
| Ciclo I | CU-01 a CU-11 (seguridad y usuarios, gestión médica, pacientes y citas) | Implementado y probado; ampliado en el documento v30 (servicios con precio, pago de citas y reprogramación de inasistencias pagadas) |
| Ciclo II | CU-12 a CU-22 (cancelación y estados de cita, agenda, historial, atención clínica, reportes con IA, bitácora, registro del paciente, perfil y recordatorios) | Implementado y probado (los 22 casos de uso) |

La prueba general del 07/11/2026 dio **541 pruebas y 3778 aserciones, todas aprobadas** también en un clon limpio del repositorio con una base nueva; tras los últimos ajustes del despliegue la suite completa da **544 pruebas y 3794 aserciones, todas aprobadas**. Además de los casos de uso, el sistema incluye la recuperación de contraseña por correo electrónico, el bloqueo temporal de la cuenta tras 3 intentos fallidos de inicio de sesión y un asistente de voz opcional en «Reportes».

## Qué hace el sistema

| Paquete | Casos de uso | Quién los usa |
|---|---|---|
| P1 Seguridad y usuarios | CU-01 Iniciar sesión · CU-02 Cerrar sesión · CU-03 Gestionar usuarios y roles | Todos los usuarios; la gestión de usuarios, el administrador |
| P2 Gestión médica | CU-04 Gestionar especialidades y servicios (con tipo y precio) · CU-05 Gestionar médicos · CU-06 Gestionar horarios y días sin atención | Administrador |
| P3 Gestión de pacientes | CU-07 Gestionar pacientes · CU-15 Consultar historial de citas del paciente («Mis citas») · CU-20 Registrarse como paciente · CU-21 Gestionar mi perfil | Administrador y recepcionista (CU-07 y CU-15); paciente (CU-15, CU-21); cualquier persona (CU-20) |
| P4 Gestión de citas | CU-08 Consultar disponibilidad · CU-09 Registrar cita · CU-10 Reprogramar cita · CU-11 Reservar cita en línea · CU-12 Cancelar cita · CU-13 Controlar asistencia y estados (confirmar, cobrar, atendida, inasistencia, corregir) · CU-14 Consultar agenda médica · CU-22 Enviar recordatorios de citas | Recepcionista y administrador; paciente (consultar, reservar y cancelar las suyas); médico (control y agenda); el sistema (recordatorios) |
| P5 Atención clínica | CU-16 Registrar consulta y tratamiento · CU-17 Consultar historia clínica | Médico |
| P6 Reportes y bitácora | CU-18 Generar reportes con IA (ocho reportes, CSV, PDF y asistente de voz) · CU-19 Consultar bitácora | Administrador y recepcionista (CU-18); administrador (CU-19) |

Roles del sistema: Administrador, Recepcionista, Médico y Paciente. Una misma cuenta puede tener más de un rol (los permisos son la unión de sus roles); quien se registra solo desde la web recibe únicamente el rol Paciente. El Administrador hereda las funciones de recepción (pacientes, disponibilidad, registro y reprogramación de citas), por eso la cuenta `admin` tiene solo el rol Administrador; no hereda las del médico ni las del paciente (necesitan su propia ficha), así que no usa «Reservar cita».

**Reglas de negocio y seguridad que implementamos**

- Las contraseñas se guardan con hash bcrypt. Se puede ingresar con el correo o con el nombre de usuario.
- Después de 3 intentos fallidos en una cuenta, el inicio de sesión se bloquea 60 segundos. Los mensajes de error son genéricos para no revelar si la cuenta existe.
- Recuperación de contraseña por correo: enlace de un solo uso que vence a los 60 minutos (flujo alternativo A3 de CU-01).
- Una cita no puede cruzarse con otra del mismo médico ni caer fuera de su horario o en un día sin atención. Además de validarlo en la aplicación, la base de datos lo garantiza con una restricción `EXCLUDE USING gist` (extensión `btree_gist`), y el registro se hace dentro de una transacción con bloqueo del médico (`lockForUpdate`), de modo que dos personas no puedan reservar el mismo horario al mismo tiempo.
- Códigos correlativos para citas, pacientes y médicos (CM-0001, PAC-0001, MED-0001).
- Bitácora de operaciones inmutable: un trigger de PostgreSQL impide modificar o borrar sus registros.
- Acceso por rol (middleware `rol`), cuentas desactivadas sin acceso y páginas protegidas sin caché del navegador.
- Pagos: cada servicio tiene un precio en bolivianos y la cita guarda el monto del momento. Se paga en efectivo (queda Pendiente hasta que la recepción registra el cobro) o con tarjeta mediante Stripe Checkout alojado: el pago se autoriza, se registra la cita dentro de la transacción y solo entonces se captura; el sistema nunca ve ni guarda datos de tarjeta, solo la referencia. No hay devoluciones.
- Estados de la cita: Programada → Confirmada o Cancelada; Confirmada → Atendida, Inasistencia o Cancelada; una inasistencia pagada se reprograma sin nuevo cobro. El paciente solo cancela sus citas y con 2 horas o más de anticipación; solo el administrador corrige un estado, con justificación.
- La historia clínica la crea el sistema con el paciente (una por paciente) y solo la consulta el médico, en solo lectura; cada consulta queda en la bitácora sin datos clínicos.
- Reportes con IA: las cifras las calcula siempre el sistema; el resumen opcional lo redacta Google Gemini recibiendo solo totales (nunca nombres, CI ni datos clínicos), y si falla el reporte sale igual, sin resumen.
- Recordatorios: una tarea diaria envía un correo por paciente con sus citas del día siguiente (si tiene correo y los recordatorios activados).

## Tecnologías

- PHP 8.3 y Laravel 13 (versión instalada: 13.38)
- PostgreSQL 16 o superior (extensión `btree_gist`)
- Blade, HTML5, CSS3 y JavaScript (sin Node ni CDN)
- Correo en producción con la API web de Brevo
- Pagos con la API REST de Stripe (Checkout alojado, modo de prueba), sin SDK ni scripts de Stripe
- Resumen de reportes con la API de Google Gemini (opcional) y PDF con dompdf (barryvdh/laravel-dompdf), sin recursos externos
- Despliegue en Railway con Docker

## Requisitos para ejecutarlo

- PHP 8.3 o superior con las extensiones `pdo_pgsql`, `pgsql`, `mbstring`, `zip` y `fileinfo` (`intl` es opcional)
- Composer
- PostgreSQL 16 o superior, con la extensión `btree_gist` disponible

## Instalación

El proyecto tiene dos carpetas en la raíz, dentro de un solo proyecto Laravel: `backend/` (código PHP, base de datos y pruebas) y `frontend/` (vistas Blade, CSS, JavaScript e imágenes). Los comandos se ejecutan en `backend/`.

```bash
git clone https://github.com/Beimar781/clinica-vida-salud.git
cd clinica-vida-salud/backend
composer install
copy .env.example .env        # en Linux o macOS: cp .env.example .env
php artisan key:generate
```

Crear la base de datos `clinica_vida_salud` en PostgreSQL, completar `DB_PASSWORD` en `backend/.env` (`ADMIN_PASSWORD` es opcional, ver «Usuarios de demostración») y ejecutar:

```bash
php artisan migrate --seed
php artisan serve
```

El sistema queda disponible en http://localhost:8000. No necesita Node ni conexión a internet: los estilos, íconos y scripts están en `frontend/assets` y los sirve el propio servidor de Laravel.

### Pago con tarjeta (opcional)

Al registrar o reservar una cita se puede pagar en efectivo en la clínica (siempre disponible) o con tarjeta mediante Stripe Checkout, en modo de prueba. Para activar la tarjeta, en `backend/.env` ponga la clave secreta de prueba de https://dashboard.stripe.com/test/apikeys (la que empieza con `sk_test_`); sin ella la opción de tarjeta aparece deshabilitada y el efectivo sigue funcionando:

```
STRIPE_SECRET=sk_test_...
STRIPE_MONEDA=bob    # los montos están en bolivianos; si la cuenta de Stripe rechazara BOB: usd
TIPO_CAMBIO=1        # con STRIPE_MONEDA=usd use 6.96
```

Tarjetas de prueba de Stripe: `4242 4242 4242 4242` (aprobada) y `4000 0000 0000 0002` (rechazada), con cualquier fecha futura y cualquier CVC. El sistema nunca ve ni guarda datos de tarjeta: la persona los escribe en la página de Stripe y aquí solo queda la referencia del pago.

En Windows, si al pagar con tarjeta aparece el aviso «No se pudo procesar el pago con tarjeta» y en `backend/storage/logs/laravel.log` dice `cURL error 60: SSL certificate`, PHP no tiene los certificados raíz: descargue `cacert.pem` de https://curl.se/ca/cacert.pem y en su `php.ini` configure `curl.cainfo` y `openssl.cafile` con la ruta de ese archivo.

### Reportes con resumen de IA (opcional)

«Reportes» (CU-18) funciona siempre; la modalidad «Con IA» agrega un resumen redactado por Google Gemini y solo necesita una clave gratuita de https://aistudio.google.com/apikey en `backend/.env`. Sin clave, o si el servicio falla o tarda más de 20 s, el reporte se muestra completo con el aviso «No se pudo generar el resumen con IA»:

```
GEMINI_API_KEY=su_clave
IA_MODELO=gemini-3.5-flash-lite   # rápido y económico; Google retira modelos con el tiempo: si responde 404, elija uno vigente
```

A la IA solo se le envían el tipo de reporte, el período y los totales (cantidades e ingresos, y el total de cada médico y de cada especialidad): nunca nombres, CI, correos, teléfonos ni datos clínicos de los pacientes. El PDF se genera con dompdf, sin recursos externos. «Reportes» incluye además un asistente de voz opcional (dictar o escribir una frase y escuchar los totales) que usa las voces del navegador; el dictado funciona en Chrome o Edge. En Windows, la llamada a la IA necesita los mismos certificados raíz de PHP que el pago con tarjeta (ver arriba).

### Recordatorios de citas (CU-22)

Una vez al día el sistema avisa por correo de las citas Programadas o Confirmadas del día siguiente (un correo por paciente, solo si tiene correo y los recordatorios activados en «Mi perfil»). Para probarlo en local, con `MAIL_MAILER=log` en `backend/.env`, ejecute dentro de `backend/`:

```
php artisan citas:enviar-recordatorios                    # las citas de mañana
php artisan citas:enviar-recordatorios --fecha=2026-11-10 # las de un día concreto (AAAA-MM-DD)
```

El comando muestra cuántos avisos se enviaron, cuántas citas se omitieron y cuántos fallaron, y el correo queda escrito en `backend/storage/logs/laravel.log` (busque «Recordatorio de tu cita»). Cada cita avisada queda marcada: volver a ejecutarlo no repite el aviso; si un envío falla, esa cita se reintenta en la siguiente ejecución. El comando está programado a las 18:00 (hora de La Paz) en `routes/console.php`, pero la programación solo corre si algo ejecuta `php artisan schedule:run` cada minuto (en local: `php artisan schedule:work`). En Railway hace falta un **servicio cron aparte**, con el mismo Dockerfile y las mismas variables, que ejecute `php artisan citas:enviar-recordatorios` una vez al día; en producción el correo sale por Brevo (`MAIL_MAILER=brevo`).

## Usuarios de demostración

`php artisan migrate --seed` crea un usuario por rol. Todas las cuentas de demostración, incluida la del administrador, usan la contraseña `Demo1234*`. Si se define `ADMIN_PASSWORD` (en `backend/.env` o en las variables del servicio), el administrador usa esa contraseña en lugar de `Demo1234*`.

| Usuario | Correo | Roles | Contraseña |
|---|---|---|---|
| admin | admin@vidasalud.test | Administrador | `Demo1234*` (o `ADMIN_PASSWORD` si está definida) |
| recepcion | recepcion@vidasalud.test | Recepcionista | `Demo1234*` |
| cmontano | carla.montano@vidasalud.test | Médico | `Demo1234*` |
| lsoliz | luis.soliz@vidasalud.test | Médico | `Demo1234*` |
| pvargas | patricia.vargas@vidasalud.test | Médico y Paciente | `Demo1234*` |
| mrojas | maria.rojas@correo.test | Paciente | `Demo1234*` |
| jperez | juan.perez@correo.test | Paciente | `Demo1234*` |

También se cargan 7 especialidades y servicios con precio (5 de consulta, laboratorio y enfermería), 7 médicos y profesionales con horarios, 9 pacientes (6 sin cuenta de usuario; el CI 8123456, Ana Lucía Gutiérrez Parada, nacida el 15/06/1990, sirve para probar «Crear cuenta» con la vinculación de una ficha existente), 3 días sin atención, 3 consultas con su historia clínica, 2 antecedentes de otros hospitales y 16 citas en todos los estados y con pagos en efectivo y con tarjeta (las fechas se calculan desde el día en que se siembra).

Para reiniciar los datos de demostración, desde `backend/`:

```bash
php artisan migrate:fresh --seed
```

Borra todas las tablas y las vuelve a crear con los datos de demostración (tarda unos 2 segundos; los códigos CM-0001, PAC-0001 y MED-0001 vuelven a empezar). Si se prefiere deshacer paso a paso: `php artisan migrate:rollback` y luego `php artisan migrate --seed`. Ambos caminos funcionan aunque pgAdmin tenga la base abierta.

## Recuperar la contraseña

En el inicio de sesión, «¿Olvidaste tu contraseña?» pide el correo de la cuenta y envía un enlace de un solo uso que vence a los 60 minutos. El sistema responde lo mismo exista o no el correo, y limita las solicitudes repetidas.

- **En local** `MAIL_MAILER=log` no envía correos de verdad: el enlace se lee en `backend/storage/logs/laravel.log`, en la línea que dice «ENLACE DE RECUPERACION».
- **En producción (Railway)** el correo sale por la API de Brevo (`App\Support\BrevoTransport`) con `MAIL_MAILER=brevo`, `BREVO_API_KEY` y `MAIL_FROM_ADDRESS` (el remitente verificado en Brevo). Usamos la API web y no SMTP porque Railway bloquea los puertos de correo SMTP.

## Pruebas

Las pruebas usan la misma base de datos del sistema (revierten sus cambios al terminar, así que no borran los datos de demostración). Primero hay que haber ejecutado `php artisan migrate --seed`:

```bash
cd backend
php artisan test
```

La última corrida completa (07/11/2026) dio **541 pruebas y 3778 aserciones, todas aprobadas** en el proyecto de trabajo y en un clon limpio del repositorio con una base nueva, siguiendo solo estas instrucciones; después del despliegue la suite completa da **544 pruebas y 3794 aserciones**. Hay un archivo de pruebas por caso de uso (`CUxx_NombreTest.php`) con un método `paso_N_...` por cada fila del plan de pruebas 8.1 del documento y pruebas de reglas internas, más pruebas de la base de datos, la seguridad, los pagos, los recursos del frontend y el despliegue. Stripe, la IA y el correo siempre se simulan: ninguna prueba sale a internet. Se puede correr un solo caso de uso con `php artisan test --filter=CU09_`. La relación entre cada requisito, su código y su prueba está en `docs/TRAZABILIDAD.md`.

## Despliegue en Railway

El repositorio trae un `Dockerfile` en la raíz (PHP 8.3 con `pdo_pgsql`, `pgsql`, `zip` e `intl`; Composer `--no-dev`; `memory_limit=256M` para el PDF de los reportes) y un `.dockerignore`. El PDF (dompdf) solo necesita `dom` y `mbstring`, que ya trae la imagen de PHP: no hace falta la extensión `gd`. El propio Dockerfile comprueba al construir que las extensiones están. Railway construye una sola imagen con todo el repositorio (`backend/` lee la carpeta hermana `frontend/`, por eso el servicio no usa `backend/` como directorio raíz). Al arrancar, el contenedor crea las carpetas de `storage`, migra la base (las migraciones nuevas se aplican sobre la base existente sin perder datos), carga los datos base y enciende el servidor en el puerto que asigna Railway. Los seeders son idempotentes: pueden correr en cada arranque sin duplicar nada.

**Cómo lo desplegamos**

1. En Railway: **New Project → Deploy from GitHub repo** y elegir `Beimar781/clinica-vida-salud`. Railway detecta el `Dockerfile` de la raíz. No hace falta cambiar el comando de inicio ni el directorio raíz.
2. En el mismo proyecto: **+ New → Database → Add PostgreSQL**. El usuario de ese servicio puede ejecutar `CREATE EXTENSION btree_gist`, que usa la primera migración.
3. En el servicio web, pestaña **Settings → Networking → Generate Domain**.
4. En el servicio web, pestaña **Variables**, crear las de la tabla siguiente (las `DB_*` se enlazan al servicio de PostgreSQL con la sintaxis `${{Postgres.NOMBRE}}`; si el servicio se llama distinto, cambiar `Postgres` por ese nombre). `APP_URL` lleva el dominio del paso 3.
5. Opcional: en **Settings → Deploy**, poner `/up` como ruta de salud (responde 200 cuando la aplicación está viva).
6. Mantener **una sola réplica**: el bloqueo de 60 s tras 3 intentos fallidos usa la caché de archivos de esa instancia (si Railway reinicia el contenedor, el contador se reinicia y no pasa nada más).

| Variable | Valor (solo ejemplos, sin valores reales) |
|---|---|
| `APP_NAME` | `Clínica Vida Salud` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | resultado de `php artisan key:generate --show` (empieza con `base64:`) |
| `APP_URL` | `https://<dominio>.up.railway.app` (con `https://`) |
| `APP_LOCALE` | `es` |
| `ADMIN_PASSWORD` | contraseña del administrador (opcional: si falta, el administrador usa `Demo1234*`) |
| `ADMIN_CORREO` | opcional; correo real del administrador (por defecto `admin@vidasalud.test`) |
| `SEMBRAR_DEMO` | `true` para cargar los datos de demostración; `false` (o sin definir) para dejar solo roles, especialidades base y administrador |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` | `${{Postgres.PGHOST}}` |
| `DB_PORT` | `${{Postgres.PGPORT}}` |
| `DB_DATABASE` | `${{Postgres.PGDATABASE}}` |
| `DB_USERNAME` | `${{Postgres.PGUSER}}` |
| `DB_PASSWORD` | `${{Postgres.PGPASSWORD}}` |
| `SESSION_DRIVER` | `file` |
| `CACHE_STORE` | `file` |
| `QUEUE_CONNECTION` | `sync` |
| `SESSION_SECURE_COOKIE` | `true` |
| `LOG_CHANNEL` | `stderr` (los errores salen en los logs de Railway) |
| `MAIL_MAILER` | `brevo` |
| `BREVO_API_KEY` | clave de API de Brevo (empieza con `xkeysib-`) |
| `MAIL_FROM_ADDRESS` | el remitente verificado en Brevo |
| `MAIL_FROM_NAME` | `Clínica Vida Salud` |
| `STRIPE_SECRET` | clave secreta de **prueba** de Stripe (`sk_test_...`, de https://dashboard.stripe.com/test/apikeys). Sin ella, la tarjeta aparece deshabilitada y el efectivo sigue funcionando |
| `STRIPE_MONEDA` | `bob` (los montos están en bolivianos; si la cuenta de Stripe rechazara BOB: `usd`) |
| `TIPO_CAMBIO` | `1` (con `STRIPE_MONEDA=usd`, `6.96`) |
| `GEMINI_API_KEY` | clave de Google Gemini (https://aistudio.google.com/apikey) para el resumen de los reportes. Sin ella el reporte sale completo, sin resumen y con el aviso «No se pudo generar el resumen con IA» |
| `IA_MODELO` | `gemini-3.5-flash-lite`. Google retira modelos con el tiempo: `gemini-2.5-flash` ya no está disponible para claves nuevas. Si esta variable apunta a un modelo retirado, el sistema reintenta una vez con el modelo por defecto, pero conviene corregirla (o borrarla) |

No hay que definir `PORT`: lo asigna Railway. Las claves y contraseñas viven solo en las variables de Railway; nunca se suben al repositorio.

**Actualizar un despliegue que ya existe con el Ciclo II**

1. **Respaldo de la base** antes de cambiar nada: en el servicio PostgreSQL de Railway, pestaña **Data** o **Backups** (o con `pg_dump` desde su cadena de conexión pública).
2. Cargar las variables nuevas del servicio web (`STRIPE_SECRET`, `STRIPE_MONEDA`, `TIPO_CAMBIO`, `GEMINI_API_KEY`, `IA_MODELO`) y revisar que `IA_MODELO` no siga en `gemini-2.5-flash`.
3. Redesplegar el servicio web (basta un `git push` a `main`, o **Deployments → Redeploy**). Las 4 migraciones del v30 (`tipo` y `precio`, `registrado_por`, datos de pago de `citas` y la tabla `consultas`) se aplican solas al arrancar sin perder datos: las especialidades antiguas quedan como consulta y reciben su precio base, las citas antiguas quedan en efectivo pendiente con el precio de su especialidad, y los días sin atención quedan a nombre del administrador. En **View logs** deben verse las migraciones `2026_11_06_...` con `DONE` (o `Nothing to migrate` si ya estaban).
4. Con `SEMBRAR_DEMO=true` se agregan los datos de demostración del v30 (idempotente: no duplica nada). Para datos reales, `SEMBRAR_DEMO=false`.
5. Crear el servicio cron de los recordatorios (siguiente apartado).

**Servicio cron de los recordatorios (CU-22)**

La tarea programada del sistema solo corre si algo la ejecuta; en Railway se resuelve con un segundo servicio que ejecuta el comando una vez al día y termina:

1. En el mismo proyecto: **+ New → GitHub Repo** y elegir el mismo repositorio (usa el mismo `Dockerfile`).
2. **Settings → Deploy → Custom Start Command**:
   ```
   sh -c "mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && php artisan package:discover --ansi && php artisan citas:enviar-recordatorios"
   ```
   No lleva `migrate` ni `serve`: lo migra el servicio web.
3. **Settings → Cron Schedule**: `0 22 * * *`. Railway usa hora UTC y La Paz es UTC−4 sin horario de verano, así que 22:00 UTC son las **18:00 en La Paz**, la hora que usa el sistema. (Opcional, para reintentar los envíos fallidos: un segundo horario `0 0 * * *`, las 20:00 en La Paz; el comando no repite los avisos ya enviados.)
4. **Variables**: las mismas que el servicio web (enlazarlas con `${{web.NOMBRE}}`, cambiando `web` por el nombre de ese servicio): `APP_KEY`, `APP_ENV`, `APP_URL`, `DB_*`, `MAIL_MAILER`, `BREVO_API_KEY`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` y `LOG_CHANNEL=stderr`.
5. Probarlo sin esperar: **Deployments → Run now** (o `railway run` con la CLI de Railway) y leer en **View logs** la línea «Recordatorios para el dd/mm/aaaa: N avisos enviados…». También sirve `php artisan citas:enviar-recordatorios --fecha=AAAA-MM-DD` para un día concreto.

**Qué revisar en la URL en línea después de desplegar**

- En **Deployments → View logs**: las migraciones (`Running migrations`), el sembrado (`Seeding database`) y la línea `Server running on [http://0.0.0.0:...]`.
- Con `admin`: el panel muestra las cinco tarjetas (Seguridad y Usuarios, Gestión Médica, Gestión de Pacientes, Gestión de Citas, Reportes y bitácora) y «Especialidades y servicios» muestra tipo y precio en Bs.
- Inicio de sesión: tras 3 intentos fallidos con una cuenta, el siguiente muestra el bloqueo de 60 segundos. «¿Olvidaste tu contraseña?» envía el correo desde el remitente verificado en Brevo (puede llegar a spam) y el enlace abre con `https`.
- Con `recepcion`: registrar una cita en efectivo (queda Pendiente), cobrarla en «Control de citas», reprogramar y cancelar otra (la cita pagada avisa que el pago no se devuelve).
- Con `mrojas`: reservar una cita pagando con la tarjeta de prueba `4242 4242 4242 4242` (comprobante «Pagado» con referencia) y probar `4000 0000 0000 0002` (rechazada); «Mis citas», «Mi perfil» y cancelar con más de 2 horas de anticipación.
- Con `cmontano`: agenda, registrar la consulta de una cita y ver la historia clínica; `recepcion` y `admin` no ven la historia (acceso denegado).
- Con `admin`: «Reportes» con la modalidad **Con IA** muestra el «Resumen generado por IA» (si aparece «No se pudo generar el resumen con IA», revisar `GEMINI_API_KEY` y `IA_MODELO`), exportar PDF y CSV, y «Bitácora» con los eventos.
- Sin sesión: «Crear cuenta» con un CI nuevo (o el CI `8123456` y la fecha `15/06/1990` para vincular la ficha de demostración).
- El recordatorio: ejecutar el servicio cron a mano y comprobar que llega el correo (el paciente debe tener correo y los recordatorios activados).
- `https://<dominio>/up` responde 200 y todo sale por `https`.

**Nota sobre los datos de demostración:** como el repositorio es público, las contraseñas `Demo1234*` son conocidas. Para usar el sistema con datos reales hay que poner `SEMBRAR_DEMO=false`, definir un `ADMIN_PASSWORD` propio y desactivar o cambiar la contraseña de las cuentas de demostración desde «Usuarios y roles». El administrador se crea una sola vez: cambiar `ADMIN_PASSWORD` después no modifica su contraseña, eso se hace desde «Usuarios y roles».

## Cómo está organizado el código

El repositorio es un solo proyecto Laravel dividido en **backend** (lógica y datos) y **frontend** (pantallas). Cada **paquete** del documento es una carpeta con el mismo nombre en las dos partes, y cada **caso de uso** es una subcarpeta (`CUxx_NombreDelCaso`):

```
clinica-vida-salud/
├── backend/                          ← proyecto Laravel (lógica, base de datos, rutas, pruebas)
│   ├── app/Modules/
│   │   ├── SeguridadUsuarios/        ← P1: Seguridad y Usuarios
│   │   │   ├── routes.php            ← URLs del paquete, agrupadas por CU
│   │   │   ├── CU01_IniciarSesion/   ← CU-01: LoginController, PanelController, OlvidoClaveController, RestablecerClaveController, LoginService, RecuperarClaveService
│   │   │   ├── CU02_CerrarSesion/    ← CU-02: LogoutController, LogoutService
│   │   │   └── CU03_GestionarUsuariosRoles/  ← CU-03: UsuariosController, UsuarioRequest, UsuarioService
│   │   ├── GestionMedica/            ← P2: CU04_..., CU05_..., CU06_...
│   │   ├── GestionPacientes/         ← P3: CU07_..., CU15_..., CU20_..., CU21_...
│   │   ├── GestionCitas/             ← P4: CU08_... a CU14_..., CU22_ (comando de recordatorios) y Compartido/ (disponibilidad, agendado, pagos y estados)
│   │   ├── AtencionClinica/          ← P5: CU16_RegistrarConsultaTratamiento y CU17_ConsultarHistoriaClinica
│   │   ├── ReportesBitacora/         ← P6: CU18_GenerarReportesConIA (ReportesController, ReporteRequest, ReporteService, ResumenIAService) y CU19_ConsultarBitacora (BitacoraController, BitacoraRequest, ConsultaBitacoraService)
│   │   └── README.md                 ← tabla de paquetes y casos de uso
│   ├── app/Models/                   ← una clase por tabla del modelo de datos (CE del análisis)
│   ├── app/Services/                 ← BitacoraService (registro de operaciones)
│   ├── app/Http/Middleware/          ← VerificarRol, VerificarCuentaActiva, SinCache
│   ├── app/Support/                  ← utilidades: Codigos (CM-0001), Dinero (Bs 100,00), Texto, PanelPrincipal, Recursos, BrevoTransport
│   ├── database/                     ← migraciones, seeders y schema.sql (modelo físico)
│   └── tests/Feature/                ← una prueba por caso de uso (CUxx_NombreTest.php)
├── frontend/                         ← pantallas (Blade + CSS + JavaScript, sin Node ni CDN)
│   ├── SeguridadUsuarios/ · GestionMedica/ · GestionPacientes/ · GestionCitas/ · AtencionClinica/ · ReportesBitacora/   ← pantalla de cada CU, misma ruta que en backend
│   ├── layouts/ · components/ · errors/  ← diseño general, componentes (ícono, logo, campo, estado de cita y de pago, tipo de servicio) y pantallas de error
│   └── assets/                       ← css/vidasalud.css (común) y css/modulos/, js/, img/
├── Dockerfile · .dockerignore        ← imagen para el despliegue en Railway (ver la sección anterior)
└── docs/                             ← documento (PDF), trazabilidad, clases de análisis y lista de capturas de la sección 4.4
```

Dentro de cada CU del backend hay, según corresponda:

| Archivo | Qué hace |
|---|---|
| `...Controller.php` | La clase de control (CC) del documento. Los comentarios del inicio repiten el flujo de la ficha y dicen qué método cumple cada paso |
| `...Request.php` | Validaciones del formulario en el servidor (campos obligatorios, formatos, duplicados) |
| `...Service.php` | Reglas de negocio del caso de uso (las que el controlador delgado delega) |

Los comentarios `@CU`, `@RF`, `@CI`, `@CC` y `@CE` en cada clase y pantalla enlazan el código con los nombres exactos de los diagramas de análisis (`docs/CLASES_ANALISIS.md`). La pantalla del mismo CU está en `frontend/<Paquete>/<CU>/*.blade.php`.

**Recorrido de un caso de uso** (ejemplo CU-03, registrar un usuario): el administrador abre `/usuarios/crear` → la ruta está en `backend/app/Modules/SeguridadUsuarios/routes.php` (bloque «CU-03») → llama a `UsuariosController::crear()`, que muestra `frontend/SeguridadUsuarios/CU03_GestionarUsuariosRoles/crear.blade.php` → al enviar el formulario, `UsuariosController::guardar()` valida con `UsuarioRequest` (correo y usuario únicos, contraseña de 8 caracteres o más, al menos un rol) → `UsuarioService::guardar()` guarda con los modelos `Usuario` y `Rol` (contraseña con hash) y deja registro en la bitácora con `BitacoraService` → vuelve al listado `index.blade.php` con el aviso verde de confirmación.
