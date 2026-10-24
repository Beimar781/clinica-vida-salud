<?php

namespace Tests\Unit;

use App\Support\Texto;
use PHPUnit\Framework\TestCase;

class TextoTest extends TestCase
{
    public function test_normaliza_a_minusculas_sin_tildes_y_sin_espacios_sobrantes(): void
    {
        $this->assertSame('cardiologia', Texto::normalizar('Cardiología'));
        $this->assertSame('cardiologia', Texto::normalizar('cardiologia '));
        $this->assertSame('medicina general', Texto::normalizar("  MEDICINA   General\t"));
    }
}
