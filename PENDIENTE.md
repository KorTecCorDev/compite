# Punto de retomada — 22 de agosto de 2026, concurso terminado

Este archivo vive en el repositorio a propósito: se lee desde cualquier máquina.
Lo que hay en él es **estado**, no decisiones — las decisiones están en la §11 de
`PLAN_IMPLEMENTACION_COCIAP2026.md`.

**EL CONCURSO YA TERMINÓ.** Cifras finales, medidas sobre la base:

| | |
|---|---:|
| Inscripciones registradas | 809 |
| Confirmadas y cobradas | 804 · **S/ 9 245,00** |
| Anuladas | 5 |
| Pendientes de pago | **0** |
| Personas en el padrón | 808 |
| Ingreso legítimo tras ajustes | **S/ 9 235,00** |

**A partir de aquí no se cambia ningún dato** (decisión del propietario,
22-ago). Lo que quede mal se declara en la rendición, no se corrige en la base.

## Lo desplegado hoy, y lo que falta comprobar en vivo

Tres despliegues el 22: la **bolsa de competencia en el dominio** (D-54), la
**suite que deja de poder mentir** (D-55), las **actas de los jurados** (D-56 y
D-57) y los **nombres en mayúsculas** (D-58).

> **PENDIENTE DE COMPROBAR EN PRODUCCIÓN:** pulsar «Descargar actas (ZIP)» con
> sesión de administrador. Ese clic es lo único que confirma que
> **PhpSpreadsheet está instalado en el servidor**: `vendor/` no viaja con el
> autodeploy. Si sale página en blanco o error 500, allí los errores no se ven —
> diagnosticar con el comando de D-56 §11 y arreglar con
> `composer install --no-dev` en la carpeta del sitio. **No hace falta volver a
> desplegar.**

Y sigue sin hacerse lo de siempre: **nadie ha impreso todavía una hoja del acta**.
Las pruebas leen el `.xlsx` por dentro, pero no ven la página.

**DESPLEGADO el 23-ago** (commit `3516387`), sin ninguna migración: los
**reportes contables** (D-59), la **firma que sobrevive a la reinscripción**
(D-60), la **grilla de cobros** (D-61), la **rendición de cuentas** (D-62), la
**hora de Ancash en todo el sistema** (D-63) y las **operaciones con sus
participantes dentro** (D-64). Detalle más abajo.

Verificado en vivo tras el push: el `app.css` publicado es **idéntico byte a
byte** al commiteado y trae las reglas nuevas, las cinco rutas de `/reportes/*`
responden 302 al login —existen y están protegidas—, y la vista pública del
carné responde 200 **con una consulta real a la base**, que es lo único que
prueba que el `SET SESSION time_zone` nuevo no rompió nada. El autodeploy tardó
unos minutos, como de costumbre.

✅ **Y el propietario confirmó el 23-ago que la hora sale correcta en
producción.** Era la última pieza que dependía de una inferencia.

---

## Dónde está todo

| | |
|---|---|
| Producción | `~/domains/palegoldenrod-gorilla-440933.hostingersite.com/public_html` |
| Base | `u761410128_compite` · MariaDB 11.8.8 |
| Base local | **copia de producción** con el concurso ya cerrado (22-ago) |
| PHP servidor | 8.3.30 (consola) |
| Guía | `DESPLIEGUE.md` · el push a `main` es el despliegue |
| Banco de pruebas | `docs/protocolo-pruebas.html` — 137 pruebas manuales |

**Antes de cualquier commit que toque `src/`:** comprobar que **no hay ningún
proceso `node` corriendo**. El watcher de gulp reapareció **cinco veces** entre
el 20 y el 22 y deja los assets sin minificar; commitearlos los publica y el CDN
los sirve una semana. `git diff HEAD --stat -- public/build` vacío es la prueba.
· La quinta fue el 22 a mediodía, en plena sesión de trabajo: `git status`
estaba limpio al empezar y ocho archivos de `public/build` aparecieron
desminificados (+1989 líneas) sin que nadie tocara `src/`.

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

## Un caso real que se creía resuelto (21-ago) — NO lo está

**Los participantes 20 y 21 son el mismo estudiante.**

| id | Documento | Estado |
|---|---|---|
| 20 | `61880439` | anulada, libre |
| 21 | `61880438` | **confirmada y cobrada**, delegación |

