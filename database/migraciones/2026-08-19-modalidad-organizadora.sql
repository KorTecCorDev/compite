-- ---------------------------------------------------------------------
-- D-37 — la I.E. organizadora inscribe a sus propios estudiantes
-- ---------------------------------------------------------------------
-- El COCIAP tiene delegaciones propias: los estudiantes matriculados este año
-- en la I.E. que organiza el concurso. Compiten en las mismas categorías que
-- todos —nivel + grado—, pero en una bolsa aparte, y pagan una tarifa propia.
--
-- Hoy vale lo mismo que la de una I.E. pública (S/ 10.00). Son DOS filas
-- distintas de `tarifas` a propósito, por decisión del propietario
-- (2026-08-19): el día que una se mueva, la otra no se mueve con ella. Reusar
-- 'publica' habría obligado a reclasificar el colegio entero para cambiarle el
-- precio, y a arrastrar en el cambio a todos los demás colegios públicos.
--
-- Bolsas de competencia confirmadas, por cada nivel + grado:
--     · privada + libre   (juntos)
--     · publica
--     · organizadora
--
-- Cuatro cambios:
--   1. `organizaciones.institucion_id` — enlace explícito «esta organización
--      ES esta I.E.». Sin él no hay forma de saber que un colegio es el
--      anfitrión salvo comparar su nombre, que es exactamente la dependencia
--      frágil que D-21 sacó de la base.
--   2. `tarifas.tipo_origen` gana el valor 'organizadora'.
--   3. `inscripciones.tipo_origen` — la modalidad pasa a guardarse como
--      SNAPSHOT junto al monto, en vez de derivarse en vivo de `ie.tipo` cada
--      vez que se pinta un carné. Hasta ahora `monto` era snapshot y la
--      modalidad no, así que reclasificar un colegio de pública a privada
--      cambiaba la modalidad impresa en los carnés YA EMITIDOS mientras su
--      monto seguía diciendo S/ 10.00. El comentario de GeneradorCarne dice
--      que la modalidad se deriva así «para que el carné no pueda contradecir
--      a la tarifa que se cobró»; la intención estaba escrita, no cumplida.
--   4. La fila de tarifa de la modalidad nueva.
--
-- No se crea la I.E. organizadora ni se enlaza: sus datos —dirección, distrito,
-- director, y un docente delegado CON DNI, que `docente_delegado_id NOT NULL`
-- exige— los captura la secretaria en /instituciones. El paso 7 recuerda el
-- UPDATE que queda pendiente; hasta que se ejecute, nadie resuelve a
-- 'organizadora' y el sistema se comporta exactamente como hoy.
--
-- No se indexa `inscripciones.tipo_origen`: con cuatro valores y el volumen de
-- un concurso, un índice de esa cardinalidad no lo usaría el optimizador.
--
-- Idempotente: cada paso comprueba el estado antes de actuar.
-- ---------------------------------------------------------------------


-- Paso 0 — diagnóstico. Si esta consulta devuelve filas, resuélvelas antes de
-- seguir: son inscripciones que el paso 4 no sabría clasificar —un participante
-- de delegación sin institución— y que dejarían `tipo_origen` en NULL, con lo
-- que el paso 5 fallaría al ponerlo NOT NULL y la tabla quedaría a medias.
SELECT i.id            AS inscripcion,
       p.codigo_correlativo,
       p.tipo_participante,
       p.institucion_id,
       'Participante de delegación sin institución' AS problema
  FROM inscripciones i
  JOIN participantes p ON p.id = i.participante_id
 WHERE p.tipo_participante = 'delegacion'
   AND p.institucion_id IS NULL;


