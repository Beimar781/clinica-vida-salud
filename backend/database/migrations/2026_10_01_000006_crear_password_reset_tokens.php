<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tabla técnica de Laravel (como «migrations»):
 * guarda el token de un solo uso del enlace «¿Olvidaste tu contraseña?» (flujo alternativo de CU-01).
 * El broker de Laravel exige la columna «email»; allí se guarda el correo de la cuenta (usuarios.correo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $tabla) {
            $tabla->string('email', 120)->primary();
            $tabla->string('token');
            $tabla->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
