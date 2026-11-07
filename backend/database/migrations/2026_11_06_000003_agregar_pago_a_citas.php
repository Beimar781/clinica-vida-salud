<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Documento v30, tabla citas (6.3.2 y 6.3.3): el pago de la cita (CU-09, CU-11, CU-13).
 *   monto            precio de la especialidad o servicio al registrar la cita (copia: un cambio de precio posterior
 *                    no altera las citas ya registradas)
 *   metodo_pago      TARJETA (Stripe) o EFECTIVO
 *   estado_pago      PENDIENTE o PAGADO
 *   referencia_pago  id del pago en Stripe (nunca datos de tarjeta)
 *   fecha_pago       cuándo se cobró
 * Las citas ya existentes quedan con EFECTIVO / PENDIENTE y monto 0; el seeder de datos base copia a las de monto
 * 0 el precio de su especialidad. Fiel a database/schema.sql.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE citas
   ADD COLUMN monto           NUMERIC(8,2) NOT NULL DEFAULT 0,
   ADD COLUMN metodo_pago     VARCHAR(10)  NOT NULL DEFAULT 'EFECTIVO'
       CHECK (metodo_pago IN ('TARJETA','EFECTIVO')),
   ADD COLUMN estado_pago     VARCHAR(10)  NOT NULL DEFAULT 'PENDIENTE'
       CHECK (estado_pago IN ('PENDIENTE','PAGADO')),
   ADD COLUMN referencia_pago VARCHAR(80),
   ADD COLUMN fecha_pago      TIMESTAMP;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE citas
   DROP COLUMN IF EXISTS fecha_pago,
   DROP COLUMN IF EXISTS referencia_pago,
   DROP COLUMN IF EXISTS estado_pago,
   DROP COLUMN IF EXISTS metodo_pago,
   DROP COLUMN IF EXISTS monto;
SQL);
    }
};
