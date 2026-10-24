<?php

namespace Database\Seeders;

use App\Modules\GestionPacientes\Models\AntecedenteExterno;
use App\Modules\GestionPacientes\Models\HistoriaClinica;
use App\Modules\GestionPacientes\Models\Paciente;
use App\Modules\Seguridad\Models\Usuario;
use App\Support\Codigos;
use Illuminate\Database\Seeder;

/**
 * Pacientes de demostración. Cada uno recibe su historia clínica; algunos tienen cuenta de usuario.
 */
class PacienteSeeder extends Seeder
{
    public function run(): void
    {
        $recepcionId = Usuario::where('nombre_usuario', 'recepcion')->value('id');

        $pacientes = [
            ['ci' => '6543210', 'nombres' => 'María Elena', 'apellidos' => 'Rojas Suárez', 'nacimiento' => '1988-03-14', 'sexo' => 'F',
                'telefono' => '71234501', 'correo' => 'maria.rojas@correo.test', 'direccion' => 'Av. Cristo Redentor 1520', 'usuario' => 'mrojas',
                'emergencia' => ['Pedro Rojas', 'Hermano', '71234502'], 'sangre' => 'O+', 'alergias' => 'Penicilina'],
            ['ci' => '7123456', 'nombres' => 'Juan Carlos', 'apellidos' => 'Pérez Mamani', 'nacimiento' => '1975-11-02', 'sexo' => 'M',
                'telefono' => '71234503', 'correo' => 'juan.perez@correo.test', 'direccion' => 'Calle Libertad 245', 'usuario' => 'jperez',
                'emergencia' => ['Ana Mamani', 'Esposa', '71234504'], 'sangre' => 'A+', 'cronicas' => 'Hipertensión arterial', 'medicamentos' => 'Losartán 50 mg',
                'externo' => ['Hospital Japonés', '2025-08-20', 'Cardiología', 'Hipertensión arterial grado I', 'Losartán 50 mg cada 24 horas']],
            ['ci' => '8456123', 'nombres' => 'Patricia', 'apellidos' => 'Vargas Egüez', 'nacimiento' => '1983-07-21', 'sexo' => 'F',
                'telefono' => '70011003', 'correo' => 'patricia.vargas@vidasalud.test', 'direccion' => 'Barrio Equipetrol, calle 5', 'usuario' => 'pvargas',
                'emergencia' => null, 'sangre' => 'B+'],
            ['ci' => '9012345', 'nombres' => 'Sofía', 'apellidos' => 'Camacho Justiniano', 'nacimiento' => '2018-05-09', 'sexo' => 'F',
                'telefono' => '71234505', 'correo' => null, 'direccion' => 'Av. Banzer km 4', 'usuario' => null,
                'emergencia' => ['Lourdes Justiniano', 'Madre', '71234506'], 'sangre' => 'O+'],
            ['ci' => '5987654', 'nombres' => 'Carlos Alberto', 'apellidos' => 'Mamani Quispe', 'nacimiento' => '1969-01-30', 'sexo' => 'M',
                'telefono' => '71234507', 'correo' => null, 'direccion' => 'Calle Warnes 780', 'usuario' => null,
                'emergencia' => ['Rosa Quispe', 'Esposa', '71234508'], 'sangre' => 'AB+', 'cronicas' => 'Diabetes tipo 2', 'medicamentos' => 'Metformina 850 mg'],
            ['ci' => '10234567', 'nombres' => 'Lucía', 'apellidos' => 'Fernández Roca', 'nacimiento' => '1995-09-12', 'sexo' => 'F',
                'telefono' => '71234509', 'correo' => 'lucia.fernandez@correo.test', 'direccion' => 'Calle Ñuflo de Chávez 310', 'usuario' => null,
                'emergencia' => ['Marta Roca', 'Madre', '71234510'], 'sangre' => 'A-'],
            ['ci' => '4321876', 'nombres' => 'Roberto', 'apellidos' => 'Salvatierra Vaca', 'nacimiento' => '1958-12-05', 'sexo' => 'M',
                'telefono' => '71234511', 'correo' => null, 'direccion' => 'Av. Mutualista 90', 'usuario' => null,
                'emergencia' => null, 'sangre' => null],
            ['ci' => '11345678', 'nombres' => 'Valeria', 'apellidos' => 'Ortiz Chávez', 'nacimiento' => '2001-04-27', 'sexo' => 'F',
                'telefono' => '71234512', 'correo' => 'valeria.ortiz@correo.test', 'direccion' => null, 'usuario' => null,
                'emergencia' => null, 'sangre' => null],
        ];

        foreach ($pacientes as $d) {
            if (Paciente::where('ci', $d['ci'])->exists()) {
                continue;
            }

            $paciente = Paciente::create([
                'usuario_id' => $d['usuario'] ? Usuario::where('nombre_usuario', $d['usuario'])->value('id') : null,
                'codigo' => Codigos::paciente(),
                'ci' => $d['ci'],
                'nombres' => $d['nombres'],
                'apellidos' => $d['apellidos'],
                'fecha_nacimiento' => $d['nacimiento'],
                'sexo' => $d['sexo'],
                'telefono' => $d['telefono'],
                'correo' => $d['correo'],
                'direccion' => $d['direccion'],
                'emergencia_nombre' => $d['emergencia'][0] ?? null,
                'emergencia_parentesco' => $d['emergencia'][1] ?? null,
                'emergencia_telefono' => $d['emergencia'][2] ?? null,
                'recibe_recordatorios' => true,
                'mostrar_contacto_medico' => true,
                'estado' => true,
            ]);

            $historia = HistoriaClinica::create([
                'paciente_id' => $paciente->id,
                'tipo_sangre' => $d['sangre'],
                'alergias' => $d['alergias'] ?? null,
                'enfermedades_cronicas' => $d['cronicas'] ?? null,
                'medicamentos' => $d['medicamentos'] ?? null,
            ]);

            if (isset($d['externo'])) {
                [$hospital, $fecha, $especialidad, $diagnostico, $tratamiento] = $d['externo'];
                AntecedenteExterno::create([
                    'historia_clinica_id' => $historia->id,
                    'hospital_origen' => $hospital,
                    'fecha_atencion' => $fecha,
                    'especialidad' => $especialidad,
                    'diagnostico' => $diagnostico,
                    'tratamiento' => $tratamiento,
                    'registrado_por' => $recepcionId,
                ]);
            }
        }
    }
}
