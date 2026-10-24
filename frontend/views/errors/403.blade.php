@include('errors.pagina', ['titulo' => 'Acceso denegado', 'mensaje' => ($exception->getMessage() ?: 'No tiene permiso para acceder a esta sección.')])