-- Paso 1 — la organización gana la referencia a la I.E. que ella misma es.
--
-- NULABLE a propósito, y no es provisional: una organización puede no tener
-- estudiantes propios en su concurso, o no estar todavía en el catálogo (es el
-- caso hoy: la I.E. Víctor Valenzuela Guardia aún no existe como colegio).
--
-- Va en `organizaciones` y NO como un booleano `es_organizadora` en la I.E.
-- porque ser anfitriona es una propiedad de la RELACIÓN con la organización,
-- no del colegio: `instituciones_educativas` es un catálogo global compartido
-- entre organizaciones (§3 del plan), y un flag ahí sería falso para cualquier
-- otro inquilino en cuanto exista un segundo. Es la misma familia de error que
-- P-07.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'organizaciones'
       AND COLUMN_NAME = 'institucion_id'
);
SET @sql := IF(@existe = 0, '
    ALTER TABLE organizaciones
        ADD COLUMN institucion_id INT UNSIGNED NULL AFTER nombre,
        ADD CONSTRAINT fk_organizacion_institucion
            FOREIGN KEY (institucion_id) REFERENCES instituciones_educativas(id),
        ADD INDEX idx_organizacion_institucion (institucion_id)
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 2 — `tarifas` admite la modalidad nueva. El ENUM se amplía, no se
-- reordena: los valores existentes conservan su posición y ninguna fila se
-- reescribe.
SET @tipo := (
    SELECT COLUMN_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tarifas'
       AND COLUMN_NAME = 'tipo_origen'
);
SET @sql := IF(@tipo LIKE '%organizadora%', 'DO 0', "
    ALTER TABLE tarifas
      MODIFY tipo_origen ENUM('publica','privada','libre','organizadora') NOT NULL
");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 3 — la inscripción gana su modalidad. Nulable de momento: las filas que
-- ya existen todavía no la tienen, y el paso 4 es quien se la calcula.
--
-- Va junto a `monto` porque es el dato que lo decidió: los dos son el retrato
-- de lo que se cobró el día que se cobró, y tenerlos separados —uno congelado
-- y el otro en vivo— es lo que permitía que se contradijeran.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inscripciones'
       AND COLUMN_NAME = 'tipo_origen'
);
SET @sql := IF(@existe = 0, "
    ALTER TABLE inscripciones
        ADD COLUMN tipo_origen ENUM('publica','privada','libre','organizadora') NULL
            COMMENT 'modalidad con la que se cobró — snapshot, decisión D-37'
            AFTER estado
", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 4 — rellenar hacia atrás, con la MISMA regla que hasta hoy se aplicaba
-- en lectura: 'libre' para el estudiante independiente, y el tipo de la I.E.
-- para el de delegación. Así ninguna inscripción ya emitida cambia de modalidad
-- por efecto de esta migración.
--
-- La rama 'organizadora' está aquí por corrección, no porque vaya a disparar
-- hoy: si alguien enlaza la I.E. anfitriona ANTES de migrar, sus inscripciones
-- previas quedan bien clasificadas; con el enlace en NULL la condición es falsa
-- para todas las filas y el CASE cae al tipo de la I.E., como debe.
UPDATE inscripciones i
       JOIN participantes p  ON p.id = i.participante_id
  LEFT JOIN instituciones_educativas ie ON ie.id = p.institucion_id
  LEFT JOIN concursos c      ON c.id = p.concurso_id
  LEFT JOIN organizaciones o ON o.id = c.organizacion_id
   SET i.tipo_origen = CASE
         WHEN p.tipo_participante = 'libre' THEN 'libre'
         WHEN o.institucion_id IS NOT NULL
          AND p.institucion_id = o.institucion_id THEN 'organizadora'
         ELSE ie.tipo
       END
 WHERE i.tipo_origen IS NULL;


-- Paso 5 — la modalidad pasa a obligatoria. Aquí es donde la migración se
-- detiene si el paso 0 listó filas y no se resolvieron: MySQL rechaza el NOT
-- NULL sobre una columna que contiene NULL, y todo lo hecho hasta aquí es
-- aditivo, así que nada se pierde. Se corrigen esas inscripciones y se vuelve
-- a ejecutar.
SET @esNulable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inscripciones'
       AND COLUMN_NAME = 'tipo_origen'
);
SET @sql := IF(@esNulable = 'YES', "
    ALTER TABLE inscripciones
      MODIFY tipo_origen ENUM('publica','privada','libre','organizadora') NOT NULL
             COMMENT 'modalidad con la que se cobró — snapshot, decisión D-37'
", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 6 — la tarifa. S/ 10.00, confirmada por el propietario el 2026-08-19.
--
-- Para todo concurso que aún no la tenga, en vez de fijar `concurso_id = 1`:
-- el mismo INSERT sirve si mañana esta base sostiene otro concurso, y el
-- `NOT EXISTS` lo deja re-ejecutable sin estrellarse contra uk_tarifa.
INSERT INTO tarifas (concurso_id, tipo_origen, monto)
SELECT c.id, 'organizadora', 10.00
  FROM concursos c
 WHERE NOT EXISTS (
       SELECT 1 FROM tarifas t
        WHERE t.concurso_id = c.id AND t.tipo_origen = 'organizadora'
 );


-- Paso 7 — lo que queda pendiente y esta migración NO puede hacer sola.
--
-- Mientras `organizaciones.institucion_id` siga en NULL, ninguna inscripción
-- resolverá a 'organizadora' y el sistema se comporta igual que antes. El
-- enlace se hace UNA vez, después de dar de alta la I.E. anfitriona en
-- /instituciones con su docente delegado:
--
--     UPDATE organizaciones
--        SET institucion_id = <id de la I.E. organizadora>
--      WHERE id = <id de la organización>;
--
-- Esta consulta lo recuerda mientras siga sin hacerse.
SELECT o.id     AS organizacion,
       o.nombre,
       'Sin I.E. enlazada — sus estudiantes se cobrarían como pública' AS pendiente
  FROM organizaciones o
 WHERE o.institucion_id IS NULL;
