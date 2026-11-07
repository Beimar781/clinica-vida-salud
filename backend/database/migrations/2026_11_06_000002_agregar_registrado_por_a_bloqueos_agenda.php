<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Documento v30, tabla bloqueos_agenda (6.3.2 y 6.3.3): cada día sin atención guarda el usuario que lo
 * registró (CU-06), FK a usuarios y NOT NULL. Como la tabla ya puede tener filas (la base de Railway), la
 * columna se agrega primero nullable, se rellena con el primer usuario Administrador (si no hubiera
 * ninguno, con el primer usuario) y recién entonces se vuelve NOT NULL con su llave foránea.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE bloqueos_agenda ADD COLUMN registrado_por BIGINT');

        DB::unprepared(<<<'SQL'
UPDATE bloqueos_agenda
   SET registrado_por = COALESCE(
        (SELECT ru.usuario_id
           FROM rol_usuario ru
           JOIN roles r ON r.id = ru.rol_id
          WHERE r.nombre = 'Administrador'
          ORDER BY ru.usuario_id
          LIMIT 1),
        (SELECT id FROM usuarios ORDER BY id LIMIT 1)
   )
 WHERE registrado_por IS NULL
SQL);

        if (DB::table('bloqueos_agenda')->whereNull('registrado_por')->exists()) {
            throw new RuntimeException('Hay días sin atención pero ningún usuario al que atribuirlos: ejecute los seeders (php artisan db:seed) y vuelva a migrar.');
        }

        DB::unprepared(<<<'SQL'
ALTER TABLE bloqueos_agenda
   ALTER COLUMN registrado_por SET NOT NULL,
   ADD CONSTRAINT bloqueos_agenda_registrado_por_fkey FOREIGN KEY (registrado_por) REFERENCES usuarios(id);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE bloqueos_agenda DROP COLUMN IF EXISTS registrado_por');
    }
};