> **Comprobado el 22-ago sobre la base: el intercambio de documentos NO está
> aplicado.** El `…439` sigue en el registro anulado y quien compitió lleva el
> `…438`. Ver el aviso de la rendición (D-62), más arriba.

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

## LOS REPORTES CONTABLES YA ESTÁN (D-59, D-60 y D-61) — sin desplegar

Cinco pantallas imprimibles, **cero migraciones**, 134 comprobaciones propias:

| Ruta | Quién | Qué |
|---|---|---|
| `/reportes/rendicion` | administrador | **La rendición de cuentas**: el documento de cierre, con conciliación, anexos y padrón nominal. |
| `/reportes/caja` | los dos roles | Arqueo por cobrador y medio, más una **ficha por operación con los participantes que la componen**. La secretaria ve **solo lo suyo**: es su cierre. |
| `/reportes/cobros` | administrador | **La grilla**: TODAS las inscripciones con estado, quién confirmó, medio, código de Yape y hora — ordenadas por fecha de confirmación. Siete filtros. |
| `/reportes/saldos` | administrador | Las cinco líneas del saldo, con el cuadre comprobado en ejecución. |
| `/reportes/devoluciones` | administrador | El fondo, que llevaba desde la Fase 4 calculado y **sin pantalla**. |

La barra lleva un solo enlace nuevo, «Caja»; las otras dos se alcanzan desde
dentro. Se imprimen con el botón o con Ctrl+P: hay un bloque `@media print` que
apaga barra, pie y botones y añade el pie de firmas «Entregué / Recibí».

**Lo que hay que saber antes de usarlos para entregar dinero:**

- **El mismo pago se cuenta una sola vez**, aunque esté escrito en dos filas por
  una reinscripción. La regla vive en una sola constante,
  `Inscripcion::DESDE_COBROS_VIGENTES`, y por eso el arqueo y el saldo cuadran.
- **Apareció una línea que no existía en ninguna pantalla:** «cobrado sin
  reasignar» — anuladas para reinscribir que aún no se reinscribieron. Ese
  dinero está en el cajón y no salía ni en recaudado ni en el fondo.
- **Las devoluciones efectuadas siguen sin registrarse.** La línea sale en cero
  y rotulada, para que el cuadre no mienta por omisión.
- **D-60:** al reinscribir, la firma de quien cobró ya no se pierde. Antes la
  fila nueva nacía confirmada y **sin dueño**.
- **La grilla de cobros NO suma dinero, a propósito** (D-61). Enseña filas
  crudas, así que sumarlas cobraría dos veces al mismo estudiante; las filas
  cuyo pago ya está contado en otra salen marcadas **«ya contado»** y los
  totales se piden en `/reportes/saldos`. Es la misma regla, en una sola copia
  (`Inscripcion::FILA_DE_PAGO_VIGENTE`).
- **La grilla es de auditoría, no de trabajo.** `/inscripciones` sigue en orden
  de nómina con sus acciones; esta va en orden de reloj y no opera nada.

---

## LA RENDICIÓN DE CUENTAS (D-62) — el documento de cierre

`/reportes/rendicion`, solo administrador. **El concurso ya terminó y no se
cambia ningún dato**: los sobre registros se declaran, no se corrigen.

| | |
|---|---:|
| Inscripciones registradas | 809 |
| (−) anuladas | 5 |
| Confirmadas y cobradas | 804 · **S/ 9 245,00** |
| (−) cobro duplicado a una misma persona | 1 · **S/ 10,00** |
| **Competidores efectivos e ingreso legítimo** | **803 · S/ 9 235,00** |

**Los tres sobre registros, encontrados por tres barridos distintos:**

1. **RAMÍREZ OSORIO, BRIYIT ELISA** — inscrita **dos veces** (participantes 195
   y 196), mismo colegio (IE 86016 Pedro Pablo Atusparia), mismo grado
   (primaria 4°), **cobrada dos veces con catorce segundos de diferencia** por
   Tatiana Villar. **S/ 10,00 por devolver** y un competidor fantasma en su
   bolsa. Es el único caso con dinero de más.
