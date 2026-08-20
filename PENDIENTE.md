# Punto de retomada — 20 de agosto de 2026, tarde

Este archivo vive en el repositorio a propósito: se lee desde cualquier máquina.
Lo que hay en él es **estado**, no decisiones — las decisiones están en la §11 de
`PLAN_IMPLEMENTACION_COCIAP2026.md`.

**El sistema está desplegado y `php scripts/verificar_despliegue.php` sale con
cero fallos.** Falta lo que solo se puede hacer desde el navegador y una decisión
sobre el dominio.

---

## Dónde está todo

| | |
|---|---|
| Producción | `~/domains/palegoldenrod-gorilla-440933.hostingersite.com/public_html` |
| Base | `u761410128_compite` · MariaDB 11.8.8 · creada con `schema.sql` + `seed.sql` |
| PHP servidor | 8.3.30 (consola) |
| Último commit desplegado | mirar con `git log --oneline -1` dentro de esa carpeta |
| Guía | `DESPLIEGUE.md` |
| Banco de pruebas | `docs/protocolo-pruebas.html`, publicado como Artifact privado |

**La cuenta de hosting está compartida** con `sigacociap.net`, que es el otro
proyecto del propietario. No se toca ni se lee.

---

## Lo que falta, en orden

1. **Cambiar la contraseña del administrador.** Se creó con una contraseña que
   quedó visible en la consola. `/usuarios` → tu fila → *Editar / contraseña*.
2. **Crear las secretarias** en `/usuarios`.
3. **Dar de alta la I.E. anfitriona** en `/instituciones` y marcarla con papel
   **«Anfitriona»**. Sin esa marca, sus estudiantes se cobran como pública y
   compiten en la bolsa equivocada, **sin ningún aviso**.
4. **Correr `DEP-1` a `DEP-6`** del banco de pruebas, sobre el servidor.
5. **Entonces** abrir el registro.

---

## Una decisión abierta, ya medida

El subdominio provisional tiene 46 caracteres, y eso entra dentro del QR:

| Dominio | Módulos | Por módulo | |
|---|---|---|---|
| `palegoldenrod-gorilla-440933.hostingersite.com` | 41 × 41 | **0.415 mm** | por debajo del mínimo |
| Uno propio corto | 33 × 33 | 0.500 mm | correcto |

El sistema fija **0.50 mm por módulo** como densidad mínima y deja un aviso en el
log por cada carné que baje de ahí. El carné se genera igual y un QR apretado se
lee en muchos teléfonos, pero con menos margen justo donde no sobra: en la
puerta, con prisa y con la luz que haya.

**`DEP-4` decide**: imprimir un carné y escanear su QR con el móvil. Si engancha
rápido, se sigue así. Si cuesta, hace falta un dominio corto antes de imprimir en
serie.

Pase lo que pase, **la puerta no depende del QR**: `/control` busca por código
tecleado, y el correlativo va impreso en grande en el carné.

---

## LO INMEDIATO: D-50 — corregir el registro de participación

**Es lo siguiente que se implementa, antes de los reportes Excel.** El plan
detallado lo guardó el propietario aparte; aquí queda lo que no puede perderse.

**El agujero.** `Participante` solo tiene `crear()`: no existe ninguna forma de
corregir el DNI, los apellidos, los nombres ni la institución de un estudiante
mal registrado. Hoy solo se puede cambiar el grado, y por un camino que anula y
reinscribe. Salió al intentar arreglar un DNI mal tecleado.

**Cuatro decisiones ya tomadas por el propietario (20 ago):**

1. **Un solo formulario** con datos del estudiante + grado + procedencia + motivo
   obligatorio, en `/inscripciones/{id}/corregir`.
2. **Tabla `correcciones`** con valor anterior legible, motivo, firma y lote —
   `participantes` es hoy la única mutación del sistema sin firma, contra D-39.
