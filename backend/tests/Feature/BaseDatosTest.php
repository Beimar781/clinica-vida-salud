<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Prueba de humo de la base de datos: las restricciones EXCLUDE de PostgreSQL (última barrera contra
 * citas y horarios superpuestos), la bitácora de solo agregar y los CHECK. Corre sobre la misma BD
 * del sistema; cada prueba se revierte al terminar (DatabaseTransactions) y usa códigos «T-».
 */
class BaseDatosTest extends SistemaTestCase
{
    private int $especialidadId;

    private int $medicoId;

    private int $otroMedicoId;

    private int $pacienteId;

    private int $otroPacienteId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->especialidadId = DB::table('especialidades')->insertGetId([
            'nombre' => 'T-Especialidad', 'nombre_normalizado' => 't-especialidad',
        ]);
        $this->medicoId = $this->insertarMedico('T-M1', 'T-REG1');
        $this->otroMedicoId = $this->insertarMedico('T-M2', 'T-REG2');
        $this->pacienteId = $this->insertarPaciente('T-P1', 'T-001');
        $this->otroPacienteId = $this->insertarPaciente('T-P2', 'T-002');
    }

    public function test_rechaza_dos_citas_superpuestas_del_mismo_medico(): void
    {
        $this->insertarCita('T-C1', $this->medicoId, $this->pacienteId, '08:00', '08:30');

        $this->assertViolaExclusion(
            fn () => $this->insertarCita('T-C2', $this->medicoId, $this->otroPacienteId, '08:15', '08:45')
        );
    }

    public function test_rechaza_dos_citas_superpuestas_del_mismo_paciente(): void
    {
        $this->insertarCita('T-C1', $this->medicoId, $this->pacienteId, '08:00', '08:30');

        $this->assertViolaExclusion(
            fn () => $this->insertarCita('T-C2', $this->otroMedicoId, $this->pacienteId, '08:00', '08:30')
        );
    }

    public function test_una_cita_cancelada_no_ocupa_horario(): void
    {
        $this->insertarCita('T-C1', $this->medicoId, $this->pacienteId, '08:00', '08:30', 'CANCELADA');

        $this->insertarCita('T-C2', $this->medicoId, $this->pacienteId, '08:00', '08:30');

        $this->assertSame(2, DB::table('citas')->whereIn('codigo', ['T-C1', 'T-C2'])->count());
    }

    public function test_citas_contiguas_no_se_superponen(): void
    {
        $this->insertarCita('T-C1', $this->medicoId, $this->pacienteId, '08:00', '08:30');
        $this->insertarCita('T-C2', $this->medicoId, $this->pacienteId, '08:30', '09:00');

        $this->assertSame(2, DB::table('citas')->whereIn('codigo', ['T-C1', 'T-C2'])->count());
    }

    public function test_rechaza_horarios_superpuestos_del_mismo_medico_y_dia(): void
    {
        DB::table('horarios_atencion')->insert($this->horario(1, '08:00', '12:00'));

        $this->assertViolaExclusion(
            fn () => DB::table('horarios_atencion')->insert($this->horario(1, '11:00', '14:00'))
        );
    }

    public function test_acepta_horarios_en_otro_dia_o_de_otro_medico(): void
    {
        DB::table('horarios_atencion')->insert($this->horario(1, '08:00', '12:00'));
        DB::table('horarios_atencion')->insert($this->horario(2, '08:00', '12:00'));
        DB::table('horarios_atencion')->insert($this->horario(1, '08:00', '12:00', $this->otroMedicoId));

        $this->assertSame(2, DB::table('horarios_atencion')->where('medico_id', $this->medicoId)->count());
    }

    public function test_un_horario_inactivo_no_bloquea_otro_bloque(): void
    {
        DB::table('horarios_atencion')->insert($this->horario(1, '08:00', '12:00') + ['estado' => false]);
        DB::table('horarios_atencion')->insert($this->horario(1, '10:00', '14:00'));

        $this->assertSame(2, DB::table('horarios_atencion')->where('medico_id', $this->medicoId)->count());
    }

    public function test_rechaza_hora_final_menor_o_igual_a_la_inicial(): void
    {
        $this->expectException(QueryException::class);

        DB::transaction(fn () => DB::table('horarios_atencion')->insert($this->horario(1, '12:00', '12:00')));
    }

    public function test_la_bitacora_no_admite_update_ni_delete(): void
    {
        DB::table('bitacora')->insert(['accion' => 'T-PRUEBA', 'fecha_hora' => now()]);

        foreach (['UPDATE bitacora SET accion = ? WHERE accion = ?' => ['T-OTRA', 'T-PRUEBA'],
            'DELETE FROM bitacora WHERE accion = ?' => ['T-PRUEBA']] as $sql => $parametros) {
            try {
                DB::transaction(fn () => DB::statement($sql, $parametros));
                $this->fail('La bitácora aceptó una modificación: '.$sql);
            } catch (QueryException $e) {
                $this->assertStringContainsString('La bitácora no admite modificaciones ni eliminaciones', $e->getMessage());
            }
        }

        $this->assertSame(1, DB::table('bitacora')->where('accion', 'T-PRUEBA')->count());
    }

    public function test_rechaza_modalidad_y_estado_invalidos(): void
    {
        foreach ([['modalidad' => 'TELEFONICA'], ['estado' => 'PENDIENTE']] as $invalido) {
            try {
                DB::transaction(fn () => $this->insertarCita('T-C9', $this->medicoId, $this->pacienteId, '10:00', '10:30', 'PROGRAMADA', $invalido));
                $this->fail('Se aceptó un valor inválido: '.json_encode($invalido));
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode(), 'Debe fallar por el CHECK de la tabla.');
            }
        }
    }

    public function test_las_secuencias_de_codigos_existen(): void
    {
        foreach (['citas_codigo_seq', 'pacientes_codigo_seq', 'medicos_codigo_seq'] as $secuencia) {
            $this->assertNotNull(
                DB::selectOne('SELECT to_regclass(?) AS oid', [$secuencia])->oid,
                "Falta la secuencia {$secuencia}"
            );
        }
    }

    public function test_la_bitacora_tiene_la_columna_ip_opcional(): void
    {
        $columna = DB::selectOne("SELECT data_type, character_maximum_length, is_nullable FROM information_schema.columns WHERE table_name = 'bitacora' AND column_name = 'ip'");

        $this->assertNotNull($columna, 'Falta la columna bitacora.ip');
        $this->assertSame('character varying', $columna->data_type);
        $this->assertSame(45, $columna->character_maximum_length);
        $this->assertSame('YES', $columna->is_nullable);
    }

    /** v30 · especialidades: tipo (CONSULTA por defecto) y precio NUMERIC(8,2) >= 0 (0 por defecto). */
    public function test_especialidades_tienen_tipo_y_precio_con_sus_restricciones(): void
    {
        $tipo = $this->columna('especialidades', 'tipo');
        $this->assertSame('character varying', $tipo->data_type);
        $this->assertSame(12, $tipo->character_maximum_length);
        $this->assertSame('NO', $tipo->is_nullable);
        $this->assertStringContainsString('CONSULTA', $tipo->column_default);

        $precio = $this->columna('especialidades', 'precio');
        $this->assertSame('numeric', $precio->data_type);
        $this->assertSame([8, 2], [$precio->numeric_precision, $precio->numeric_scale]);
        $this->assertSame('NO', $precio->is_nullable);

        // Sin indicar tipo ni precio: CONSULTA y 0.
        $porDefecto = DB::table('especialidades')->where('id', $this->especialidadId)->first();
        $this->assertSame('CONSULTA', $porDefecto->tipo);
        $this->assertEquals(0, $porDefecto->precio);

        foreach (['LABORATORIO', 'ENFERMERIA'] as $i => $valido) {
            DB::table('especialidades')->insert(['nombre' => "T-Serv $i", 'nombre_normalizado' => "t-serv-$i", 'tipo' => $valido, 'precio' => 80.5]);
        }

        foreach ([['tipo' => 'OTRO'], ['precio' => -1]] as $i => $invalido) {
            try {
                DB::transaction(fn () => DB::table('especialidades')->insert($invalido + ['nombre' => "T-Mala $i", 'nombre_normalizado' => "t-mala-$i"]));
                $this->fail('Se aceptó un valor inválido: '.json_encode($invalido));
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode(), 'Debe fallar por el CHECK de la tabla.');
            }
        }

        $this->expectException(QueryException::class); // 22003: numeric field overflow (más de 999999.99)
        DB::transaction(fn () => DB::table('especialidades')->insert(['nombre' => 'T-Cara', 'nombre_normalizado' => 't-cara', 'precio' => 1000000]));
    }

    /** v30 · bloqueos_agenda.registrado_por: FK a usuarios y NOT NULL. */
    public function test_los_dias_sin_atencion_exigen_el_usuario_que_los_registro(): void
    {
        $columna = $this->columna('bloqueos_agenda', 'registrado_por');
        $this->assertSame('bigint', $columna->data_type);
        $this->assertSame('NO', $columna->is_nullable);

        try {
            DB::transaction(fn () => DB::table('bloqueos_agenda')->insert(['fecha' => '2031-05-05', 'motivo' => 'T-Sin autor']));
            $this->fail('Se aceptó un día sin atención sin autor.');
        } catch (QueryException $e) {
            $this->assertSame('23502', $e->getCode(), 'Debe fallar por NOT NULL.');
        }

        try {
            DB::transaction(fn () => DB::table('bloqueos_agenda')->insert(['fecha' => '2031-05-05', 'motivo' => 'T-Autor falso', 'registrado_por' => 999999999]));
            $this->fail('Se aceptó un autor que no existe.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode(), 'Debe fallar por la llave foránea.');
        }

        $autor = $this->crearUsuario('t-autor-bloqueo', ['Administrador']);
        DB::table('bloqueos_agenda')->insert(['fecha' => '2031-05-05', 'motivo' => 'T-Con autor', 'registrado_por' => $autor->id]);
        $this->assertSame($autor->id, (int) DB::table('bloqueos_agenda')->where('motivo', 'T-Con autor')->value('registrado_por'));
    }

    /** v30 · citas: monto, método y estado del pago, referencia (Stripe) y fecha de pago. */
    public function test_las_citas_guardan_el_pago_con_sus_restricciones(): void
    {
        $this->insertarCita('T-C1', $this->medicoId, $this->pacienteId, '08:00', '08:30');
        $cita = DB::table('citas')->where('codigo', 'T-C1')->first();
        $this->assertEquals(0, $cita->monto);
        $this->assertSame('EFECTIVO', $cita->metodo_pago);
        $this->assertSame('PENDIENTE', $cita->estado_pago);
        $this->assertNull($cita->referencia_pago);
        $this->assertNull($cita->fecha_pago);

        $monto = $this->columna('citas', 'monto');
        $this->assertSame([8, 2], [$monto->numeric_precision, $monto->numeric_scale]);
        $this->assertSame('NO', $monto->is_nullable);
        $this->assertSame(80, $this->columna('citas', 'referencia_pago')->character_maximum_length);
        $this->assertSame('timestamp without time zone', $this->columna('citas', 'fecha_pago')->data_type);

        // Una cita pagada con tarjeta.
        $this->insertarCita('T-C2', $this->medicoId, $this->pacienteId, '09:00', '09:30', 'PROGRAMADA', [
            'monto' => 150, 'metodo_pago' => 'TARJETA', 'estado_pago' => 'PAGADO', 'referencia_pago' => 'pi_T_123', 'fecha_pago' => now(),
        ]);
        $this->assertSame('PAGADO', DB::table('citas')->where('codigo', 'T-C2')->value('estado_pago'));

        foreach ([['metodo_pago' => 'CHEQUE'], ['estado_pago' => 'PARCIAL']] as $i => $invalido) {
            try {
                DB::transaction(fn () => $this->insertarCita("T-C9$i", $this->medicoId, $this->pacienteId, '11:00', '11:30', 'PROGRAMADA', $invalido));
                $this->fail('Se aceptó un valor inválido: '.json_encode($invalido));
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode(), 'Debe fallar por el CHECK de la tabla.');
            }
        }
    }

    /** Los índices de database/schema.sql que usan las consultas del Ciclo I existen en la base migrada. */
    public function test_los_indices_del_esquema_existen(): void
    {
        $existentes = collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = 'public'"))->pluck('indexname');

        foreach (['idx_citas_medico_fecha', 'idx_citas_paciente', 'idx_antecedentes_historia', 'idx_bitacora_fecha', 'idx_consultas_medico', 'idx_consultas_historia'] as $indice) {
            $this->assertContains($indice, $existentes, "Falta el índice {$indice}");
        }
    }

    /** v30 · consultas (P5): una por cita, con diagnóstico obligatorio, dentro de una historia clínica. */
    public function test_la_tabla_consultas_admite_una_sola_consulta_por_cita(): void
    {
        $historiaId = DB::table('historias_clinicas')->insertGetId(['paciente_id' => $this->pacienteId]);
        $this->insertarCita('T-C1', $this->medicoId, $this->pacienteId, '08:00', '08:30', 'ATENDIDA');
        $citaId = (int) DB::table('citas')->where('codigo', 'T-C1')->value('id');
        $consulta = ['cita_id' => $citaId, 'historia_clinica_id' => $historiaId, 'medico_id' => $this->medicoId, 'diagnostico' => 'T-Diagnóstico'];

        DB::table('consultas')->insert($consulta);
        $this->assertSame(1, DB::table('consultas')->where('cita_id', $citaId)->count());

        // Una segunda consulta para la misma cita: UNIQUE (23505).
        try {
            DB::transaction(fn () => DB::table('consultas')->insert($consulta));
            $this->fail('Se aceptó una segunda consulta para la misma cita.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->getCode());
        }

        // Sin diagnóstico: NOT NULL (23502).
        DB::table('consultas')->where('cita_id', $citaId)->delete();
        try {
            DB::transaction(fn () => DB::table('consultas')->insert(['diagnostico' => null] + $consulta));
            $this->fail('Se aceptó una consulta sin diagnóstico.');
        } catch (QueryException $e) {
            $this->assertSame('23502', $e->getCode());
        }
    }

    /** La operación debe fallar con SQLSTATE 23P01 (exclusion_violation). */
    private function assertViolaExclusion(callable $operacion): void
    {
        try {
            // Dentro de una transacción anidada (savepoint) para poder seguir usando la conexión.
            DB::transaction($operacion);
            $this->fail('La base de datos aceptó una superposición.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->getCode(), 'Debe fallar por la restricción EXCLUDE.');
        }
    }

    private function columna(string $tabla, string $columna): object
    {
        $fila = DB::selectOne(
            'SELECT data_type, character_maximum_length, numeric_precision, numeric_scale, is_nullable, column_default
               FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
            ['public', $tabla, $columna]
        );
        $this->assertNotNull($fila, "Falta la columna {$tabla}.{$columna}");

        return $fila;
    }

    private function insertarMedico(string $codigo, string $registro): int
    {
        $id = DB::table('medicos')->insertGetId([
            'codigo' => $codigo, 'nro_registro' => $registro, 'nombres' => 'Prueba', 'apellidos' => $codigo,
        ]);
        DB::table('medico_especialidad')->insert(['medico_id' => $id, 'especialidad_id' => $this->especialidadId]);

        return $id;
    }

    private function insertarPaciente(string $codigo, string $ci): int
    {
        return DB::table('pacientes')->insertGetId([
            'codigo' => $codigo, 'ci' => $ci, 'nombres' => 'Prueba', 'apellidos' => $codigo,
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000',
        ]);
    }

    private function insertarCita(string $codigo, int $medicoId, int $pacienteId, string $inicio, string $fin, string $estado = 'PROGRAMADA', array $extra = []): void
    {
        DB::table('citas')->insert($extra + [
            'codigo' => $codigo, 'paciente_id' => $pacienteId, 'medico_id' => $medicoId,
            'especialidad_id' => $this->especialidadId, 'fecha' => '2031-03-03',
            'hora_inicio' => $inicio, 'hora_fin' => $fin, 'estado' => $estado,
        ]);
    }

    private function horario(int $dia, string $inicio, string $fin, ?int $medicoId = null): array
    {
        return [
            'medico_id' => $medicoId ?? $this->medicoId, 'dia_semana' => $dia,
            'hora_inicio' => $inicio, 'hora_fin' => $fin, 'duracion_min' => 30,
        ];
    }
}
