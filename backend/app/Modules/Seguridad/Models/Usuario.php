<?php

namespace App\Modules\Seguridad\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;

/**
 * @CU CU-01, CU-02, CU-03 @RF RF01, RF02, RF03 @CE CEUsuario (tabla usuarios; en los diagramas el atributo se llama «password»). Los permisos son la unión de los roles asignados (rol_usuario).
 */
class Usuario extends Authenticatable
{
    protected $table = 'usuarios';

    protected $fillable = ['nombre_usuario', 'correo', 'password_hash', 'estado', 'ultimo_acceso'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'estado' => 'boolean',
            'ultimo_acceso' => 'datetime',
        ];
    }

    /** La contraseña vive en la columna password_hash (no en «password»). */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** La tabla usuarios no tiene remember_token: no se usa «recordarme». */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Rol::class, 'rol_usuario', 'usuario_id', 'rol_id');
    }

    /** @return Collection<int, string> */
    public function nombresRoles(): Collection
    {
        if (! $this->relationLoaded('roles')) {
            $this->load('roles');
        }

        return $this->roles->pluck('nombre');
    }

    /** True si el usuario tiene al menos uno de los roles indicados. */
    public function tieneRol(string ...$roles): bool
    {
        return $this->nombresRoles()->intersect($roles)->isNotEmpty();
    }
}