3. **La corrección de grado deja de anular y reinscribir**: pasa a ser un
   `UPDATE` registrado. La inscripción conserva su id y su carné, y el listado
   deja de mostrar dos filas por corrección.
4. **Cambiar procedencia:** permitido si está pendiente; si está pagada, **solo
   si la tarifa nueva es igual a la actual**, comparando valores en tiempo de
   ejecución y no grupos escritos a mano (D-37 avisó de que la tarifa COCIAP
   puede cambiar). Con las tarifas de hoy: `publica ↔ organizadora` y
   `privada ↔ libre` pasan aun pagadas; cualquier cruce se bloquea.
   Convertir libre ↔ delegación entra, en ambos sentidos.
5. **Permisos:** datos y grado, ambos roles. **Procedencia, solo administrador**,
   rechazando el POST y no ignorándolo en silencio.

**Cinco preguntas que quedaron SIN responder** y que hay que resolver antes de
escribir código:

1. ¿Convertir libre ↔ delegación es también solo-administrador?
2. ¿Las 2 anuladas de correcciones previas que hay en la base se quedan como
   historia mixta?
3. ¿Hace falta una pantalla para **ver** el historial de correcciones, o basta
   con guardarlo? Cambia el tamaño del trabajo de forma notable.
4. ¿«Corregir» aparece también en filas anuladas? Hoy no, y entonces un DNI mal
   escrito en alguien anulado **no se puede arreglar** y viaja con él al
   reinscribirlo.
5. Al pasar de delegación a libre, ¿se reutiliza el buscador de apoderado por
   DNI de la pantalla de estudiante libre?

**Lo que arrastra:** `AnulacionController::corregir()` deja de tener sentido ahí
—la acción ya no anula— y pasa a un `CorreccionController`. El redirect vuelve a
`#ins-{$id}`, porque la inscripción conserva su id. Y hay que corregir el
comentario del listado que dice que cada corrección deja una anulada detrás.

---

## Después de D-50: los reportes Excel (Fase 5)

Analizado el 20 ago, **sin empezar**. Lo que no se puede perder de ese análisis:

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

---

## Deuda consciente, aplazada por el propietario

- **Sin respaldo automático.** El `mysqldump` está documentado en
  `DESPLIEGUE.md` y es manual. Es la única red sobre el dinero cobrado.
- **Sin reportes (Fase 5).** `Inscripcion::fondoDevoluciones()` existe en el
  modelo, pero no hay pantalla ni exportación a Excel. No bloquea el registro;
  sí bloqueará el descargo cuando dirección lo pida. Ya analizado — ver arriba.
- **Sin bitácora general.** Se firma quién registra, quién cobra y quién anula
  una inscripción, pero no quién crea o edita instituciones y apoderados.
- **Sin `/perfil`.** Las contraseñas solo las cambia el administrador desde
  `/usuarios`.
- **Un solo inquilino.** Dar de alta una segunda organización mezcla apoderados
  y colegios. Antes hay que resolver P-05 y P-07, en ese orden.
- **Sin tabla de migraciones aplicadas.** `verificar_despliegue.php` lo sustituye
  comprobando el esquema columna por columna, que resuelve el síntoma.

---

## Cómo comprobar que sigue todo en pie

```
php scripts/pruebas/todas.php          # 171 comprobaciones, base real, no dejan nada
php scripts/medir_responsive.php       # 6 pantallas × 8 anchos: desborde y alineación
php scripts/verificar_despliegue.php   # el servidor: config, esquema, datos, assets
```

**En el servidor los errores no se ven**: `shell_exec` está deshabilitado y
`display_errors` apagado. Para diagnosticar allí:
`php -d display_errors=1 -d error_reporting=E_ALL <script>`.

Y antes de cualquier commit que toque `src/scss` o `src/js`: **`npm run build`**.
`public/build` está rastreado por git, así que lo commiteado es lo que se
despliega.
