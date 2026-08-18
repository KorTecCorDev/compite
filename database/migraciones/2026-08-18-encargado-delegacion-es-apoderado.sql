-- ---------------------------------------------------------------------
-- D-28 — el docente delegado ES el apoderado de su delegación
-- ---------------------------------------------------------------------
-- Hasta ahora el docente delegado vivía embebido en seis columnas de
-- `instituciones_educativas`, y los participantes de delegación se guardaban
-- con `apoderado_id = NULL`. La misma persona podía existir dos veces —una como
-- docente delegado de su colegio y otra como apoderado del hijo que inscribió
-- como libre— con dos celulares destinados a divergir.
--
-- Desde el 2026-08-18 el docente delegado es una fila de `apoderados` y la I.E.
-- solo guarda a cuál apunta. Cada participante de delegación queda vinculado a
-- ese apoderado en el momento de inscribirse.
--
-- REQUISITO: todo docente delegado tiene que tener DNI. Antes era opcional. Si
-- alguna I.E. no lo tiene, esta migración **se detiene a propósito** en el paso
-- 5: sin documento no se puede crear su apoderado, porque `apoderados.dni` es
-- NOT NULL UNIQUE y es lo único que permite reconocer a la persona. El paso 0
-- lista cuáles hay que completar antes de volver a ejecutarla.
--
-- Idempotente: cada paso comprueba el estado antes de actuar.
-- ---------------------------------------------------------------------


-- Paso 0 — diagnóstico. Si esta consulta devuelve filas, complétalas antes de
-- seguir: la migración fallará en el paso 5 y no habrá tocado nada irreversible.
--
-- Va condicionado como todo lo demás porque en una segunda ejecución la columna
-- que consulta ya no existe, y un diagnóstico que rompe la migración que viene a
-- proteger no sirve de nada.
SET @existeDni := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instituciones_educativas'
       AND COLUMN_NAME = 'docente_delegado_dni'
);
SET @sql := IF(@existeDni > 0, '
    SELECT id, nombre, "Falta el DNI del docente delegado" AS problema
      FROM instituciones_educativas
     WHERE docente_delegado_dni IS NULL OR docente_delegado_dni = ""
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 1 — `apoderados` gana el correo que el docente delegado sí tiene y el
-- apoderado de un estudiante libre no. NULL: al libre no se le pide.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'apoderados' AND COLUMN_NAME = 'correo'
);
SET @sql := IF(@existe = 0,
    'ALTER TABLE apoderados ADD COLUMN correo VARCHAR(150) NULL AFTER celular',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 2 — la I.E. gana la referencia. Nulable de momento: todavía no hay a
-- quién apuntar.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instituciones_educativas'
       AND COLUMN_NAME = 'docente_delegado_id'
);
SET @sql := IF(@existe = 0,
    'ALTER TABLE instituciones_educativas ADD COLUMN docente_delegado_id INT UNSIGNED NULL AFTER direccion',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 3 — promover a cada docente delegado a apoderado.
--
-- `NOT EXISTS` cubre dos casos reales: que el docente ya esté en la tabla
-- porque inscribió a su propio hijo como libre, y que dos colegios compartan
-- docente. En ambos se reutiliza la fila existente en vez de estrellarse contra
-- el UNIQUE del DNI. GROUP BY porque dos I.E. con el mismo docente producirían
-- dos filas idénticas en el mismo INSERT, y el UNIQUE tampoco lo perdonaría.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instituciones_educativas'
       AND COLUMN_NAME = 'docente_delegado_dni'
);
SET @sql := IF(@existe > 0, '
    INSERT INTO apoderados (dni, ap_paterno, ap_materno, nombres, celular, correo)
    SELECT ie.docente_delegado_dni,
           COALESCE(ie.docente_delegado_ap_paterno, ""),
           COALESCE(ie.docente_delegado_ap_materno, ""),
           COALESCE(ie.docente_delegado_nombres, ""),
           COALESCE(ie.docente_delegado_celular, ""),
           ie.docente_delegado_correo
      FROM instituciones_educativas ie
     WHERE ie.docente_delegado_dni IS NOT NULL
       AND ie.docente_delegado_dni <> ""
       AND NOT EXISTS (SELECT 1 FROM apoderados a WHERE a.dni = ie.docente_delegado_dni)
     GROUP BY ie.docente_delegado_dni
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 4 — enlazar cada I.E. con el apoderado que le corresponde.
SET @sql := IF(@existe > 0, '
    UPDATE instituciones_educativas ie
      JOIN apoderados a ON a.dni = ie.docente_delegado_dni
       SET ie.docente_delegado_id = a.id
     WHERE ie.docente_delegado_id IS NULL
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 5 — la referencia pasa a obligatoria. Aquí es donde la migración se
-- detiene si alguna I.E. se quedó sin enlazar (docente sin DNI): MySQL rechaza
-- el NOT NULL sobre una columna que contiene NULL. Nada de lo anterior se
-- pierde; se completan los DNI que listó el paso 0 y se vuelve a ejecutar.
SET @esNulable := (
    SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instituciones_educativas'
       AND COLUMN_NAME = 'docente_delegado_id'
);
SET @sql := IF(@esNulable = 'YES',
    'ALTER TABLE instituciones_educativas MODIFY docente_delegado_id INT UNSIGNED NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 6 — clave foránea e índice.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instituciones_educativas'
       AND CONSTRAINT_NAME = 'fk_ie_docente_delegado'
);
SET @sql := IF(@existe = 0, '
    ALTER TABLE instituciones_educativas
      ADD CONSTRAINT fk_ie_docente_delegado
          FOREIGN KEY (docente_delegado_id) REFERENCES apoderados(id),
      ADD INDEX idx_ie_docente_delegado (docente_delegado_id)
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 7 — los participantes de delegación ya inscritos heredan el apoderado
-- de su institución. Sin esto, las inscripciones anteriores a la migración
-- serían las únicas sin encargado asignado, y el listado mentiría sobre ellas.
UPDATE participantes p
  JOIN instituciones_educativas ie ON ie.id = p.institucion_id
   SET p.apoderado_id = ie.docente_delegado_id
 WHERE p.tipo_participante = 'delegacion'
   AND p.apoderado_id IS NULL;


-- Paso 8 — soltar las seis columnas embebidas. Va al final a propósito: hasta
-- aquí la migración es reversible sin pérdida de datos.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'instituciones_educativas'
       AND COLUMN_NAME = 'docente_delegado_dni'
);
SET @sql := IF(@existe > 0, '
    ALTER TABLE instituciones_educativas
        DROP COLUMN docente_delegado_ap_paterno,
        DROP COLUMN docente_delegado_ap_materno,
        DROP COLUMN docente_delegado_nombres,
        DROP COLUMN docente_delegado_celular,
        DROP COLUMN docente_delegado_correo,
        DROP COLUMN docente_delegado_dni
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
