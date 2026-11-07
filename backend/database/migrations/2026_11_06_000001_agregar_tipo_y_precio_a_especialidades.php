<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Documento v30, tabla especialidades (6.3.2 y 6.3.3): el catálogo pasa a ser de «especialidades y servicios»
 * (CU-04). tipo = CONSULTA, LABORATORIO o ENFERMERIA (por defecto CONSULTA) y precio en bolivianos
 * NUMERIC(8,2) >= 0 (por defecto 0). Las filas existentes quedan como CONSULTA con precio 0; el seeder
 * de datos base les pone su precio. Fiel a database/schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE especialidades
   ADD COLUMN tipo VARCHAR(12) NOT NULL DEFAULT 'CONSULTA'
       CHECK (tipo IN ('CONSULTA','LABORATORIO','ENFERMERIA')),
   ADD COLUMN precio NUMERIC(8,2) NOT NULL DEFAULT 0 CHECK (precio >= 0);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE especialidades DROP COLUMN IF EXISTS precio, DROP COLUMN IF EXISTS tipo');
    }
};
