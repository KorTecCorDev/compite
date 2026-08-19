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
--   D-31  participantes.dni ÚNICO POR CONCURSO. Revierte D-05: el duplicado ya
--         no se advierte, se impide. Por concurso y no absoluto, para que el
--         mismo estudiante pueda competir el año que viene.
--   D-28  el docente delegado deja de estar embebido en la I.E. y pasa a ser
--         un `apoderados`: es el encargado de la delegación y, por tanto, el
--         apoderado de los participantes que inscribe.
--   D-39  cada inscripción guarda TRES firmas: quién la registró (usuario_id),
--         quién cobró (confirmado_por) y quién anuló (anulado_por). Antes solo
--         la primera, así que un cobro mal hecho no tenía dueño.
--   D-37  la I.E. organizadora inscribe a sus propios estudiantes: modalidad
--         'organizadora' con tarifa propia, `organizaciones.institucion_id`
--         para saber cuál colegio es el anfitrión, y la modalidad guardada
--         como snapshot en `inscripciones` junto al monto que decidió.
--
-- Orden de creación respeta las dependencias de claves foráneas.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;


-- ---------------------------------------------------------------------
-- Tenant. En este MVP solo existe UNASAM / I.E. Víctor Valenzuela Guardia.
--
-- institucion_id (D-37): la I.E. que esta organización ES, cuando además de
-- organizar inscribe a sus propios estudiantes. Es lo que permite reconocer al
-- colegio anfitrión sin comparar su nombre. NULABLE y no provisional: una
-- organización puede no tener estudiantes propios en su concurso.
--
-- Va aquí y no como un booleano en la I.E. porque ser anfitriona es propiedad
-- de la RELACIÓN con la organización, no del colegio: el catálogo de I.E. es
-- global y compartido entre organizaciones, y ese flag sería falso para
-- cualquier otro inquilino en cuanto exista un segundo.
--
-- La clave foránea se añade más abajo, después de crear
-- `instituciones_educativas`: esta tabla es la raíz del modelo y se crea
-- primero, así que la referencia va hacia adelante.
-- ---------------------------------------------------------------------
CREATE TABLE organizaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    institucion_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_organizacion_institucion (institucion_id)
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
--
-- 'organizadora' (D-37) es la modalidad de los estudiantes de la I.E. que
-- organiza el concurso. Hoy vale lo mismo que 'publica' —S/ 10.00— y aun así
-- es una fila aparte a propósito: el día que una se mueva, la otra no se mueve
-- con ella. Reusar 'publica' obligaría a reclasificar el colegio anfitrión
-- entero para cambiarle el precio, arrastrando a todos los demás públicos.
-- ---------------------------------------------------------------------
CREATE TABLE tarifas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    concurso_id INT UNSIGNED NOT NULL,
    tipo_origen ENUM('publica','privada','libre','organizadora') NOT NULL,
    monto DECIMAL(6,2) NOT NULL,
    UNIQUE KEY uk_tarifa (concurso_id, tipo_origen),
    CONSTRAINT fk_tarifa_concurso
        FOREIGN KEY (concurso_id) REFERENCES concursos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Adulto responsable de uno o varios participantes.
