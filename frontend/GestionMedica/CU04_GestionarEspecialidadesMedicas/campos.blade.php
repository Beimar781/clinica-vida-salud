{{-- @CU CU-04 @RF RF04 @CI CIEspecialidad (mostrarFormulario) @CC CCEspecialidad @CE CEEspecialidad
     Campos que comparten crear.blade.php y editar.blade.php. Variable opcional: $especialidad (solo al editar). --}}
<div class="formulario">
    <x-campo nombre="nombre" etiqueta="Nombre de la especialidad" :valor="$especialidad->nombre ?? null"
             required maxlength="80" autocomplete="off" autofocus />
    <x-areatexto nombre="descripcion" etiqueta="Descripción (opcional)" :valor="$especialidad->descripcion ?? null"
                 maxlength="200" rows="3" />
</div>
