-- ---------------------------------------------------------------------
-- D-39 — quién cobró y quién anuló
-- ---------------------------------------------------------------------
-- Hasta ahora la única acción firmada era **crear** la inscripción:
-- `inscripciones.usuario_id` guarda quién la registró. Las otras dos, no:
--
--   · `Inscripcion::confirmarPago()` escribía medio y fecha, pero no quién
--     cobró. Es el acto que mueve dinero.
--   · `Inscripcion::anular()` escribía estado y motivo, pero no quién anuló.
--     Es el acto que saca a alguien del concurso y, si había pago, el que manda
--     un monto al fondo de devoluciones.
--
-- Con varias secretarias trabajando a la vez durante el registro, eso significa
-- que un cobro mal hecho o una anulación indebida **no tenían dueño**: el
-- sistema no podía decir quién. Que es justo lo que hay que poder decir.
--
-- Las dos columnas son NULLABLE y no puede ser de otra forma: una inscripción
-- pendiente no ha sido cobrada por nadie todavía, y la mayoría no se anulan
-- nunca. NULL aquí significa «no ha pasado», no «no se sabe».
--
-- Las filas anteriores a esta migración se quedan en NULL a propósito. Rellenar
-- con el que registró sería inventar: casi siempre será la misma persona, pero
-- «casi siempre» no es una firma, y una firma inventada es peor que ninguna.
--
-- ON DELETE no se declara porque los usuarios NO se borran, se desactivan
-- (`usuarios.activo`), justamente para que estas referencias sigan resolviendo.
--
-- Idempotente: comprueba el estado antes de actuar.
-- ---------------------------------------------------------------------


-- Paso 1 — las dos columnas.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inscripciones'
       AND COLUMN_NAME = 'confirmado_por'
);
SET @sql := IF(@existe = 0, "
    ALTER TABLE inscripciones
        ADD COLUMN confirmado_por INT UNSIGNED NULL
            COMMENT 'quién confirmó el pago — D-39'
            AFTER fecha_pago,
        ADD COLUMN anulado_por INT UNSIGNED NULL
            COMMENT 'quién anuló — D-39'
            AFTER motivo_anulacion
", 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 2 — claves foráneas e índices. Van aparte del ALTER anterior para que
-- una segunda ejecución que ya tenga las columnas pueda seguir comprobando esto.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inscripciones'
       AND CONSTRAINT_NAME = 'fk_inscripcion_confirmado_por'
);
SET @sql := IF(@existe = 0, '
    ALTER TABLE inscripciones
        ADD CONSTRAINT fk_inscripcion_confirmado_por
            FOREIGN KEY (confirmado_por) REFERENCES usuarios(id),
        ADD CONSTRAINT fk_inscripcion_anulado_por
            FOREIGN KEY (anulado_por) REFERENCES usuarios(id)
', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 3 — cuántas filas quedan sin firma de cobro o de anulación. No es un
-- problema que resolver: es el retrato de lo que ocurrió antes de que el
-- sistema supiera registrarlo.
SELECT
    SUM(estado = 'confirmada' AND confirmado_por IS NULL) AS cobros_sin_firma,
    SUM(estado = 'anulada'    AND anulado_por    IS NULL) AS anulaciones_sin_firma,
    'Anteriores a D-39. Se dejan en NULL a propósito: una firma inventada es peor que ninguna.' AS nota
  FROM inscripciones;
