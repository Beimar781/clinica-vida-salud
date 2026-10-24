<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @CU CU-01, CU-02, CU-03 @RF RF01, RF02, RF03 @CE CEUsuario (tabla usuarios; en los diagramas el atributo se llama «password»). Los permisos son la unión de los roles asignados (rol_usuario).
 */
class Usuario extends Authenticatable
{
    use Notifiable;

    protected $table = 'usuarios';

    protected $fillable = ['nombre_usuario', 'correo', 'password_hash', 'estado', 'ultimo_acceso'];

    protected $hidden = ['password_hash'];

    /** Igual que el DEFAULT TRUE de la tabla: una cuenta nueva nace activa también en memoria. */
    protected $attributes = ['estado' => true];

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'estado' => 'boolean',
            'ultimo_acceso' => 'datetime',
        ];
    }

    /**
     * Nombre de usuario para mostrarlo en pantalla con la primera letra en mayúscula (Admin). Solo cambia la
     * presentación: el valor guardado y el inicio de sesión (que ignora mayúsculas) no se tocan.
     */
    protected function nombreMostrado(): Attribute
    {
        return Attribute::get(fn () => Str::ucfirst((string) $this->nombre_usuario));
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

    /** Recuperar contraseña: el «email» del sistema de restablecimiento de Laravel es la columna correo. */
    public function getEmailForPasswordReset(): string
    {
        return $this->correo;
    }

    public function routeNotificationForMail(): string
    {
        return $this->correo;
    }

    /**
     * Busca la cuenta por correo (sin distinguir mayúsculas).
     *
     * @CU CU-01 (recuperar contraseña) @CE CEUsuario (buscarPorCorreo)
     */
    public static function buscarPorCorreo(string $correo): ?self
    {
        return static::whereRaw('lower(correo) = ?', [mb_strtolower(trim($correo))])->first();
    }

    /**
     * Busca la cuenta por correo o por nombre de usuario, sin distinguir mayúsculas.
     *
     * @CU CU-01 @CI CILogin @CC CCLogin @CE CEUsuario (buscarPorCorreo)
     */
    public static function buscarPorIdentificador(string $identificador): ?self
    {
        $identificador = mb_strtolower(trim($identificador));

        return static::whereRaw('lower(correo) = ?', [$identificador])->first()
            ?? static::whereRaw('lower(nombre_usuario) = ?', [$identificador])->first();
    }

    /**
     * Guarda la fecha y hora del último ingreso (no modifica updated_at).
     *
     * @CU CU-01, CU-02 @CE CEUsuario (registrarAcceso)
     */
    public function registrarAcceso(): void
    {
        static::whereKey($this->getKey())->update(['ultimo_acceso' => now()]);
        $this->ultimo_acceso = now();
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

    /**
     * True si el usuario tiene al menos uno de los roles indicados. El Administrador hereda las funciones de
     * recepción (Recepcionista): no se extiende a Médico ni a Paciente, que necesitan su propia ficha.
     */
    public function tieneRol(string ...$roles): bool
    {
        $propios = $this->nombresRoles();

        if ($propios->contains(Rol::ADMINISTRADOR) && in_array(Rol::RECEPCIONISTA, $roles, true)) {
            return true;
        }

        return $propios->intersect($roles)->isNotEmpty();
    }
}