--
-- Cubre los dos casos del concurso (D-28):
--   · el apoderado de un estudiante libre —varios hermanos comparten uno—;
--   · el docente delegado de una I.E., que es el encargado de su delegación y
--     por tanto el apoderado de los treinta estudiantes que inscribe.
--
-- Es UNA tabla y no dos porque es UNA persona: el mismo docente puede además
-- inscribir a su propio hijo como libre, y con dos tablas existiría dos veces
-- con dos celulares que divergirían.
--
-- Va antes que `instituciones_educativas` porque esa tabla la referencia.
--
-- dni: NOT NULL UNIQUE. Es lo único que permite reconocer a la persona y
--   reutilizarla en lugar de duplicarla; sin él, el docente que vuelve cada año
--   sería un apoderado nuevo cada año.
-- correo: NULL. Al docente delegado se le exige (es el canal por el que se le
--   escribe a la delegación); al apoderado de un libre no se le pide.
-- ---------------------------------------------------------------------
CREATE TABLE apoderados (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dni VARCHAR(15) NOT NULL UNIQUE,
    ap_paterno VARCHAR(100) NOT NULL,
    ap_materno VARCHAR(100) NOT NULL,
    nombres VARCHAR(150) NOT NULL,
    celular VARCHAR(20) NOT NULL,
    correo VARCHAR(150) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
-- Catálogo GLOBAL, compartido entre organizaciones (no aislado por tenant).
--
-- El DIRECTOR vive embebido aquí: son datos persistentes de la I.E. y no es
-- apoderado de nadie, así que no tiene por qué existir en `apoderados`.
--
-- El DOCENTE DELEGADO ya no (D-28): es el encargado de la delegación y por
-- tanto el apoderado de sus participantes, así que vive en `apoderados` y aquí
-- solo se guarda a cuál apunta. Antes estaba embebido en seis columnas, y eso
-- obligaba a que la misma persona existiera dos veces —una aquí y otra como
-- apoderado— con dos celulares destinados a divergir.
-- ---------------------------------------------------------------------
CREATE TABLE instituciones_educativas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL,
    distrito VARCHAR(100) NOT NULL,
    provincia VARCHAR(100) NOT NULL,
    departamento VARCHAR(100) NOT NULL,
    tipo ENUM('publica','privada') NOT NULL,
    direccion VARCHAR(250),

    -- El encargado de la delegación. NOT NULL: una delegación sin encargado no
    -- tiene a quién asignar como apoderado de sus estudiantes, y el formulario
    -- de la I.E. ya lo exigía.
    docente_delegado_id INT UNSIGNED NOT NULL,

    director_ap_paterno VARCHAR(100),
    director_ap_materno VARCHAR(100),
    director_nombres VARCHAR(150),
    director_celular VARCHAR(20),
    director_correo VARCHAR(150),
    director_dni VARCHAR(15) NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_ie_docente_delegado
        FOREIGN KEY (docente_delegado_id) REFERENCES apoderados(id),

    -- Soporta el buscador anti-duplicados de la Fase 2.
    INDEX idx_ie_nombre (nombre),
    INDEX idx_ie_ubicacion (departamento, provincia, distrito),
    INDEX idx_ie_docente_delegado (docente_delegado_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- La referencia pendiente de `organizaciones` (D-37). Se cierra aquí, y no en
-- el CREATE de esa tabla, porque `organizaciones` es la raíz del modelo y se
-- crea primero; adelantar todo el catálogo de colegios solo para satisfacer
-- una referencia opcional invertiría la lectura del esquema.
ALTER TABLE organizaciones
    ADD CONSTRAINT fk_organizacion_institucion
        FOREIGN KEY (institucion_id) REFERENCES instituciones_educativas(id);


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
-- dni: ÚNICO dentro del concurso (D-31). Un estudiante, un documento, una
-- inscripción. La restricción vive aquí y no solo en la aplicación porque dos
-- secretarias cobrando a la vez pueden pasar la misma validación en PHP.
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
    CONSTRAINT uq_participante_documento UNIQUE (concurso_id, dni),
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
--
-- tipo_origen: la modalidad que ELIGIÓ ese monto, congelada con él (D-37).
-- Antes se derivaba en vivo de `instituciones_educativas.tipo` cada vez que se
-- pintaba un carné, así que reclasificar un colegio de pública a privada
-- cambiaba la modalidad impresa en los carnés ya emitidos mientras su monto
-- seguía diciendo S/ 10.00. Los dos datos son el retrato de lo que se cobró el
-- día que se cobró; tenerlos separados —uno congelado y el otro en vivo— es lo
-- que permitía que se contradijeran.
-- ---------------------------------------------------------------------
CREATE TABLE inscripciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    participante_id INT UNSIGNED NOT NULL,
    categoria_id INT UNSIGNED NOT NULL COMMENT 'movido desde participantes — decisión D-01',
    usuario_id INT UNSIGNED NOT NULL COMMENT 'secretaria/admin que registró',
    estado ENUM('pendiente','confirmada','anulada') NOT NULL DEFAULT 'pendiente',
    tipo_origen ENUM('publica','privada','libre','organizadora') NOT NULL
        COMMENT 'modalidad con la que se cobró — snapshot, decisión D-37',
    monto DECIMAL(6,2) NOT NULL,
    medio_pago ENUM('yape','transferencia','efectivo') NULL,
    yape_codigo_seguridad CHAR(3) NULL COMMENT 'solo si medio_pago = yape',
    fecha_pago DATETIME NULL,
    confirmado_por INT UNSIGNED NULL COMMENT 'quién confirmó el pago — D-39',
    motivo_anulacion VARCHAR(250) NULL,
    anulado_por INT UNSIGNED NULL COMMENT 'quién anuló — D-39',
    requiere_devolucion BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_inscripcion_participante
        FOREIGN KEY (participante_id) REFERENCES participantes(id),
    CONSTRAINT fk_inscripcion_categoria
        FOREIGN KEY (categoria_id) REFERENCES categorias(id),
    CONSTRAINT fk_inscripcion_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_inscripcion_confirmado_por
        FOREIGN KEY (confirmado_por) REFERENCES usuarios(id),
    CONSTRAINT fk_inscripcion_anulado_por
        FOREIGN KEY (anulado_por) REFERENCES usuarios(id),

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
-- El PDF NO se guarda: se genera al vuelo en cada descarga (D-24). Lo que se
-- registra aquí es el hecho de negocio —esta inscripción tiene carné emitido,
-- y desde cuándo—, que es lo único que no se puede recalcular.
--
-- codigo_qr: el CÓDIGO que el QR resuelve, no la URL. La URL se arma al vuelo
--   con GeneradorCarne::urlPublica(), que lee `app.url_base`.
-- ---------------------------------------------------------------------
CREATE TABLE carnes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inscripcion_id INT UNSIGNED NOT NULL UNIQUE,
    codigo_qr VARCHAR(100) NOT NULL,
    generado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_carne_inscripcion
        FOREIGN KEY (inscripcion_id) REFERENCES inscripciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
