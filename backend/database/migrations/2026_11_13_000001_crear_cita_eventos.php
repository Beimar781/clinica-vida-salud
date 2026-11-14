<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Extra aprobado (CU-10, CU-12, CU-13, CU-15): historial de cambios de cada cita, visible para el paciente en «Mis citas».
 * Solo crea una tabla nueva (no modifica ni borra nada existente). El historial de las citas anteriores se rellena a mano,
 * cuando se decida, con `php artisan citas:reconstruir-historial` (no corre durante el despliegue).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE cita_eventos (
   id              BIGSERIAL PRIMARY KEY,
   cita_id         BIGINT      NOT NULL REFERENCES citas(id) ON DELETE CASCADE,
   tipo            VARCHAR(20) NOT NULL CHECK (tipo IN ('REGISTRADA','CONFIRMADA','INASISTENCIA','REPROGRAMADA','CANCELADA','ATENDIDA','ESTADO_CORREGIDO')),
   fecha_anterior  DATE,
   hora_anterior   TIME,
   fecha_nueva     DATE,
   hora_nueva      TIME,
   estado_anterior VARCHAR(20),
   estado_nuevo    VARCHAR(20),
   motivo          VARCHAR(300),
   usuario_id      BIGINT REFERENCES usuarios(id),
   created_at      TIMESTAMP NOT NULL DEFAULT now()
);

CREATE INDEX idx_cita_eventos_cita ON cita_eventos (cita_id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS cita_eventos');
    }
};
