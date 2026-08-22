# Punto de retomada — 21 de agosto de 2026, madrugada

Este archivo vive en el repositorio a propósito: se lee desde cualquier máquina.
Lo que hay en él es **estado**, no decisiones — las decisiones están en la §11 de
`PLAN_IMPLEMENTACION_COCIAP2026.md`.

**Hoy viernes 21 entra el lote grande de inscripciones. Mañana sábado 22 es el
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
| Banco de pruebas | `docs/protocolo-pruebas.html` — 137 pruebas manuales |

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
- ✅ **D-53: el panel deja de contar por dentro cómo está hecho el sistema**
  (21-ago). Fuera la sección «Módulos» —era el avance del proyecto, «Fase 3 ·
  listo», delante de la secretaría— y la nota interna bajo las fechas. Ningún
  camino se pierde: la barra ya llevaba Instituciones y Apoderados. Se retiró
  también su SCSS y se recompiló; verificado regla a regla que el `app.css` solo
  pierde `.lista-modulos`. De paso se corrigió **«faltan 1 día»**, que salía hoy
  mismo en pantalla. **Desplegado el 21-ago.**
- ✅ **D-52: cada quien opera sus propios registros** (21-ago). «Corregir» y
  «Reinscribir» solo salen en las filas que uno registró; el administrador puede
  con todas, y lo que él registró **no** lo toca una secretaria. **Cobrar,
  descargar carnés, la hoja A4 de delegación y `/control` quedan EXENTOS** —esa
  exención es lo que sostiene el sábado: una delegación mixta paga con un solo
  Yape y la mesa de la puerta tiene que encontrar a cualquiera—. Cero
  migraciones: `inscripciones.usuario_id` ya existía. **Desplegado el 21-ago.**
  · Fuera de alcance a propósito: la propiedad de **apoderados**, que necesita un
  `ALTER TABLE` y se deja para después del concurso.
- ✅ **D-51: anular es exclusivo del administrador** (21-ago). La secretaria
  conserva todo lo demás —registrar, cobrar, corregir, reinscribir, carnés—;
  solo pierde la anulación, que es la única acción irreversible y la única que
  mueve dinero al fondo de devoluciones. **Desplegado el 21-ago.**
- ✅ **D-50 implementado, probado, desplegado y aprobado** por el propietario el
  21-ago. Las once pruebas del navegador pasaron en local, la migración se
  ejecutó en Hostinger y `verificar_despliegue.php` sale allá **sin un solo
  fallo**, con `tabla correcciones completa`, `entorno = produccion`,
  `depurar = false` y los assets minificados.

---

## Lo que falta, en orden

1. **Correr `DEP-1` a `DEP-6`**, incluido el respaldo.
2. **`DEP-4` decide el dominio.** El subdominio provisional tiene 46 caracteres
   y empuja el QR a **0.415 mm por módulo**, por debajo del mínimo de 0.50 que
   el sistema exige. Imprime un carné y escanéalo: si engancha rápido, se sigue
   así; si cuesta, hace falta un dominio corto **antes de imprimir en serie**. La
   puerta no depende del QR —`/control` busca por código tecleado—, pero el
   margen se pierde justo donde no sobra.
3. **Respaldo antes de abrir el registro.** Ya no protege datos de prueba: hay
   cobros reales dentro y el `mysqldump` es manual. **Descárgalo fuera del
   servidor**: un respaldo que vive en la misma máquina que la base no es un
   respaldo.

---

## Dos cosas medidas el 21-ago que conviene no perder

**1. Imprimir carnés es el punto de no retorno del dominio.**
`url_base` está vacío a propósito (D-43): cada carné lleva en su QR el dominio
por el que se generó. Eso hace el sistema portátil, pero **un QR en papel no se
corrige**. Los carnés impresos entrando por el subdominio provisional apuntarán
a él para siempre; si algún día se apaga, quedan muertos. Si va a haber dominio
propio, que exista **antes de la primera hoja de carnés**.

**2. El TLS del subdominio provisional falla por IPv6.**
Medido con 20 peticiones en 8 minutos: **50 % de error**. Al probar dirección
por dirección, la causa quedó clara:

| Dirección | Handshake TLS |
|---|---|
| `185.249.224.202` (IPv4) | 3/3 |
| `77.37.85.78` (IPv4) | 3/3 |
| `2a02:4780:71:…` (IPv6) | **0/3** |
| `2a02:4780:72:…` (IPv6) | **1/3** |

