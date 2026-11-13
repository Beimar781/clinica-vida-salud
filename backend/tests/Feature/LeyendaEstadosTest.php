<?php

namespace Tests\Feature;

use App\Models\Rol;
use PHPUnit\Framework\Attributes\Test;

/** CU-10, CU-13 y CU-15 (extra aprobado): leyenda «¿Qué significa cada estado?» en «Mis citas», «Control de citas» y la lista de citas del personal. */
class LeyendaEstadosTest extends SistemaTestCase
{
    #[Test]
    public function la_leyenda_aparece_cerrada_en_las_tres_pantallas(): void
    {
        $recepcion = $this->crearUsuario('t-ley-recep', [Rol::RECEPCIONISTA]);
        $paciente = $this->crearPaciente(['usuario_id' => $this->crearUsuario('t-ley-pac', [Rol::PACIENTE])->id]);

        $pantallas = [
            [$recepcion, route('control.index')],
            [$recepcion, route('citas.index')],
            [$recepcion, route('historial.index', ['paciente' => $paciente->id])],
            [$paciente->usuario, route('historial.index')],
        ];
        foreach ($pantallas as [$usuario, $url]) {
            $this->actingAs($usuario)->get($url)->assertOk()
                ->assertSee('¿Qué significa cada estado?')->assertSee('reservada, falta confirmar')->assertSee('ya pasó la hora y nadie la cerró (no es un estado)')
                ->assertDontSee('<details class="leyenda-estados" open', false);
        }
    }
}
