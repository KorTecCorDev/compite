# Punto de retomada — 21 de agosto de 2026, madrugada

Este archivo vive en el repositorio a propósito: se lee desde cualquier máquina.
Lo que hay en él es **estado**, no decisiones — las decisiones están en la §11 de
`PLAN_IMPLEMENTACION_COCIAP2026.md`.

**Mañana viernes 21 entra el lote grande de inscripciones. El sábado 22 es el
concurso, con inscripción el mismo día.**

---

## Dónde está todo

| | |
|---|---|
| Producción | `~/domains/palegoldenrod-gorilla-440933.hostingersite.com/public_html` |
| Base | `u761410128_compite` · MariaDB 11.8.8 |
| Base local | **copia de producción** desde el 20-ago por la noche |
| PHP servidor | 8.3.30 (consola) |
| Guía | `DESPLIEGUE.md` · el push a `main` es el despliegue |
| Banco de pruebas | `docs/protocolo-pruebas.html` — 135 pruebas manuales |

**La cuenta de hosting está compartida** con `sigacociap.net`, que es el otro
proyecto del propietario. No se toca ni se lee.

---

## Ya no son pendientes (verificado el 20-ago en los datos reales)

- ✅ **Contraseñas correctas**, confirmado por el propietario.
- ✅ **Las dos secretarias existen**: Tatiana Villar y Maritza Jara.
- ✅ **I.E. anfitriona de alta y enlazada**: `IE COCIAP` (id 1), con
  `organizaciones.institucion_id = 1`. Sin ese enlace sus estudiantes se
  cobrarían como pública y competirían en la bolsa equivocada, sin ningún aviso.
- ✅ **43 instituciones cargadas** (22 públicas, 21 privadas); 29 todavía sin
  ningún estudiante, esperando el lote.
- ✅ **D-50 implementado y probado** — ver abajo.

---

## Lo que falta, en orden

1. **Desplegar D-50** y correr `php scripts/verificar_despliegue.php` en el
   servidor. La tabla `correcciones` es nueva: **la migración hay que ejecutarla
   allá a mano**, `database/migraciones/2026-08-20-correcciones.sql`. Estar
   versionada no la ejecuta en ningún lado.
2. **Correr `COR-1` a `COR-8`** del banco de pruebas sobre el servidor, que es
   el bloque nuevo de D-50.
3. **Correr `DEP-1` a `DEP-6`**, incluido el respaldo.
4. **`DEP-4` decide el dominio.** El subdominio provisional tiene 46 caracteres
   y empuja el QR a **0.415 mm por módulo**, por debajo del mínimo de 0.50 que
   el sistema exige. Imprime un carné y escanéalo: si engancha rápido, se sigue
   así; si cuesta, hace falta un dominio corto **antes de imprimir en serie**. La
   puerta no depende del QR —`/control` busca por código tecleado—, pero el
   margen se pierde justo donde no sobra.
5. **Respaldo antes de abrir el registro.** Ya no protege datos de prueba: hay
   cobros reales dentro y el `mysqldump` es manual.

---

## Un caso real que hay que resolver a mano

**Los participantes 20 y 21 son el mismo estudiante.**

| id | Documento | Estado |
|---|---|---|
| 20 | `61880439` | anulada, libre |
| 21 | `61880438` | **pendiente de cobro**, delegación (I.E. 34) |

Mismo nombre completo, un dígito de diferencia. El 20 se anuló por institución
equivocada y, como entonces no se podía corregir, se volvió a registrar de cero;
en el reingreso el documento salió distinto. `uq_participante_documento` no lo
detectó porque un dígito cambiado lo convierte en otro documento.

**Con el DNI del chico delante, hay que decidir cuál es el bueno.** La 21 está
viva y pendiente, así que **ese es el documento que va a ir impreso en su
carné**.

**Ojo: si el bueno resulta ser el `…439`, D-50 no lo arregla solo.**
`Participante::porDocumento()` busca en `participantes` sin mirar el estado de
la inscripción, así que **el participante 20 sigue ocupando ese documento aunque
esté anulado**, y el sistema rechazará el cambio nombrándolo. Está bien que lo
haga —dos participantes no pueden compartir documento— pero significa que hay
que liberarlo primero:

- **Sin tocar la base:** «Reinscribir» la 20 → corregirle a ella el documento →
  volver a anularla → y entonces corregir la 21. Todo desde la pantalla y con
  firma en cada paso.
- **A mano:** un `UPDATE` sobre el participante 20, con respaldo antes. Más
  rápido, pero sin rastro en `correcciones`.
- **O dejarlo.** En la puerta se busca por código, no por documento, así que un
  dígito mal no deja a nadie fuera.

