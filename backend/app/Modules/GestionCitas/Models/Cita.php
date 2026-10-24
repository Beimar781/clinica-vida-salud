<?php

namespace App\Modules\GestionCitas\Models;

use App\Modules\GestionMedica\Models\Especialidad;
use App\Modules\GestionMedica\Models\Medico;
use App\Modules\GestionPacientes\Models\Paciente;
use App\Modules\Seguridad\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @CU CU-08 a CU-11 @RF RF08 a RF11 @CE CECita (tabla citas). El código CM-0001 sale de citas_codigo_seq.
 */
class Cita extends Model
{
    public const PROGRAMADA = 'PROGRAMADA';

    public const CONFIRMADA = 'CONFIRMADA';

    public const ATENDIDA = 'ATENDIDA';

    public const CANCELADA = 'CANCELADA';

    public const INASISTENCIA = 'INASISTENCIA';

    public const PRESENCIAL = 'PRESENCIAL';

    public const VIRTUAL = 'VIRTUAL';

    protected $table = 'citas';

    protected $fillable = [
        'codigo', 'paciente_id', 'medico_id', 'especialidad_id', 'fecha', 'hora_inicio', 'hora_fin',
        'modalidad', 'motivo', 'estado', 'motivo_cancelacion', 'recordatorio_enviado', 'creado_por',
    ];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'recordatorio_enviado' => 'boolean'];
    }

    /** Las citas canceladas no ocupan horario (WHERE estado <> 'CANCELADA' en las restricciones EXCLUDE). */
    public function ocupaHorario(): bool
    {
        return $this->estado !== self::CANCELADA;
    }

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    public function medico(): BelongsTo
    {
        return $this->belongsTo(Medico::class, 'medico_id');
    }

    public function especialidad(): BelongsTo
    {
        return $this->belongsTo(Especialidad::class, 'especialidad_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'creado_por');
    }
}
