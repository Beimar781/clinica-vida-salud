<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de la bitácora (tabla bitacora). Solo lectura: las escrituras pasan por BitacoraService::registrar()
 * y la base de datos rechaza cualquier UPDATE o DELETE (trigger trg_bitacora_inmutable). Por eso el modelo
 * no admite asignación masiva ni define métodos de escritura.
 *
 * @CU CU-19 @RF RF19 @CE CEBitacora (tabla bitacora)
 */
class Bitacora extends Model
{
    protected $table = 'bitacora';

    public $timestamps = false;

    /** Ningún campo es asignable en masa. */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['fecha_hora' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
