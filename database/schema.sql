-- =====================================================================
-- COCIAP 2026 — Esquema de base de datos
-- Fuente de verdad: PLAN_IMPLEMENTACION_COCIAP2026.md, sección 5
-- Generado: 2026-08-16
--
-- Decisiones aplicadas sobre el esquema original del plan:
--   D-01  categoria_id movido de `participantes` a `inscripciones`.
--   D-03  charset/collation utf8mb4 + utf8mb4_unicode_ci EXPLÍCITO por tabla,
--         para no depender del default del servidor de Hostinger.
--   D-04  codigo_correlativo con sufijo aleatorio (no enumerable), porque la
--         vista pública del carné expone datos de menores de edad.
--   D-05  participantes.dni SIN UNIQUE: el duplicado se advierte, no se impide.
--         Se indexa (concurso_id, dni) para que esa advertencia sea barata.
--
-- Orden de creación respeta las dependencias de claves foráneas.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;


-- ---------------------------------------------------------------------
-- Tenant. En este MVP solo existe UNASAM / I.E. Víctor Valenzuela Guardia.
-- ---------------------------------------------------------------------
CREATE TABLE organizaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Evento concreto organizado por una Organización. COCIAP 2026 es el primero.
-- ---------------------------------------------------------------------
CREATE TABLE concursos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organizacion_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    -- Prefijo del código correlativo de los participantes (decisión D-12).
    -- Explícito en vez de deducido del nombre: 'IV Concurso Regional de
    -- Conocimientos COCIAP 2026' no da un prefijo fiable por parsing.
    codigo VARCHAR(20) NOT NULL UNIQUE,
    fecha_evento DATE NOT NULL,
    fecha_inicio_inscripcion DATE NOT NULL,
    fecha_fin_inscripcion DATE NOT NULL,
    sede VARCHAR(200),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_concurso_organizacion
        FOREIGN KEY (organizacion_id) REFERENCES organizaciones(id),
    INDEX idx_concurso_organizacion (organizacion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Nivel + grado. Para COCIAP son 11 fijas: primaria 1-6, secundaria 1-5.
-- ---------------------------------------------------------------------
CREATE TABLE categorias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    concurso_id INT UNSIGNED NOT NULL,
    nivel ENUM('primaria','secundaria') NOT NULL,
    grado TINYINT UNSIGNED NOT NULL,
    UNIQUE KEY uk_categoria (concurso_id, nivel, grado),
    CONSTRAINT fk_categoria_concurso
        FOREIGN KEY (concurso_id) REFERENCES concursos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Costo por concurso y tipo de origen. No varía por categoría.
-- ---------------------------------------------------------------------
CREATE TABLE tarifas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    concurso_id INT UNSIGNED NOT NULL,
    tipo_origen ENUM('publica','privada','libre') NOT NULL,
    monto DECIMAL(6,2) NOT NULL,
    UNIQUE KEY uk_tarifa (concurso_id, tipo_origen),
    CONSTRAINT fk_tarifa_concurso
        FOREIGN KEY (concurso_id) REFERENCES concursos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Catálogo GLOBAL, compartido entre organizaciones (no aislado por tenant).
-- Docente delegado y director viven aquí: son datos persistentes de la I.E.,
-- no se recapturan en cada inscripción. DNI de ambos es opcional.
-- ---------------------------------------------------------------------
CREATE TABLE instituciones_educativas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL,
    distrito VARCHAR(100) NOT NULL,
    provincia VARCHAR(100) NOT NULL,
    departamento VARCHAR(100) NOT NULL,
    tipo ENUM('publica','privada') NOT NULL,
    direccion VARCHAR(250),

    docente_delegado_ap_paterno VARCHAR(100),
    docente_delegado_ap_materno VARCHAR(100),
    docente_delegado_nombres VARCHAR(150),
    docente_delegado_celular VARCHAR(20),
    docente_delegado_correo VARCHAR(150),
    docente_delegado_dni VARCHAR(15) NULL,

    director_ap_paterno VARCHAR(100),
    director_ap_materno VARCHAR(100),
    director_nombres VARCHAR(150),
    director_celular VARCHAR(20),
    director_correo VARCHAR(150),
    director_dni VARCHAR(15) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Soporta el buscador anti-duplicados de la Fase 2.
    INDEX idx_ie_nombre (nombre),
    INDEX idx_ie_ubicacion (departamento, provincia, distrito)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Reutilizable: un apoderado puede tener varios estudiantes libres (hermanos).
-- ---------------------------------------------------------------------
CREATE TABLE apoderados (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dni VARCHAR(15) NOT NULL UNIQUE,
    ap_paterno VARCHAR(100) NOT NULL,
    ap_materno VARCHAR(100) NOT NULL,
    nombres VARCHAR(150) NOT NULL,
    celular VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Usuarios del sistema. Login individual por persona.
-- NOTA (P-05, pendiente): el plan no incluye organizacion_id aquí pese al
-- diseño multi-tenant declarado. Se respeta el plan hasta que el propietario
-- resuelva; añadirlo después es un ALTER TABLE aditivo, sin pérdida de datos.
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombres VARCHAR(150) NOT NULL,
    correo VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('secretaria','administrador') NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Participante. El identificador único es el correlativo, NO el DNI.
--
-- codigo_correlativo: formato COCIAP2026-0042-K7M9X3
--   El bloque numérico da orden y legibilidad a la secretaria; el sufijo
--   aleatorio impide recorrer los carnés de todos los menores incrementando
--   el número, dado que la vista pública del carné no tiene control de acceso.
--
-- dni: SIN UNIQUE a propósito (D-05). El duplicado se advierte en la UI.
-- categoria_id ya NO vive aquí: se movió a `inscripciones` (D-01).
-- ---------------------------------------------------------------------
CREATE TABLE participantes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo_correlativo VARCHAR(30) NOT NULL UNIQUE,
    concurso_id INT UNSIGNED NOT NULL,
    tipo_participante ENUM('delegacion','libre') NOT NULL,
    dni VARCHAR(15) NOT NULL,
    ap_paterno VARCHAR(100) NOT NULL,
    ap_materno VARCHAR(100) NOT NULL,
    nombres VARCHAR(150) NOT NULL,
    institucion_id INT UNSIGNED NULL,
    apoderado_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_participante_concurso
        FOREIGN KEY (concurso_id) REFERENCES concursos(id),
    CONSTRAINT fk_participante_institucion
        FOREIGN KEY (institucion_id) REFERENCES instituciones_educativas(id),
    CONSTRAINT fk_participante_apoderado
        FOREIGN KEY (apoderado_id) REFERENCES apoderados(id),

    -- Soporta la advertencia de duplicado sin recorrer toda la tabla.
    INDEX idx_participante_dni (concurso_id, dni),
    INDEX idx_participante_institucion (institucion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Inscripción: una fila POR PARTICIPANTE, aunque el alta sea por lote.
-- Así el futuro módulo de calificación puede atribuir resultados a la I.E.
--
-- categoria_id vive aquí (D-01): permite que "anular y reinscribir" corrija
-- realmente la categoría, conservando el correlativo del estudiante y dejando
-- el rastro de la categoría errónea en la inscripción anulada.
--
-- monto: se copia de `tarifas` al inscribir (snapshot). Si la tarifa cambia
-- después, las inscripciones ya emitidas conservan lo que realmente se cobró.
-- ---------------------------------------------------------------------
CREATE TABLE inscripciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    participante_id INT UNSIGNED NOT NULL,
    categoria_id INT UNSIGNED NOT NULL COMMENT 'movido desde participantes — decisión D-01',
    usuario_id INT UNSIGNED NOT NULL COMMENT 'secretaria/admin que registró',
    estado ENUM('pendiente','confirmada','anulada') NOT NULL DEFAULT 'pendiente',
    monto DECIMAL(6,2) NOT NULL,
    medio_pago ENUM('yape','transferencia','efectivo') NULL,
    yape_codigo_seguridad CHAR(3) NULL COMMENT 'solo si medio_pago = yape',
    fecha_pago DATETIME NULL,
    motivo_anulacion VARCHAR(250) NULL,
    requiere_devolucion BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_inscripcion_participante
        FOREIGN KEY (participante_id) REFERENCES participantes(id),
    CONSTRAINT fk_inscripcion_categoria
        FOREIGN KEY (categoria_id) REFERENCES categorias(id),
    CONSTRAINT fk_inscripcion_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id),

    INDEX idx_inscripcion_estado (estado),
    INDEX idx_inscripcion_participante (participante_id),
    INDEX idx_inscripcion_categoria (categoria_id),
    -- Soporta el reporte de fondo de devoluciones (flujo 7).
    INDEX idx_inscripcion_devolucion (requiere_devolucion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Carné generado al confirmar el pago. Una por inscripción.
--
-- Nada de lo que se guarda aquí puede depender del entorno: la misma base
-- tiene que servir en XAMPP y en Hostinger sin reescribir filas (D-21).
--
-- codigo_qr: el CÓDIGO que el QR codifica, no la URL. La URL se arma al
--   vuelo con GeneradorCarne::urlPublica(), que lee `app.url_base`.
-- ruta_pdf: ruta RELATIVA a la raíz del proyecto (storage/carnes/...).
-- ---------------------------------------------------------------------
CREATE TABLE carnes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inscripcion_id INT UNSIGNED NOT NULL UNIQUE,
    codigo_qr VARCHAR(100) NOT NULL,
    ruta_pdf VARCHAR(255) NOT NULL,
    generado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_carne_inscripcion
        FOREIGN KEY (inscripcion_id) REFERENCES inscripciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
