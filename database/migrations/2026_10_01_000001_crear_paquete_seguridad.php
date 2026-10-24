<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * P1 · Seguridad y Usuarios (roles, usuarios, rol_usuario)
 * Fiel a database/schema.sql (documento, sección 6.3.3). SQL crudo para conservar
 * los CHECK, las restricciones EXCLUDE, las secuencias y el trigger tal como están definidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE roles (
   id          BIGSERIAL PRIMARY KEY,
   nombre      VARCHAR(30) NOT NULL UNIQUE,
   descripcion VARCHAR(120)
);

CREATE TABLE usuarios (
   id             BIGSERIAL PRIMARY KEY,
   nombre_usuario VARCHAR(50)  NOT NULL UNIQUE,
   correo         VARCHAR(120) NOT NULL UNIQUE,
   password_hash  VARCHAR(255) NOT NULL,
   estado         BOOLEAN      NOT NULL DEFAULT TRUE,
   ultimo_acceso  TIMESTAMP,
   created_at     TIMESTAMP    NOT NULL DEFAULT now(),
   updated_at     TIMESTAMP    NOT NULL DEFAULT now()
);

CREATE TABLE rol_usuario (
   usuario_id BIGINT NOT NULL REFERENCES usuarios(id),
   rol_id     BIGINT NOT NULL REFERENCES roles(id),
   PRIMARY KEY (usuario_id, rol_id)
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS rol_usuario;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS roles;
SQL);
    }
};
