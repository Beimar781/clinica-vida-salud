-- Clínica Vida Salud - Sistema web de gestión de citas médicas
-- Modelo físico (PostgreSQL 16) · fuente: documento sección 6.3.3
-- Referencia para escribir las migraciones de Laravel (usar DB::unprepared para EXCLUDE,
-- secuencias y triggers). Las tablas deben quedar idénticas a esta definición.

CREATE EXTENSION IF NOT EXISTS btree_gist;

-- ============================================================
-- P1 · Seguridad y Usuarios
-- ============================================================
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

-- ============================================================
-- P2 · Gestión Médica
-- ============================================================
CREATE TABLE especialidades (
   id                 BIGSERIAL PRIMARY KEY,
   nombre             VARCHAR(80) NOT NULL,
   nombre_normalizado VARCHAR(80) NOT NULL UNIQUE,   -- minúsculas, sin tildes, sin espacios sobrantes
   descripcion        VARCHAR(200),
   estado             BOOLEAN     NOT NULL DEFAULT TRUE
);

CREATE TABLE medicos (
   id                    BIGSERIAL PRIMARY KEY,
   usuario_id            BIGINT UNIQUE REFERENCES usuarios(id),
   codigo                VARCHAR(10) NOT NULL UNIQUE,    -- MED-0001 (secuencia medicos_codigo_seq)
   nro_registro          VARCHAR(30) NOT NULL UNIQUE,
   nombres               VARCHAR(80) NOT NULL,
   apellidos             VARCHAR(80) NOT NULL,
   telefono              VARCHAR(20),
   correo                VARCHAR(120),
   ubicacion             VARCHAR(100),                   -- consultorio o sede (búsqueda por ubicación)
   puede_marcar_atendida BOOLEAN NOT NULL DEFAULT TRUE,  -- permiso para marcar citas como atendidas
   estado                BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE medico_especialidad (
   medico_id       BIGINT NOT NULL REFERENCES medicos(id),
   especialidad_id BIGINT NOT NULL REFERENCES especialidades(id),
   PRIMARY KEY (medico_id, especialidad_id)
);

CREATE TABLE horarios_atencion (
   id           BIGSERIAL PRIMARY KEY,
   medico_id    BIGINT   NOT NULL REFERENCES medicos(id),
   dia_semana   SMALLINT NOT NULL CHECK (dia_semana BETWEEN 1 AND 7),   -- 1 = lunes ... 7 = domingo
   hora_inicio  TIME     NOT NULL,
   hora_fin     TIME     NOT NULL,
   duracion_min SMALLINT NOT NULL CHECK (duracion_min > 0),
   estado       BOOLEAN  NOT NULL DEFAULT TRUE,
   CHECK (hora_fin > hora_inicio),
   -- un médico no puede tener bloques superpuestos el mismo día
   EXCLUDE USING gist (
      medico_id  WITH =,
      dia_semana WITH =,
      tsrange(DATE '2000-01-01' + hora_inicio, DATE '2000-01-01' + hora_fin, '[)') WITH &&
   ) WHERE (estado)
);

-- Feriados y días sin atención (se excluyen de la agenda)
CREATE TABLE bloqueos_agenda (
   id         BIGSERIAL PRIMARY KEY,
   fecha      DATE         NOT NULL UNIQUE,
   motivo     VARCHAR(120) NOT NULL,
   estado     BOOLEAN      NOT NULL DEFAULT TRUE,
   created_at TIMESTAMP    NOT NULL DEFAULT now()
);

-- ============================================================
-- P3 · Gestión de Pacientes
-- ============================================================
CREATE TABLE pacientes (
   id                      BIGSERIAL PRIMARY KEY,
   usuario_id              BIGINT UNIQUE REFERENCES usuarios(id),
   codigo                  VARCHAR(10)  NOT NULL UNIQUE,   -- PAC-0001 (secuencia pacientes_codigo_seq)
   ci                      VARCHAR(20)  NOT NULL UNIQUE,
   nombres                 VARCHAR(80)  NOT NULL,
   apellidos               VARCHAR(80)  NOT NULL,
   fecha_nacimiento        DATE         NOT NULL,
   sexo                    CHAR(1)      CHECK (sexo IN ('M', 'F')),
   telefono                VARCHAR(20)  NOT NULL,
   correo                  VARCHAR(120),
   direccion               VARCHAR(200),
   emergencia_nombre       VARCHAR(100),
   emergencia_parentesco   VARCHAR(40),
   emergencia_telefono     VARCHAR(20),
   recibe_recordatorios    BOOLEAN NOT NULL DEFAULT TRUE,  -- notificaciones
   mostrar_contacto_medico BOOLEAN NOT NULL DEFAULT TRUE,  -- privacidad
   estado                  BOOLEAN NOT NULL DEFAULT TRUE
);

-- Historia clínica: se crea al registrar al paciente (una por paciente)
CREATE TABLE historias_clinicas (
   id                      BIGSERIAL PRIMARY KEY,
   paciente_id             BIGINT NOT NULL UNIQUE REFERENCES pacientes(id),
   fecha_apertura          DATE   NOT NULL DEFAULT CURRENT_DATE,
   tipo_sangre             VARCHAR(3) CHECK (tipo_sangre IN ('A+','A-','B+','B-','AB+','AB-','O+','O-')),
   antecedentes_personales VARCHAR(400),
   alergias                VARCHAR(200),
   enfermedades_cronicas   VARCHAR(300),
   medicamentos            VARCHAR(300),
   observaciones           VARCHAR(400),
   created_at              TIMESTAMP NOT NULL DEFAULT now(),
   updated_at              TIMESTAMP NOT NULL DEFAULT now()
);

-- Antecedentes de otros hospitales o clínicas
CREATE TABLE antecedentes_externos (
   id                  BIGSERIAL PRIMARY KEY,
   historia_clinica_id BIGINT       NOT NULL REFERENCES historias_clinicas(id),
   hospital_origen     VARCHAR(120) NOT NULL,
   fecha_atencion      DATE,
   especialidad        VARCHAR(80),
   diagnostico         VARCHAR(300) NOT NULL,
   tratamiento         VARCHAR(400),
   observaciones       VARCHAR(400),
   registrado_por      BIGINT REFERENCES usuarios(id),
   created_at          TIMESTAMP NOT NULL DEFAULT now()
);

-- ============================================================
-- P4 · Gestión de Citas
-- ============================================================
CREATE TABLE citas (
   id                   BIGSERIAL PRIMARY KEY,
   codigo               VARCHAR(12) NOT NULL UNIQUE,         -- CM-0001 (secuencia citas_codigo_seq)
   paciente_id          BIGINT NOT NULL REFERENCES pacientes(id),
   medico_id            BIGINT NOT NULL REFERENCES medicos(id),
   especialidad_id      BIGINT NOT NULL REFERENCES especialidades(id),
   fecha                DATE NOT NULL,
   hora_inicio          TIME NOT NULL,
   hora_fin             TIME NOT NULL,
   modalidad            VARCHAR(10) NOT NULL DEFAULT 'PRESENCIAL'
                        CHECK (modalidad IN ('PRESENCIAL','VIRTUAL')),
   motivo               VARCHAR(200),
   estado               VARCHAR(15) NOT NULL DEFAULT 'PROGRAMADA'
                        CHECK (estado IN ('PROGRAMADA','CONFIRMADA','ATENDIDA','CANCELADA','INASISTENCIA')),
   motivo_cancelacion   VARCHAR(200),
   recordatorio_enviado BOOLEAN NOT NULL DEFAULT FALSE,
   creado_por           BIGINT REFERENCES usuarios(id),
   created_at           TIMESTAMP NOT NULL DEFAULT now(),
   updated_at           TIMESTAMP NOT NULL DEFAULT now(),
   CHECK (hora_fin > hora_inicio),
   -- un médico no puede tener citas superpuestas; las canceladas no ocupan horario
   EXCLUDE USING gist (
      medico_id WITH =,
      tsrange(fecha + hora_inicio, fecha + hora_fin, '[)') WITH &&
   ) WHERE (estado <> 'CANCELADA'),
   -- un paciente no puede tener dos citas activas en el mismo horario
   EXCLUDE USING gist (
      paciente_id WITH =,
      tsrange(fecha + hora_inicio, fecha + hora_fin, '[)') WITH &&
   ) WHERE (estado <> 'CANCELADA')
);

CREATE INDEX idx_citas_medico_fecha ON citas (medico_id, fecha);
CREATE INDEX idx_citas_paciente     ON citas (paciente_id);

-- ============================================================
-- P5 · Atención Clínica (Ciclo II)
-- ============================================================
CREATE TABLE consultas (
   id                  BIGSERIAL PRIMARY KEY,
   cita_id             BIGINT       NOT NULL UNIQUE REFERENCES citas(id),
   historia_clinica_id BIGINT       NOT NULL REFERENCES historias_clinicas(id),
   medico_id           BIGINT       NOT NULL REFERENCES medicos(id),
   diagnostico         VARCHAR(300) NOT NULL,
   tratamiento         VARCHAR(400),
   observaciones       VARCHAR(400),
   created_at          TIMESTAMP NOT NULL DEFAULT now(),
   updated_at          TIMESTAMP NOT NULL DEFAULT now()
);

-- ============================================================
-- P6 · Reportes y Bitácora (bitácora es transversal desde el Ciclo I)
-- ============================================================
CREATE TABLE bitacora (
   id         BIGSERIAL PRIMARY KEY,
   usuario_id BIGINT REFERENCES usuarios(id),
   accion     VARCHAR(60) NOT NULL,
   entidad    VARCHAR(40),
   detalle    VARCHAR(300),
   fecha_hora TIMESTAMP NOT NULL DEFAULT now()
);

CREATE INDEX idx_consultas_medico      ON consultas (medico_id);
CREATE INDEX idx_consultas_historia    ON consultas (historia_clinica_id);
CREATE INDEX idx_antecedentes_historia ON antecedentes_externos (historia_clinica_id);
CREATE INDEX idx_bitacora_fecha        ON bitacora (fecha_hora DESC);

-- ============================================================
-- Adiciones técnicas (no cambian el modelo del documento)
-- ============================================================

-- Secuencias para los códigos legibles: CM-0001, PAC-0001, MED-0001.
-- Se usan con nextval() dentro de la transacción; nunca max()+1 (provoca duplicados bajo concurrencia).
CREATE SEQUENCE citas_codigo_seq     START 1;
CREATE SEQUENCE pacientes_codigo_seq START 1;
CREATE SEQUENCE medicos_codigo_seq   START 1;
-- Ejemplo: SELECT 'CM-' || lpad(nextval('citas_codigo_seq')::text, 4, '0');

-- La bitácora es de solo agregar: ningún UPDATE ni DELETE (RF19, trazabilidad)
CREATE OR REPLACE FUNCTION bitacora_inmutable() RETURNS trigger AS $$
BEGIN
   RAISE EXCEPTION 'La bitácora no admite modificaciones ni eliminaciones';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_bitacora_inmutable
   BEFORE UPDATE OR DELETE ON bitacora
   FOR EACH ROW EXECUTE FUNCTION bitacora_inmutable();

-- Datos base obligatorios (los seeders de Laravel los insertan)
-- roles: Administrador, Recepcionista, Médico, Paciente
