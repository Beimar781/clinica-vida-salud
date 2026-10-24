<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * P3 · Gestión de Pacientes (pacientes, historias clínicas, antecedentes externos)
 * Fiel a database/schema.sql (documento, sección 6.3.3). SQL crudo para conservar
 * los CHECK, las restricciones EXCLUDE, las secuencias y el trigger tal como están definidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE pacientes (
   id                      BIGSERIAL PRIMARY KEY,
   usuario_id              BIGINT UNIQUE REFERENCES usuarios(id),
   codigo                  VARCHAR(10)  NOT NULL UNIQUE,   -- PAC-0001 (secuencia pacientes_codigo_seq)
   ci                      VARCHAR(20)  NOT NULL UNIQUE,
   nombres                 VARCHAR(80)  NOT NULL,
   apellidos               VARCHAR(80)  NOT NULL,
   fecha_nacimiento        DATE         NOT NULL,
   sexo                    CHAR(1)      CHECK (sexo IN ('M', 'F')),
   telefono                VARCHAR(20)  NOT NULL,
   correo                  VARCHAR(120),
   direccion               VARCHAR(200),
   emergencia_nombre       VARCHAR(100),
   emergencia_parentesco   VARCHAR(40),
   emergencia_telefono     VARCHAR(20),
   recibe_recordatorios    BOOLEAN NOT NULL DEFAULT TRUE,  -- notificaciones
   mostrar_contacto_medico BOOLEAN NOT NULL DEFAULT TRUE,  -- privacidad
   estado                  BOOLEAN NOT NULL DEFAULT TRUE
);

-- Historia clínica: se crea al registrar al paciente (una por paciente)
CREATE TABLE historias_clinicas (
   id                      BIGSERIAL PRIMARY KEY,
   paciente_id             BIGINT NOT NULL UNIQUE REFERENCES pacientes(id),
   fecha_apertura          DATE   NOT NULL DEFAULT CURRENT_DATE,
   tipo_sangre             VARCHAR(3) CHECK (tipo_sangre IN ('A+','A-','B+','B-','AB+','AB-','O+','O-')),
   antecedentes_personales VARCHAR(400),
   alergias                VARCHAR(200),
   enfermedades_cronicas   VARCHAR(300),
   medicamentos            VARCHAR(300),
   observaciones           VARCHAR(400),
   created_at              TIMESTAMP NOT NULL DEFAULT now(),
   updated_at              TIMESTAMP NOT NULL DEFAULT now()
);

-- Antecedentes de otros hospitales o clínicas
CREATE TABLE antecedentes_externos (
   id                  BIGSERIAL PRIMARY KEY,
   historia_clinica_id BIGINT       NOT NULL REFERENCES historias_clinicas(id),
   hospital_origen     VARCHAR(120) NOT NULL,
   fecha_atencion      DATE,
   especialidad        VARCHAR(80),
   diagnostico         VARCHAR(300) NOT NULL,
   tratamiento         VARCHAR(400),
   observaciones       VARCHAR(400),
   registrado_por      BIGINT REFERENCES usuarios(id),
   created_at          TIMESTAMP NOT NULL DEFAULT now()
);

CREATE INDEX idx_antecedentes_historia ON antecedentes_externos (historia_clinica_id);

DROP SEQUENCE IF EXISTS pacientes_codigo_seq;
CREATE SEQUENCE pacientes_codigo_seq START 1;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP SEQUENCE IF EXISTS pacientes_codigo_seq;
DROP TABLE IF EXISTS antecedentes_externos;
DROP TABLE IF EXISTS historias_clinicas;
DROP TABLE IF EXISTS pacientes;
SQL);
    }
};
