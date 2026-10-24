<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * P2 · Gestión Médica (especialidades, médicos, horarios, bloqueos)
 * Fiel a database/schema.sql (documento, sección 6.3.3). SQL crudo para conservar
 * los CHECK, las restricciones EXCLUDE, las secuencias y el trigger tal como están definidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE TABLE especialidades (
   id                 BIGSERIAL PRIMARY KEY,
   nombre             VARCHAR(80) NOT NULL,
   nombre_normalizado VARCHAR(80) NOT NULL UNIQUE,   -- minúsculas, sin tildes, sin espacios sobrantes
   descripcion        VARCHAR(200),
   estado             BOOLEAN     NOT NULL DEFAULT TRUE
);

CREATE TABLE medicos (
   id                    BIGSERIAL PRIMARY KEY,
   usuario_id            BIGINT UNIQUE REFERENCES usuarios(id),
   codigo                VARCHAR(10) NOT NULL UNIQUE,    -- MED-0001 (secuencia medicos_codigo_seq)
   nro_registro          VARCHAR(30) NOT NULL UNIQUE,
   nombres               VARCHAR(80) NOT NULL,
   apellidos             VARCHAR(80) NOT NULL,
   telefono              VARCHAR(20),
   correo                VARCHAR(120),
   ubicacion             VARCHAR(100),                   -- consultorio o sede (búsqueda por ubicación)
   puede_marcar_atendida BOOLEAN NOT NULL DEFAULT TRUE,  -- permiso para marcar citas como atendidas
   estado                BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE medico_especialidad (
   medico_id       BIGINT NOT NULL REFERENCES medicos(id),
   especialidad_id BIGINT NOT NULL REFERENCES especialidades(id),
   PRIMARY KEY (medico_id, especialidad_id)
);

CREATE TABLE horarios_atencion (
   id           BIGSERIAL PRIMARY KEY,
   medico_id    BIGINT   NOT NULL REFERENCES medicos(id),
   dia_semana   SMALLINT NOT NULL CHECK (dia_semana BETWEEN 1 AND 7),   -- 1 = lunes ... 7 = domingo
   hora_inicio  TIME     NOT NULL,
   hora_fin     TIME     NOT NULL,
   duracion_min SMALLINT NOT NULL CHECK (duracion_min > 0),
   estado       BOOLEAN  NOT NULL DEFAULT TRUE,
   CHECK (hora_fin > hora_inicio),
   -- un médico no puede tener bloques superpuestos el mismo día
   EXCLUDE USING gist (
      medico_id  WITH =,
      dia_semana WITH =,
      tsrange(DATE '2000-01-01' + hora_inicio, DATE '2000-01-01' + hora_fin, '[)') WITH &&
   ) WHERE (estado)
);

-- Feriados y días sin atención (se excluyen de la agenda)
CREATE TABLE bloqueos_agenda (
   id         BIGSERIAL PRIMARY KEY,
   fecha      DATE         NOT NULL UNIQUE,
   motivo     VARCHAR(120) NOT NULL,
   estado     BOOLEAN      NOT NULL DEFAULT TRUE,
   created_at TIMESTAMP    NOT NULL DEFAULT now()
);

DROP SEQUENCE IF EXISTS medicos_codigo_seq;
CREATE SEQUENCE medicos_codigo_seq START 1;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP SEQUENCE IF EXISTS medicos_codigo_seq;
DROP TABLE IF EXISTS bloqueos_agenda;
DROP TABLE IF EXISTS horarios_atencion;
DROP TABLE IF EXISTS medico_especialidad;
DROP TABLE IF EXISTS medicos;
DROP TABLE IF EXISTS especialidades;
SQL);
    }
};