**No es Compite:** es la capa de red del CDN de Hostinger (`Server: hcdn`), por
debajo de la aplicación. El impacto real es menor que ese 50 %, porque los
navegadores hacen *Happy Eyeballs* y caen a IPv4 solos — por eso desde Chrome no
se nota. Pero el sábado los apoderados escanearán el QR **desde datos móviles**,
donde IPv6 es habitual en Perú. Dos salidas: reportarlo a Hostinger con estos
datos, y —la definitiva— publicar el dominio propio **solo con registros `A`**,
sin `AAAA`, con lo que el problema desaparece de raíz. Al probar `DEP-4`,
hacerlo con el móvil en datos móviles y no en wifi, que es el escenario real.

---

## Un caso real, ya resuelto (21-ago)

**Los participantes 20 y 21 son el mismo estudiante.**

| id | Documento | Estado |
|---|---|---|
| 20 | `61880439` | anulada, libre |
| 21 | `61880438` | **pendiente de cobro**, delegación (I.E. 34) |

Mismo nombre completo, un dígito de diferencia. El 20 se anuló por institución
equivocada y, como entonces no se podía corregir, se volvió a registrar de cero;
en el reingreso el documento salió distinto. `uq_participante_documento` no lo
detectó porque un dígito cambiado lo convierte en otro documento.

El bueno era el `…439`, el que estaba en el registro **anulado**. Y ahí apareció
el límite: `Participante::porDocumento()` busca en `participantes` **sin mirar el
estado de la inscripción**, así que el participante 20 seguía ocupando ese
documento aunque estuviera anulado, y la pantalla rechazaba el cambio
nombrándolo. Está bien que lo hiciera —dos participantes no pueden compartir
documento— pero significaba que **desde la interfaz no se podía**.

**Resuelto por consola el 21-ago intercambiando los dos documentos**, dentro de
una transacción y con respaldo previo: el 21 se quedó con el `…439` y el
registro fantasma con el `…438`, que es donde debe estar archivado el error. No
se inventó ningún número ni se borró ninguna fila. El cambio se firmó a mano en
`correcciones` para que no quedara sin rastro.

**La lección, por si vuelve a pasar:** un `UPDATE` directo salta la firma que
D-50 construyó. Si hay que repetirlo, el `INSERT` en `correcciones` va en la
misma sesión. Y ojo con la transacción: la primera vez no se aplicó nada porque
faltó el `COMMIT` — al cerrar el cliente, MariaDB revierte.

D-50 **no fusiona** registros: decidir cuál se queda sigue siendo trabajo humano.

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

## La bolsa de competencia ya está en el dominio (D-54, 21-ago)

`Concurso::bolsa()`, `etiquetaBolsa()` y `bolsas()`. **El acta ya no tiene que
reimplementar el agrupamiento**: era el requisito previo de la Fase 5 y está
cumplido. La regla vivía solo en un `CASE` dentro de un `printf` de
`modalidad-organizadora.php`, sin ninguna aserción encima; ahora tiene 12.

**El reparto real, ya con el lote dentro (114 vivas, 22 bolsas ocupadas):**

| | |
|---|---|
| Bolsas con **un solo participante** | **4** — primaria 1° COCIAP, primaria 3° Pública, secundaria 1° Pública, secundaria 2° Pública |
| La más grande | primaria 3° COCIAP, con **39** |
| Categorías sin las tres bolsas | varias: primaria 4° y 6° solo tienen Privada + Libre; secundaria 4° solo Pública |

Esos cuatro compiten solos en su bolsa y **ganan por defecto**. No es un fallo
del sistema —es cómo quedaron las inscripciones—, pero el acta tiene que
enseñarlo antes de la premiación, no durante. Por eso `bolsas()` devuelve
siempre las tres.

---

## Las actas YA ESTÁN (D-56 y D-57) — falta probarlas en papel

`GET /reportes/actas.zip`, **solo administrador**, con el botón «Descargar actas
(ZIP)» en el listado de inscripciones. Baja **un libro por bolsa de
competencia** y once hojas dentro de cada uno, una por grado:

```
actas-cociap-2026-08-22.zip
  ├─ acta-privada-libre.xlsx   ← privada y libre JUNTOS (D-37)
  ├─ acta-publica.xlsx
  └─ acta-cociap.xlsx
```

Por bolsa y **no por modalidad**: cuatro libros habrían separado a privados de
libres y habrían dado dos ganadores donde las bases dicen uno. Columnas
**Correctas · Incorrectas · Puntaje · H/E** en blanco, firma del **Comité de
Inscripción**, solo confirmadas. Se genera al vuelo, así que después de cada
tanda de cobros basta con volver a descargarlo.

**Rendimiento medido, no supuesto:** 1000 participantes → **0,96 s y 32 MB**;
2000 → 1,81 s. No hay nada que optimizar aquí. Lo que peor escala del sistema
son los carnés en PDF (~0,4 s cada diez), ya mitigado generando por delegación.

**Lo que falta, y solo puedes hacerlo tú:**

