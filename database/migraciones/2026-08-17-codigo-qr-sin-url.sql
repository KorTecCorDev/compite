-- ---------------------------------------------------------------------
-- D-21 — `carnes.codigo_qr` deja de guardar una URL absoluta
-- ---------------------------------------------------------------------
-- La columna venía guardando la URL completa de la vista pública
-- (`http://localhost/compite/carne/COCIAP2026-0019-KZZQMX`). Eso ata la base
-- al entorno donde se generó el carné: al restaurarla en Hostinger, todas las
-- filas existentes seguirían apuntando a localhost.
--
-- Pasa a guardar solo el código correlativo. La URL se arma cuando se necesita
-- con App\Servicios\GeneradorCarne::urlPublica(), que lee `app.url_base`.
--
-- Idempotente: solo toca las filas que todavía tienen forma de URL, así que
-- se puede ejecutar más de una vez sin efecto.
-- ---------------------------------------------------------------------

UPDATE carnes c
  JOIN inscripciones i ON i.id = c.inscripcion_id
  JOIN participantes p ON p.id = i.participante_id
   SET c.codigo_qr = p.codigo_correlativo
 WHERE c.codigo_qr LIKE 'http%';
