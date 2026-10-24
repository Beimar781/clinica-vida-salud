{{-- Página de error autónoma (no consulta la base de datos ni la sesión): mensajes claros, sin detalles internos (RNF08). --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }} · Clínica Vida Salud</title>
    <link rel="stylesheet" href="@recurso('css/vidasalud.css')">
</head>
<body>
    <main class="contenedor">
        <section class="portada">
            <div class="portada__caja">
                <x-logo />
                <h1>{{ $titulo }}</h1>
                <p>{{ $mensaje }}</p>
                <div class="portada__acciones">
                    <a class="btn btn-primario" href="{{ url('/') }}">Volver al inicio</a>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
