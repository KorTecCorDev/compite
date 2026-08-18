-- ---------------------------------------------------------------------
-- D-31 — el documento de un participante no puede repetirse
-- ---------------------------------------------------------------------
-- Revierte D-05, que decidía advertir el duplicado sin impedirlo. La decisión
-- del propietario (2026-08-18) es que cada estudiante tiene un documento y solo
-- uno dentro del concurso.
--
-- La advertencia sin freno no aguantó la primera prueba real: en la base de
-- pruebas quedó la misma estudiante inscrita CINCO veces con el mismo DNI, cada
-- una con su inscripción y su monto. Nadie ignoró el aviso a propósito; con la
-- nómina de un colegio delante, el aviso es una línea más que se lee de pasada.
--
-- La unicidad es POR CONCURSO, no absoluta: el mismo estudiante tiene que poder
-- volver a inscribirse el año que viene, y `participantes` guarda una fila por
-- concurso. Un UNIQUE solo sobre `dni` habría dejado la edición 2027 sin poder
-- registrar a nadie que ya hubiera competido.
--
-- Idempotente: comprueba si el índice ya existe antes de crearlo.
-- ---------------------------------------------------------------------


-- Paso 0 — diagnóstico. Si esta consulta devuelve filas, resuélvelas antes de
-- seguir: el paso 2 fallará con «Duplicate entry» y no habrá tocado nada.
--
-- Resolverlas quiere decir decidir, para cada grupo, cuál de esas filas es la
-- persona real. Es una decisión de negocio y la migración no la toma sola:
-- borrar automáticamente «la más nueva» podría tirar la inscripción que sí se
-- pagó y conservar la equivocada.
SELECT p.concurso_id,
       p.dni,
       COUNT(*)                        AS veces,
       GROUP_CONCAT(p.id ORDER BY p.id) AS participantes,
       GROUP_CONCAT(p.codigo_correlativo ORDER BY p.id) AS codigos
  FROM participantes p
 GROUP BY p.concurso_id, p.dni
HAVING COUNT(*) > 1;


-- Paso 1 — la regla. Va primero a propósito: si quedan duplicados sin resolver,
-- MySQL rechaza aquí con «Duplicate entry» y la tabla queda **exactamente como
-- estaba**. Se limpian los grupos que listó el paso 0 y se vuelve a ejecutar.
SET @existeUnico := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participantes'
       AND INDEX_NAME = 'uq_participante_documento'
);

SET @sql := IF(@existeUnico > 0,
    'DO 0',
    'ALTER TABLE participantes
       ADD CONSTRAINT uq_participante_documento UNIQUE (concurso_id, dni)'
);

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- Paso 2 — el índice no único deja de tener sentido: el UNIQUE recién creado
-- sirve para lo mismo al buscar, y mantener los dos es indexar dos veces la
-- misma pareja de columnas en cada alta. Solo se suelta si el UNIQUE existe, así
-- que un fallo en el paso 1 no deja la tabla sin ningún índice por ese par.
SET @hayUnico := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participantes'
       AND INDEX_NAME = 'uq_participante_documento'
);

SET @existeIndice := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participantes'
       AND INDEX_NAME = 'idx_participante_dni'
);

SET @sql := IF(@hayUnico > 0 AND @existeIndice > 0,
    'ALTER TABLE participantes DROP INDEX idx_participante_dni',
    'DO 0'
);

PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