2. **DEPAZ YAURI, MAURICIO JAVIER** — su pago de S/ 15,00 está escrito en dos
   filas (la anulada 16 y la reinscrita 23). El dinero entró una vez; ya está
   contado una vez.
3. **LEANDRO CHAMORRO, ANDRÉS HERBERG** — participantes 20 y 21, mismo nombre,
   distinta procedencia. Sin impacto monetario. **Pero ojo:** ver abajo.

Los otros cuatro pares con apellidos+colegio+grado iguales son **hermanos**
(nombres de pila distintos), comprobado uno a uno.

> ⚠️ **El documento del caso 3 está en el registro equivocado.** Este archivo
> decía que el 21-ago se intercambiaron por consola los documentos dejando el
> bueno (`…439`) en el registro vivo. **Los datos dicen lo contrario:** el
> `…439` está en el participante **20, que es el anulado**, y quien compitió
> lleva el `…438`. O se revirtió —aquí mismo está anotado que la primera vez
> faltó el `COMMIT`— o nunca se aplicó. **No se tocó nada.** Hay que decidirlo
> antes de emitir cualquier constancia a nombre de ese estudiante.

**Falta, y solo puedes hacerlo tú:** imprimir la rendición y una hoja del arqueo
y mirarlas en papel.

---

## ⚠️ La hora: resuelto en todo el sistema (D-62 y D-63)

> **Corrección de lo que este archivo decía antes.** Aquí llegó a estar escrito
> que la base local tenía filas generadas y que sus cifras de dinero no eran
> reales. **Era falso.** Los «pagos en el futuro» eran pagos con hora UTC, y el
> desfase resultó ser **exactamente 18 000 segundos en 803 de 805 filas** — una
> zona horaria, no una falsificación. **Los datos son reales y completos**, y el
> padrón de 808 es el del concurso, no el de las 113 que este archivo registraba
> antes de la jornada del sábado.

`fecha_pago` es `DATETIME` y guarda la hora del servidor (UTC en Hostinger);
`created_at` y `updated_at` son `TIMESTAMP` y sí se convierten al leer. De ahí
las cinco horas.

**Lo que cuesta en la práctica:** 191 cobros, **S/ 1 965,00**, están archivados
con fecha del sábado y se cobraron el viernes por la noche. Corregido, el cierre
real es 20-ago S/ 215,00 · 21-ago S/ 5 215,00 · 22-ago S/ 3 815,00.

**Ya está resuelto en TODO el sistema** (D-63), sin tocar un dato:

- La sesión de base de datos habla **UTC** (`SET SESSION time_zone = '+00:00'`),
  así que `DATETIME` y `TIMESTAMP` —que MySQL entregaba de forma distinta— salen
  por fin en la misma zona, **y la hora de desarrollo es la de producción**.
  Antes `created_at` se veía bien en local y 5 h adelantado en el servidor.
- `Core\Fecha` convierte al mostrar. **`mostrar()` para instantes, `dia()` para
  días de calendario**: la fecha del evento NO se convierte, o el carné y el
  acta dirían que el concurso fue el 21 a las 19:00.
- `Fecha::ahora()` y `Fecha::hoy()` no dependen del `php.ini`, que en consola y
  en el servidor puede venir en UTC.
- Hay una prueba que **recorre el código y falla si alguna fecha se pinta fuera
  de `Core\Fecha`**.

✅ **Comprobado en producción por el propietario el 23-ago: la hora sale
correcta.** Con eso `app.zona_datos = 'UTC'` deja de ser una deducción y pasa a
estar validado contra el servidor real. Si algún día se migra a un hosting cuyo
MySQL corra en hora local, esa clave hay que revisarla: describe la zona **del
volcado**, no la del servidor que lo lee.

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
php scripts/pruebas/todas.php          # 20 suites · 467 comprobaciones, base real
php scripts/medir_responsive.php       # 7 pantallas × 8 anchos
php scripts/verificar_despliegue.php   # el servidor: config, esquema, datos, assets
```

**En el servidor los errores no se ven**: `shell_exec` está deshabilitado y
`display_errors` apagado. Para diagnosticar allí:
`php -d display_errors=1 -d error_reporting=E_ALL <script>`.

Y antes de cualquier commit que toque `src/scss` o `src/js`: **`npm run build`**.
`public/build` está rastreado por git, así que lo commiteado es lo que se
despliega.
