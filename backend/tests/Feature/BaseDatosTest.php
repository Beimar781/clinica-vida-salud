<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prueba de humo de la base de datos: las restricciones EXCLUDE de PostgreSQL (última barrera contra
 * citas y horarios superpuestos), la bitácora de solo agregar y los CHECK. Corre sobre la misma BD
 * del sistema; cada prueba se revierte al terminar (DatabaseTransactions) y usa códigos «T-».
 */
class BaseDatosTest extends TestCase
{
    use DatabaseTransactions;

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
        $this->medicoId = $this->crearMedico('T-M1', 'T-REG1');
        $this->otroMedicoId = $this->crearMedico('T-M2', 'T-REG2');
        $this->pacienteId = $this->crearPaciente('T-P1', 'T-001');
        $this->otroPacienteId = $this->crearPaciente('T-P2', 'T-002');
    }

    public function test_rechaza_dos_citas_superpuestas_del_mismo_medico(): void
    {
        $this->crearCita('T-C1', $this->medicoId, $this->pacienteId, '08:00', '08:30');

        $this->assertViolaExclusion(
            fn () => $this->crearCita('T-C2', $this->medicoId, $this->otroPacienteId, '08:15', '08:45')
        );
    }

    public function test_rechaza_dos_citas_superpuestas_del_mismo_paciente(): void
    {
        $this->crearCita('T-C1', $this->medicoId, $this->pacienteId, '08:00', '08:30');

        $this->assertViolaExclusion(
            fn () => $this->crearCita('T-C2', $this->otroMedicoId, $this->pacienteId, '08:00', '08:30')
        );
    }

    public function test_una_cita_cancelada_no_ocupa_horario(): void
    {
        $this->crearCita('T-C1', $this->medicoId, $this->pacienteId, '08:00', '08:30', 'CANCELADA');

        $this->crearCita('T-C2', $this->medicoId, $this->pacienteId, '08:00', '08:30');

        $this->assertSame(2, DB::table('citas')->whereIn('codigo', ['T-C1', 'T-C2'])->count());
    }

    public function test_citas_contiguas_no_se_superponen(): void
    {
        $this->crearCita('T-C1', $this->medicoId, $this->pacienteId, '08:00', '08:30');
        $this->crearCita('T-C2', $this->medicoId, $this->pacienteId, '08:30', '09:00');

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
                DB::transaction(fn () => $this->crearCita('T-C9', $this->medicoId, $this->pacienteId, '10:00', '10:30', 'PROGRAMADA', $invalido));
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

    private function crearMedico(string $codigo, string $registro): int
    {
        $id = DB::table('medicos')->insertGetId([
            'codigo' => $codigo, 'nro_registro' => $registro, 'nombres' => 'Prueba', 'apellidos' => $codigo,
        ]);
        DB::table('medico_especialidad')->insert(['medico_id' => $id, 'especialidad_id' => $this->especialidadId]);

        return $id;
    }

    private function crearPaciente(string $codigo, string $ci): int
    {
        return DB::table('pacientes')->insertGetId([
            'codigo' => $codigo, 'ci' => $ci, 'nombres' => 'Prueba', 'apellidos' => $codigo,
            'fecha_nacimiento' => '1990-01-01', 'telefono' => '70000000',
        ]);
    }

    private function crearCita(string $codigo, int $medicoId, int $pacienteId, string $inicio, string $fin, string $estado = 'PROGRAMADA', array $extra = []): void
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
