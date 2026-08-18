-- ---------------------------------------------------------------------
-- D-24 — el carné deja de guardarse como archivo en disco
-- ---------------------------------------------------------------------
-- Hasta ahora, confirmar un pago escribía un PDF en `storage/carnes/` y esta
-- tabla guardaba su ruta. Eso creaba tres problemas:
--
--   1. El PDF quedaba congelado con el diseño del día en que se emitió. Al
--      rediseñar el carné (tamaño ID-1, escudo institucional, QR corto), los
--      ya emitidos habrían seguido saliendo con la maqueta vieja.
--   2. `storage/carnes/` tenía que viajar al despliegue, o los carnés
--      desaparecían en producción.
--   3. Si el archivo se borraba, la descarga fallaba con «el archivo no está
--      en el servidor» y había que regenerarlo a mano.
--
-- Desde el 2026-08-18 el PDF se genera al vuelo en cada descarga. La tabla
-- sigue registrando el hecho de negocio —qué inscripción tiene carné emitido
-- y desde cuándo—, que es lo único que no se puede recalcular.
--
-- Idempotente: comprueba que la columna exista antes de soltarla, así que se
-- puede ejecutar más de una vez sin error.
-- ---------------------------------------------------------------------

SET @existe := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'carnes'
       AND COLUMN_NAME  = 'ruta_pdf'
);

SET @sql := IF(@existe > 0,
    'ALTER TABLE carnes DROP COLUMN ruta_pdf',
    'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