1. **Abre el ZIP e imprime una hoja.** Las pruebas reabren los `.xlsx` y
   comprueban que nadie cae en el libro equivocado, pero **no ven la página**:
   si una columna se parte o las casillas quedan estrechas para escribir a mano,
   eso se ve en el papel y en ningún otro sitio.
2. **Confirma PhpSpreadsheet en el servidor antes de publicar.** `vendor/` no
   viaja con el autodeploy. El comando está en D-56, §11. Si sale `false`, hace
   falta `composer install --no-dev` allá.

---

## LO SIGUIENTE: el reporte administrativo (Fase 5)

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

## Dashboard de estadísticas — analizado y APLAZADO tras el acta (21-ago)

Decisión del propietario: **el acta va primero**; esto se retoma cuando la Fase 5
esté hecha y probada. El análisis queda aquí para no repetirlo.

**Alcance acordado — tramo A, y solo eso:** una pantalla `/estadisticas`
**solo administrador** (`Auth::exigirAdministrador()`, que ya existe) con:

1. Reparto **por bolsa de competencia**, marcando las bolsas de un solo
   participante. Reutiliza `Concurso::bolsas()` (D-54), ya desplegado.
2. **Por delegación**, con lo que falta por cobrar de cada una — es lo que
   permite saber a qué colegio llamar. Hoy hay 17 delegaciones con inscritos.
3. El resumen de cobro, que ya calcula `Inscripcion::resumen()`.

**Tramo B, descartado salvo petición expresa:** auto-refresh, ritmo por hora y
desglose por modalidad. La modalidad aporta poco sobre la bolsa —hoy
organizadora 48, privada 40, pública 18, libre 7— y la bolsa es la que decide
premios. **Nada de exportar desde el dashboard**: ese es el trabajo del acta y no
puede tener dos implementaciones.

**Tres decisiones técnicas ya tomadas, con su motivo:**

- **Sin librería de gráficos.** Todo el JS del sitio suma ~10 KB y no hay una
  sola dependencia de runtime; Chart.js son ~200 KB detrás de un CDN que cachea
  siete días. Con 3 bolsas y 11 categorías, barras de CSS cuentan lo mismo y
  pesan cero.
- **«En vivo» = `<meta refresh>`, no polling con JSON.** Las agregaciones miden
  **0,56–0,84 ms** con el lote dentro: no hay nada que optimizar, y un endpoint
  JSON con actualización parcial del DOM es diez veces el trabajo y diez veces la
  superficie de fallo para tres usuarios.
- **Pantalla aparte, no ampliar `/panel`.** D-53 acaba de decidir que el panel
  sea discreto, y el panel lo ven las secretarias.

**Lo que este dashboard NO podrá mostrar, y conviene no olvidarlo:**
**cuánta gente ha llegado.** `/control` solo busca y muestra; no marca ingreso, y
`inscripciones` no tiene ninguna columna de asistencia. Un tablero de puerta en
vivo exigiría añadir ese registro, y el propietario lo descartó (21-ago): este
año la puerta funciona con carné impreso y búsqueda por código o apellido.

**Comprobado y deliberado, para que no vuelva a saltar en cada revisión:**
`/panel` usa `exigirSesion()` y enseña «Recaudado» a las dos secretarias. **Es
intencional** —ellas cobran, así que ven el total—, confirmado por el propietario
el 21-ago. No es un fallo de permisos.

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
- ~~**Dos suites frágiles.**~~ → **Ocurrió y está resuelto (D-55, 21-ago).** Al
  cobrarse el lote no quedó ninguna pendiente y las dos reventaron solas, tal
  como estaba previsto aquí. Ahora **crean** su caso en vez de buscarlo. De paso
  salió un agujero peor: **cuatro pruebas `vistas-*` no podían fallar nunca**
  —imprimían `FALLA` y salían con 0—, así que `todas.php` las daba por buenas.
  El corredor ya no se fía solo del código de salida.
  · Queda una de la misma familia, anotada a propósito: el caso 2 de
  `reinscribir` toma una confirmada real con `LIMIT 1`. Hoy hay 113, así que no
  puede quedarse sin material.

---

## Cómo comprobar que sigue todo en pie

```
php scripts/pruebas/todas.php          # 19 suites · 333 comprobaciones, base real
php scripts/medir_responsive.php       # 7 pantallas × 8 anchos
php scripts/verificar_despliegue.php   # el servidor: config, esquema, datos, assets
```

**En el servidor los errores no se ven**: `shell_exec` está deshabilitado y
`display_errors` apagado. Para diagnosticar allí:
`php -d display_errors=1 -d error_reporting=E_ALL <script>`.

Y antes de cualquier commit que toque `src/scss` o `src/js`: **`npm run build`**.
`public/build` está rastreado por git, así que lo commiteado es lo que se
despliega.
