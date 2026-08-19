-- =====================================================================
-- COCIAP 2026 — Datos iniciales (Fase 1 del plan, sección 8)
--
-- Carga: la Organización, el Concurso, las 11 Categorías y las 3 Tarifas.
-- NO crea usuarios: las contraseñas deben pasar por password_hash() de PHP,
-- así que el primer administrador se crea con:
--
--     C:\xampp\php\php.exe scripts/crear_usuario.php
--
-- Es idempotente: se puede volver a ejecutar sin duplicar filas.
-- =====================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------
-- Organización (tenant único en este MVP)
--
-- `institucion_id` queda en NULL a propósito (D-37): la I.E. anfitriona no se
-- puede sembrar desde aquí porque necesita un docente delegado con DNI, que es
-- una fila de `apoderados`, y esos datos los captura la secretaria. Una vez
-- creada en /instituciones, se enlaza una sola vez:
--
--     UPDATE organizaciones SET institucion_id = <id de la I.E.> WHERE id = 1;
--
-- Mientras siga en NULL, los estudiantes del colegio anfitrión se cobrarían
-- como cualquier I.E. pública y no saldrían en su propia bolsa.
-- ---------------------------------------------------------------------
INSERT INTO organizaciones (id, nombre)
VALUES (1, 'I.E. Víctor Valenzuela Guardia — UNASAM')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);


-- ---------------------------------------------------------------------
-- Concurso. Fechas confirmadas en la sección 1 del plan.
-- ---------------------------------------------------------------------
INSERT INTO concursos (
    id, organizacion_id, nombre, codigo,
    fecha_evento, fecha_inicio_inscripcion, fecha_fin_inscripcion, sede
) VALUES (
    1, 1, 'IV Concurso Regional de Conocimientos COCIAP 2026', 'COCIAP2026',
    '2026-08-22', '2026-07-20', '2026-08-21',
    'Ciudad Universitaria UNASAM — Shancayán'
)
ON DUPLICATE KEY UPDATE
    nombre                   = VALUES(nombre),
    codigo                   = VALUES(codigo),
    fecha_evento             = VALUES(fecha_evento),
    fecha_inicio_inscripcion = VALUES(fecha_inicio_inscripcion),
    fecha_fin_inscripcion    = VALUES(fecha_fin_inscripcion),
    sede                     = VALUES(sede);


-- ---------------------------------------------------------------------
-- Categorías: 11 fijas. Primaria 1°-6°, Secundaria 1°-5°.
-- ---------------------------------------------------------------------
INSERT INTO categorias (concurso_id, nivel, grado) VALUES
    (1, 'primaria',   1),
    (1, 'primaria',   2),
    (1, 'primaria',   3),
    (1, 'primaria',   4),
    (1, 'primaria',   5),
    (1, 'primaria',   6),
    (1, 'secundaria', 1),
    (1, 'secundaria', 2),
    (1, 'secundaria', 3),
    (1, 'secundaria', 4),
    (1, 'secundaria', 5)
ON DUPLICATE KEY UPDATE grado = VALUES(grado);


-- ---------------------------------------------------------------------
-- Tarifas: no varían por categoría (sección 3 del plan).
--   I.E. pública      S/ 10
--   I.E. privada      S/ 15
--   Estudiante libre  S/ 15
--   I.E. organizadora S/ 10   (D-37)
--
-- 'organizadora' vale hoy lo mismo que 'publica' y aun así es una fila aparte:
-- el propietario la quiere independiente para poder moverla sin arrastrar a los
-- demás colegios públicos.
-- ---------------------------------------------------------------------
INSERT INTO tarifas (concurso_id, tipo_origen, monto) VALUES
    (1, 'publica',      10.00),
    (1, 'privada',      15.00),
    (1, 'libre',        15.00),
    (1, 'organizadora', 10.00)
ON DUPLICATE KEY UPDATE monto = VALUES(monto);
