<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * P4 · Gestión de Citas (citas)
 * Fiel a database/schema.sql (documento, sección 6.3.3). SQL crudo para conservar
 * los CHECK, las restricciones EXCLUDE, las secuencias y el trigger tal como están definidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE citas (
   id                   BIGSERIAL PRIMARY KEY,
   codigo               VARCHAR(12) NOT NULL UNIQUE,         -- CM-0001 (secuencia citas_codigo_seq)
   paciente_id          BIGINT NOT NULL REFERENCES pacientes(id),
   medico_id            BIGINT NOT NULL REFERENCES medicos(id),
   especialidad_id      BIGINT NOT NULL REFERENCES especialidades(id),
   fecha                DATE NOT NULL,
   hora_inicio          TIME NOT NULL,
   hora_fin             TIME NOT NULL,
   modalidad            VARCHAR(10) NOT NULL DEFAULT 'PRESENCIAL'
                        CHECK (modalidad IN ('PRESENCIAL','VIRTUAL')),
   motivo               VARCHAR(200),
   estado               VARCHAR(15) NOT NULL DEFAULT 'PROGRAMADA'
                        CHECK (estado IN ('PROGRAMADA','CONFIRMADA','ATENDIDA','CANCELADA','INASISTENCIA')),
   motivo_cancelacion   VARCHAR(200),
   recordatorio_enviado BOOLEAN NOT NULL DEFAULT FALSE,
   creado_por           BIGINT REFERENCES usuarios(id),
   created_at           TIMESTAMP NOT NULL DEFAULT now(),
   updated_at           TIMESTAMP NOT NULL DEFAULT now(),
   CHECK (hora_fin > hora_inicio),
   -- un médico no puede tener citas superpuestas; las canceladas no ocupan horario
   EXCLUDE USING gist (
      medico_id WITH =,
      tsrange(fecha + hora_inicio, fecha + hora_fin, '[)') WITH &&
   ) WHERE (estado <> 'CANCELADA'),
   -- un paciente no puede tener dos citas activas en el mismo horario
   EXCLUDE USING gist (
      paciente_id WITH =,
      tsrange(fecha + hora_inicio, fecha + hora_fin, '[)') WITH &&
   ) WHERE (estado <> 'CANCELADA')
);

CREATE INDEX idx_citas_medico_fecha ON citas (medico_id, fecha);
CREATE INDEX idx_citas_paciente     ON citas (paciente_id);

DROP SEQUENCE IF EXISTS citas_codigo_seq;
CREATE SEQUENCE citas_codigo_seq START 1;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP SEQUENCE IF EXISTS citas_codigo_seq;
DROP TABLE IF EXISTS citas;
SQL);
    }
};