D-50 **no fusiona** registros: decidir cuál se queda es trabajo humano.

---

## D-50 — hecho el 20/21 de agosto

`/inscripciones/{id}/corregir` corrige **en su sitio**, sin anular ni
reinscribir, y firma qué cambió, quién y por qué en la tabla `correcciones`.

- **Datos del estudiante y grado:** ambos roles.
- **Procedencia** (delegación ↔ libre, institución, apoderado): **solo
  administrador**, y el POST se rechaza si llega de una secretaria en vez de
  ignorarse en silencio.
- **Pagada:** la procedencia solo cambia si la tarifa nueva **cuesta lo mismo**.
  Compara importes en ejecución, no nombres de modalidad, así que el día que una
  tarifa se mueva la regla se ajusta sola.
- **El historial se ve en la propia pantalla** de corrección, sin pantalla de
  auditoría aparte.
- **En anuladas no se corrige:** se usa «Reinscribir» y se corrige después sobre
  la fila viva, que trabaja sobre el mismo participante.
- **El carné no hay que regenerarlo** —el PDF se genera al vuelo—, pero el papel
  ya impreso queda viejo y el aviso de éxito lo dice.

Detalle a vigilar, sin urgencia: `IE COCIAP` figura con `tipo = 'privada'`. Hoy
es inocuo, porque como anfitriona resuelve a `organizadora` y cobra S/ 10.00.
Si alguna vez se desenlazara, pasaría a cobrar S/ 15.00.

---

## LO SIGUIENTE: los reportes Excel (Fase 5)

**Ya no es deuda aplazada: el propietario confirmó que el acta de los jurados
sale del sistema, así que es requisito del sábado.**

- **La bolsa de competencia NO es la modalidad.** D-37 fija tres bolsas por
  nivel+grado: `privada + libre` juntos, `publica`, `organizadora`. Agrupar por
  las cuatro modalidades separaría a privados de libres y daría **dos ganadores
  donde las bases dicen uno**.
- **Esa regla vive hoy solo en un `CASE` dentro de
  `scripts/pruebas/modalidad-organizadora.php`.** Antes de generar nada tiene
  que subir al dominio, o habrá dos copias que pueden divergir.
- Son **dos reportes distintos**: el acta para los jurados (solo confirmadas,
  **sin ningún dato de dinero**) y el administrativo para dirección (con montos
  y los filtros combinables del §8).
- Hay **bolsas con un solo participante**. El reporte tiene que hacerlo visible:
  descubrirlo en la premiación es mucho peor.
- `vendor/` no viaja con el autodeploy. **Verificar que PhpSpreadsheet esté
  instalado en el servidor** antes, no después: allí los errores no se ven.
- La **pantalla del fondo de devoluciones** sigue sin existir. El cálculo ya
  está en `Inscripcion::fondoDevoluciones()`; le faltan la vista y la ruta.

---

## Deuda consciente, aplazada por el propietario

- **Sin respaldo automático.** El `mysqldump` está documentado en
  `DESPLIEGUE.md` y es manual. Es la única red sobre el dinero cobrado.
- **Sin bitácora general.** Se firma quién registra, quién cobra, quién anula y
  —desde D-50— quién corrige. No, en cambio, quién crea o edita instituciones y
  apoderados.
- **Sin `/perfil`.** Las contraseñas solo las cambia el administrador desde
  `/usuarios`.
- **Un solo inquilino.** Dar de alta una segunda organización mezcla apoderados
  y colegios. Antes hay que resolver P-05 y P-07, en ese orden.
- **Sin tabla de migraciones aplicadas.** `verificar_despliegue.php` lo sustituye
  comprobando el esquema columna por columna.
- **Dos suites frágiles.** `firmas-y-usuarios` y `reinscribir` buscan «la
  primera inscripción pendiente». Hoy pasan porque quedan dos por cobrar;
  **volverán a fallar solas cuando se cobren**, sin que nadie toque el código.

---

## Cómo comprobar que sigue todo en pie

```
php scripts/pruebas/todas.php          # 16 suites · 217 comprobaciones, base real
php scripts/medir_responsive.php       # 7 pantallas × 8 anchos
php scripts/verificar_despliegue.php   # el servidor: config, esquema, datos, assets
```

**En el servidor los errores no se ven**: `shell_exec` está deshabilitado y
`display_errors` apagado. Para diagnosticar allí:
`php -d display_errors=1 -d error_reporting=E_ALL <script>`.

Y antes de cualquier commit que toque `src/scss` o `src/js`: **`npm run build`**.
`public/build` está rastreado por git, así que lo commiteado es lo que se
despliega.
