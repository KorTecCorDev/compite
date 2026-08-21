-- ---------------------------------------------------------------------
-- D-50 — corregir el registro de participación
-- ---------------------------------------------------------------------
-- Hasta hoy `participantes` era la ÚNICA mutación del sistema sin firma, y en
-- realidad ni siquiera había mutación: `Participante` solo sabía `crear()`. Un
-- DNI mal tecleado, un apellido con una letra de más o un colegio equivocado no
-- se podían arreglar. La única salida era anular y volver a registrar, y eso
-- deja rastro de dos filas, consume un correlativo nuevo y —comprobado en los
-- datos reales del 20-ago— **duplica a la persona**: el participante 20 y el 21
-- son el mismo estudiante con DNI que difiere en un dígito, porque al reingresar
-- a mano el documento salió distinto.
--
-- Esta tabla es el registro de auditoría de esas correcciones. Contra D-39, que
-- exige saber quién hizo qué con el dinero y con el concurso.
--
--
-- CUATRO DECISIONES DE DISEÑO, Y POR QUÉ
-- --------------------------------------
--   1. **FK real sobre `participante_id`**, no una tabla polimórfica
--      `entidad` + `entidad_id`. Una FK polimórfica no la puede validar ninguna
--      base de datos, y un registro de auditoría que puede apuntar a filas
--      inexistentes no sirve como auditoría.
--
--   2. **`campo` con espacio de nombres** (`participante.dni`,
--      `inscripcion.categoria_id`): una sola tabla cubre las dos entidades sin
--      renunciar a esa FK.
--
--   3. **Una fila por campo cambiado**, no un JSON con el diff. «¿Cuál era el
--      DNI antes?» se responde con un WHERE; contra un JSON, no.
--
--   4. **`anterior` y `nuevo` guardan el texto legible** —«Primaria 3°»,
--      «IE COCIAP»—, no el id. Un registro de auditoría tiene que poder leerse
--      sin unirlo a tablas que pueden haber cambiado después. El precio: no se
--      puede agrupar analíticamente por id. Si algún día hace falta, se añade
--      una columna al lado; lo que no se puede es recuperar un texto que nunca
--      se guardó.
--
-- El `motivo` se repite en todas las filas de un mismo `lote`. Es
-- denormalización deliberada: partirlo en dos tablas para ahorrar 250
-- caracteres cada cuatro filas no compensa la unión de por vida.
--
--
-- `inscripcion_id` ES NULABLE y no es un descuido: los datos del estudiante
-- —DNI, apellidos, nombres— pertenecen al participante, no a una inscripción
-- concreta. Hoy siempre se corrigen desde una, así que siempre viajará con
-- valor; el día que se corrija desde otro sitio, la fila sigue siendo válida.
--
-- ON DELETE no se declara en ninguna FK: aquí nada se borra. Las inscripciones
-- se anulan y los usuarios se desactivan, justamente para que estas referencias
-- sigan resolviendo dentro de un año.
--
-- Idempotente: `IF NOT EXISTS` y un diagnóstico al final.
-- ---------------------------------------------------------------------


-- Paso 1 — la tabla.
CREATE TABLE IF NOT EXISTS correcciones (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    participante_id INT UNSIGNED NOT NULL,
    inscripcion_id  INT UNSIGNED NULL,
    lote            CHAR(32)     NOT NULL
                    COMMENT 'agrupa los campos corregidos en un mismo envío del formulario',
    campo           VARCHAR(40)  NOT NULL
                    COMMENT 'con espacio de nombres: participante.dni, inscripcion.categoria_id',
    anterior        VARCHAR(255) NULL
                    COMMENT 'valor LEGIBLE previo, no el id — decisión 4 de D-50',
    nuevo           VARCHAR(255) NULL,
    motivo          VARCHAR(250) NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_correccion_participante
        FOREIGN KEY (participante_id) REFERENCES participantes(id),
    CONSTRAINT fk_correccion_inscripcion
        FOREIGN KEY (inscripcion_id) REFERENCES inscripciones(id),
    CONSTRAINT fk_correccion_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id),

    -- El primero sirve al historial que se pinta en la pantalla de corrección,
    -- que es por inscripción. El segundo agrupa un envío completo.
    INDEX idx_correccion_inscripcion (inscripcion_id),
    INDEX idx_correccion_participante (participante_id),
    INDEX idx_correccion_lote (lote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Paso 2 — diagnóstico. Que la tabla exista no dice que las FK se hayan creado:
-- si `participantes` o `usuarios` tuvieran un motor o un charset distinto,
-- MariaDB rechazaría la restricción y la tabla podría quedar sin ella.
SELECT 'correcciones' AS tabla,
       (SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'correcciones')      AS existe,
       (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'correcciones'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY')                                AS claves_foraneas,
       'se esperan 3 claves foráneas'                                          AS nota;
