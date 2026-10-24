<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Bitácora (transversal desde el Ciclo I; solo agregar, sin UPDATE ni DELETE)
 * Fiel a database/schema.sql (documento, sección 6.3.3). SQL crudo para conservar
 * los CHECK, las restricciones EXCLUDE, las secuencias y el trigger tal como están definidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE bitacora (
   id         BIGSERIAL PRIMARY KEY,
   usuario_id BIGINT REFERENCES usuarios(id),
   accion     VARCHAR(60) NOT NULL,
   entidad    VARCHAR(40),
   detalle    VARCHAR(300),
   fecha_hora TIMESTAMP NOT NULL DEFAULT now()
);

CREATE INDEX idx_bitacora_fecha        ON bitacora (fecha_hora DESC);

CREATE OR REPLACE FUNCTION bitacora_inmutable() RETURNS trigger AS $$
BEGIN
   RAISE EXCEPTION 'La bitácora no admite modificaciones ni eliminaciones';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_bitacora_inmutable
   BEFORE UPDATE OR DELETE ON bitacora
   FOR EACH ROW EXECUTE FUNCTION bitacora_inmutable();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS trg_bitacora_inmutable ON bitacora;
DROP FUNCTION IF EXISTS bitacora_inmutable();
DROP TABLE IF EXISTS bitacora;
SQL);
    }
};
