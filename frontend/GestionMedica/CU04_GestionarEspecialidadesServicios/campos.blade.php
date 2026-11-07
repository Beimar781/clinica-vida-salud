{{-- @CU CU-04 @RF RF04 @CI CIEspecialidad (mostrarFormulario) @CC CCEspecialidad @CE CEEspecialidad
     Campos que comparten crear.blade.php y editar.blade.php. Variable opcional: $especialidad (solo al editar). --}}
<div class="formulario">
    <x-campo nombre="nombre" etiqueta="Nombre" :valor="$especialidad->nombre ?? null"
             required maxlength="80" autocomplete="off" autofocus
             ayuda="Ej.: Cardiología, Laboratorio. No puede repetirse (se compara sin mayúsculas ni tildes)." />
    <x-seleccion nombre="tipo" etiqueta="Tipo" :opciones="\App\Models\Especialidad::TIPOS" :valor="$especialidad->tipo ?? null"
                 vacio="Seleccione el tipo" required />
    <x-campo nombre="precio" etiqueta="Precio (Bs)" tipo="number" :valor="isset($especialidad) ? $especialidad->precio : null"
             required min="0" max="999999.99" step="0.01" inputmode="decimal"
             ayuda="En bolivianos, hasta dos decimales (puede ser 0). Cambiarlo no altera las citas ya registradas." />
    <x-areatexto nombre="descripcion" etiqueta="Descripción (opcional)" :valor="$especialidad->descripcion ?? null"
                 maxlength="200" rows="3" />
</div>
