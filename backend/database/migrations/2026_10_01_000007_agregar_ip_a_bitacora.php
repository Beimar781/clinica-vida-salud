<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Adición técnica a la bitácora (no cambia el modelo del documento): dirección IP desde la que se hizo la
 * operación (VARCHAR(45) alcanza para IPv6). Es opcional: los registros anteriores quedan sin IP.
 * Fiel a database/schema.sql, sección «Adiciones técnicas».
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE bitacora ADD COLUMN ip VARCHAR(45)');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE bitacora DROP COLUMN IF EXISTS ip');
    }
};
