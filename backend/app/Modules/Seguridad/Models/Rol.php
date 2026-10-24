<?php

namespace App\Modules\Seguridad\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @CU CU-03 @RF RF03 @CE CERol (tabla roles)
 */
class Rol extends Model
{
    public const ADMINISTRADOR = 'Administrador';

    public const RECEPCIONISTA = 'Recepcionista';

    public const MEDICO = 'Médico';

    public const PACIENTE = 'Paciente';

    protected $table = 'roles';

    public $timestamps = false;

    protected $fillable = ['nombre', 'descripcion'];

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(Usuario::class, 'rol_usuario', 'rol_id', 'usuario_id');
    }
}
