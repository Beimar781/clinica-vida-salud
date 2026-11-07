<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * P5 · Atención Clínica (consultas), documento v30, 6.3.2 y 6.3.3. La usan CU-16 (registrar consulta y
 * tratamiento) y CU-17 (consultar historia clínica). Una cita tiene como máximo una consulta (cita_id UNIQUE) y
 * toda consulta pertenece a una historia clínica. Fiel a database/schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE consultas (
   id                  BIGSERIAL PRIMARY KEY,
   cita_id             BIGINT       NOT NULL UNIQUE REFERENCES citas(id),
   historia_clinica_id BIGINT       NOT NULL REFERENCES historias_clinicas(id),
   medico_id           BIGINT       NOT NULL REFERENCES medicos(id),
   diagnostico         VARCHAR(300) NOT NULL,
   tratamiento         VARCHAR(400),
   observaciones       VARCHAR(400),
   created_at          TIMESTAMP NOT NULL DEFAULT now(),
   updated_at          TIMESTAMP NOT NULL DEFAULT now()
);

CREATE INDEX idx_consultas_medico   ON consultas (medico_id);
CREATE INDEX idx_consultas_historia ON consultas (historia_clinica_id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS consultas');
    }
};
