-- ---------------------------------------------------------------------
-- D-46 — Dejar la base lista para los datos reales
-- ---------------------------------------------------------------------
-- Borra participantes, inscripciones, carnés, instituciones educativas y
-- apoderados. Conserva la organización, el concurso, las 11 categorías, las 4
-- tarifas y los usuarios.
--
-- ⚠ ESTO NO SE DESHACE. Antes de ejecutarla, respaldo:
--
--     mysqldump -u USUARIO -p BASE | gzip > antes-de-limpiar.sql.gz
--
-- Y compruébalo: que el archivo pesa, que se descomprime y que dentro hay
-- `INSERT`. Un respaldo que nadie abrió no es un respaldo.
--
--
-- EL SEGURO
-- ---------
-- Tal como está, **esta migración no borra nada**. Ejecútala y te dirá cuántas
-- filas se llevaría por delante. Solo cuando hayas mirado esa lista y tengas el
-- respaldo, cambia la línea de abajo a 1 y vuelve a ejecutarla.
--
-- El seguro existe porque este archivo no puede distinguir tu base de pruebas
-- de la de producción con inscripciones reales dentro, y el día que alguien lo
-- ejecute por costumbre habrá dinero cobrado en esas filas.

SET @LIMPIAR := 0;


-- ---------------------------------------------------------------------
-- Paso 0 — qué se va a borrar. Se ejecuta siempre.
-- ---------------------------------------------------------------------
SELECT 'SE BORRA'          AS que, 'carnes'                   AS tabla, COUNT(*) AS filas FROM carnes
UNION ALL SELECT 'SE BORRA', 'inscripciones',            COUNT(*) FROM inscripciones
UNION ALL SELECT 'SE BORRA', 'participantes',            COUNT(*) FROM participantes
UNION ALL SELECT 'SE BORRA', 'instituciones_educativas', COUNT(*) FROM instituciones_educativas
UNION ALL SELECT 'SE BORRA', 'apoderados',               COUNT(*) FROM apoderados
UNION ALL SELECT 'se conserva', 'organizaciones',        COUNT(*) FROM organizaciones
UNION ALL SELECT 'se conserva', 'concursos',             COUNT(*) FROM concursos
UNION ALL SELECT 'se conserva', 'categorias',            COUNT(*) FROM categorias
UNION ALL SELECT 'se conserva', 'tarifas',               COUNT(*) FROM tarifas
UNION ALL SELECT 'se conserva', 'usuarios',              COUNT(*) FROM usuarios;

-- Y el dinero que hay dentro, que es lo que de verdad hay que mirar antes.
SELECT COUNT(*)                                   AS inscripciones_cobradas,
       COALESCE(SUM(monto), 0)                    AS soles_confirmados,
       'Si esto no es cero, para y comprueba que son de prueba' AS aviso
  FROM inscripciones
 WHERE estado = 'confirmada';


-- ---------------------------------------------------------------------
-- Paso 1 — soltar la marca de institución anfitriona.
-- ---------------------------------------------------------------------
-- `organizaciones.institucion_id` apunta a una fila de `instituciones_educativas`
-- (D-37). Mientras apunte, la clave foránea impide borrar esa institución, así
-- que la marca se suelta primero.
--
-- CONSECUENCIA: después de esta limpieza el concurso se queda **sin institución
-- anfitriona**. Hay que volver a darla de alta en /instituciones y marcarla con
-- papel «Anfitriona» ANTES del primer estudiante del COCIAP. Si se olvida, sus
-- estudiantes se cobran como cualquier pública y compiten en la bolsa
-- equivocada, sin ningún aviso. El paso 8 lo recuerda.
SET @sql := IF(@LIMPIAR = 1,
    'UPDATE organizaciones SET institucion_id = NULL WHERE institucion_id IS NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------
-- Pasos 2 a 6 — borrar, en el orden que permiten las claves foráneas.
-- ---------------------------------------------------------------------
-- Con DELETE y no con TRUNCATE: TRUNCATE no respeta las claves foráneas —falla
-- o vacía sin comprobar, según el motor— y además no se puede deshacer dentro
-- de una transacción. DELETE sí, y en tablas de este tamaño la diferencia de
-- velocidad no existe.
--
-- El orden es el inverso al de las dependencias:
--   carnes → inscripciones → participantes → instituciones → apoderados

SET @sql := IF(@LIMPIAR = 1, 'DELETE FROM carnes', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@LIMPIAR = 1, 'DELETE FROM inscripciones', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@LIMPIAR = 1, 'DELETE FROM participantes', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@LIMPIAR = 1, 'DELETE FROM instituciones_educativas', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@LIMPIAR = 1, 'DELETE FROM apoderados', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------
-- Paso 7 — reiniciar los contadores.
-- ---------------------------------------------------------------------
-- No es cosmética. El código correlativo del participante se arma con su `id`
-- (D-04, D-12): sin reiniciar, el primer estudiante real saldría con un número
-- heredado de las pruebas —COCIAP2026-0024-…— y ese número va impreso en su
-- carné y en la nómina que ve la secretaria. Con el contador a cero, el primero
-- es el 0001.
SET @sql := IF(@LIMPIAR = 1, 'ALTER TABLE carnes AUTO_INCREMENT = 1', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@LIMPIAR = 1, 'ALTER TABLE inscripciones AUTO_INCREMENT = 1', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@LIMPIAR = 1, 'ALTER TABLE participantes AUTO_INCREMENT = 1', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@LIMPIAR = 1, 'ALTER TABLE instituciones_educativas AUTO_INCREMENT = 1', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(@LIMPIAR = 1, 'ALTER TABLE apoderados AUTO_INCREMENT = 1', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------
-- Paso 8 — cómo quedó, y qué falta hacer a mano.
-- ---------------------------------------------------------------------
SELECT CASE WHEN @LIMPIAR = 1
            THEN 'LIMPIEZA EJECUTADA'
            ELSE 'NO SE TOCÓ NADA — pon @LIMPIAR := 1 arriba cuando tengas el respaldo'
       END AS estado;

SELECT 'participantes' AS tabla, COUNT(*) AS quedan FROM participantes
UNION ALL SELECT 'inscripciones',            COUNT(*) FROM inscripciones
UNION ALL SELECT 'carnes',                   COUNT(*) FROM carnes
UNION ALL SELECT 'instituciones_educativas', COUNT(*) FROM instituciones_educativas
UNION ALL SELECT 'apoderados',               COUNT(*) FROM apoderados
UNION ALL SELECT 'concursos (debe ser 1)',   COUNT(*) FROM concursos
UNION ALL SELECT 'categorias (debe ser 11)', COUNT(*) FROM categorias
UNION ALL SELECT 'tarifas (debe ser 4)',     COUNT(*) FROM tarifas
UNION ALL SELECT 'usuarios',                 COUNT(*) FROM usuarios;

-- Lo que queda pendiente y esta migración no puede hacer: la I.E. anfitriona
-- necesita datos que solo tiene la secretaria —dirección, director y un docente
-- delegado con DNI—, así que se da de alta desde /instituciones.
SELECT o.id                AS organizacion,
       o.nombre,
       'Sin I.E. anfitriona: date de alta en /instituciones y márcala con papel «Anfitriona» ANTES del primer estudiante del COCIAP' AS pendiente
  FROM organizaciones o
 WHERE o.institucion_id IS NULL;
