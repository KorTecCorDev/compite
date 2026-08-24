# Plan de Implementación — Sistema de Inscripciones COCIAP 2026

**Documento fuente de verdad para Claude Code.** Este plan resume el análisis de dominio ya cerrado con el propietario del proyecto (Karlitos). Contiene reglas de negocio confirmadas explícitamente y decisiones técnicas propuestas. Las reglas de negocio (marcadas `[CONFIRMADO POR PROPIETARIO]`) no deben cambiarse sin consultar. Las decisiones técnicas (marcadas `[DECISIÓN TÉCNICA — REVISABLE]`) pueden ajustarse si aparece una razón técnica válida, pero deben comunicarse antes de aplicarse.

---

## 0. Protocolo de trabajo (aplica también a Claude Code)

- Prohibido asumir reglas de negocio, flujos, validaciones, permisos, estados o comportamiento esperado que no estén en este documento.
- Ante cualquier ambigüedad no cubierta aquí: **detener y preguntar al propietario, nunca suponer.**
- Toda decisión debe ser trazable: si se toma una decisión técnica nueva durante la implementación, debe documentarse aquí mismo (sección 11) con su justificación.
- Reportar activamente cualquier inconsistencia encontrada entre este documento y la realidad del código o el hosting.

---

## 1. Objetivo del MVP

Sistema interno en PHP para que la secretaría de la I.E. "Víctor Valenzuela Guardia" (UNASAM) gestione las inscripciones del **IV Concurso Regional de Conocimientos COCIAP 2026**, desde el registro hasta la emisión del carné de inscripción, de forma que las inscripciones queden **debidamente organizadas y listas para su descargo/reporte cuando dirección lo solicite**. `[CONFIRMADO POR PROPIETARIO]`

- **Evento:** sábado 22 de agosto de 2026, Ciudad Universitaria UNASAM – Shancayan.
- **Periodo de inscripción:** 20 de julio – 21 de agosto de 2026.
- **Fuera de alcance del MVP:** validación de asistencia el día del concurso, módulo de calificación (mencionado como fase futura, ver sección 9), onboarding self-service de nuevas organizaciones, panel de administración multi-tenant completo, pasarela de pago en línea (el pago se confirma manualmente por la secretaria).

---

## 2. Arquitectura y stack técnico `[DECISIÓN TÉCNICA — REVISABLE]`

- **Backend:** PHP estructurado (sin framework por ahora). Laravel queda planificado como migración posterior al concurso — **no** usar Laravel en este MVP.
- **Patrón sugerido:** MVC ligero hecho a mano (Router simple → Controladores → Modelos con PDO → Vistas PHP), para que la migración a Laravel después sea conceptualmente natural.
- **Base de datos:** MySQL/MariaDB (estándar en Hostinger), acceso vía PDO con prepared statements (obligatorio, por seguridad — nunca concatenar SQL con datos de usuario).
- **Frontend:** HTML + SASS (compilado a CSS) + JS, según preferencia ya declarada del propietario.
- **Gestión de dependencias:** Composer, instalado y ejecutado vía SSH.
- **Hosting:** Hostinger, plan compartido Premium/Business (cPanel), con acceso SSH confirmado.

### Librerías Composer propuestas
| Necesidad | Librería sugerida | Motivo |
|---|---|---|
| Generar Excel de estudiantes por grado | `phpoffice/phpspreadsheet` | Estándar de facto en PHP, sin dependencias de binarios externos |
| Generar PDF del carné | `dompdf/dompdf` | Puro PHP, no requiere binarios del sistema (más compatible con hosting compartido que wkhtmltopdf) |
| Generar código QR | `endroid/qr-code` | Puro PHP (usa GD, que normalmente ya está habilitado en Hostinger) |
| Autenticación/sesiones | Nativo de PHP (`password_hash`, `$_SESSION`) | No se requiere librería externa para el alcance actual |

**Paso obligatorio antes de instalar nada:** verificar por SSH la versión de PHP activa en consola (`php -v`) y las extensiones habilitadas (`php -m` — confirmar `pdo_mysql`, `gd`, `mbstring`, `zip`), porque puede diferir de la versión configurada para el sitio web en cPanel. Si hay diferencia, hay que igualar la versión de PHP de la consola a la del sitio (Hostinger permite seleccionar la versión de PHP por dominio en cPanel → "PHP Configuration").

---

## 3. Modelo de dominio confirmado

- **Organización** (tenant): entidad que organiza un concurso. Preparada desde el diseño para futuro multi-tenant/SaaS, pero en este MVP solo existe una: UNASAM/I.E. Víctor Valenzuela Guardia. `[CONFIRMADO POR PROPIETARIO]`
- **Concurso**: evento concreto organizado por una Organización (COCIAP 2026 es la primera instancia).
- **Categoría**: nivel + grado dentro de un Concurso (Primaria 1°–6°, Secundaria 1°–5° = 11 categorías fijas para COCIAP).
- **Tarifa**: costo de inscripción por Concurso y tipo de origen (I.E. pública S/10, I.E. privada S/15, estudiante libre S/15, **I.E. organizadora S/10 — D-37**). No varía por categoría.
- **Institución Educativa**: catálogo **global y compartido** entre todas las Organizaciones del sistema (no aislado por tenant), para no duplicar el mismo colegio si participa en concursos de distintas organizaciones. `[CONFIRMADO POR PROPIETARIO]`
- **Participante**: puede ser tipo `delegacion` (vinculado a una Institución Educativa, dentro de un lote institucional) o tipo `libre` (estudiante independiente, vinculado a un Apoderado).
- **Apoderado**: entidad reutilizable — puede vincularse a varios estudiantes libres (ej. hermanos). `[CONFIRMADO POR PROPIETARIO]`
- **Docente Delegado y Director**: identifican a quien envía la delegación de una I.E. Se guardan como **datos persistentes de la Institución Educativa** (no se recapturan en cada inscripción; si cambian, se actualizan sobre el mismo registro). DNI de ambos es opcional. `[CONFIRMADO POR PROPIETARIO]`
- **Inscripción**: registro individual **por participante** (no por lote), aunque el alta se haga en bloque para una delegación. Esto es así porque el futuro módulo de calificación necesita atribuir resultados a la institución de origen para premiar a la mejor institución. `[CONFIRMADO POR PROPIETARIO]`
- **Usuario**: con rol `secretaria` o `administrador`. Login individual por persona.
- **Carné**: PDF con código QR/barra que enlaza a una vista digital del carné del participante. Se genera y se descarga en el momento en que se comprueba el pago.
- **Fondo de devoluciones**: no es una entidad transaccional, es un **reporte/listado** de inscripciones anuladas definitivamente (sin reinscripción) con su monto pendiente. No lleva estado de seguimiento propio (no se marca cuándo se devuelve). `[CONFIRMADO POR PROPIETARIO]`

### Reglas de negocio confirmadas
- Único canal de captura de datos: la secretaria. No existe interfaz pública de autoservicio — el Google Form externo se mantiene como canal virtual, pero la secretaria es quien valida y registra en el sistema lo recibido ahí. `[CONFIRMADO POR PROPIETARIO]`
- El pago se considera cobrado solo cuando la secretaria confirma la inscripción en el sistema.
- Validar una inscripción hecha de forma virtual exige datos completos y correctos + pago confirmado.
- El identificador único de participante es un **código correlativo generado por el sistema** (no el DNI, aunque el DNI también se captura por ser obligatorio en la ficha oficial).
- No hay cupo máximo por categoría.
- Si un participante necesita cambiar de categoría, **se anula la inscripción y se vuelve a inscribir** — no se edita la categoría directamente sobre una inscripción existente.
- Una inscripción puede anularse/rechazarse. Si la anulación es para reinscribir de inmediato, la secretaria lo hace directamente. Si es una anulación definitiva (sin reinscripción) y ya había pago confirmado, ese monto se marca para que aparezca en el reporte de "fondo de devoluciones".
- El administrador puede hacer todo lo que hace la secretaria, más las funciones administrativas del sistema (gestión de Concurso, Categorías, Tarifas, Instituciones Educativas, Usuarios).
- El sistema **no migra** inscripciones previas en papel o Google Form — solo controla inscripciones desde que esté en operación.
- No hay bloqueo automático por fecha: se pueden registrar inscripciones incluso el mismo día del evento (22 de agosto de 2026). El cierre del periodo de inscripción es una decisión operativa de la secretaria/administrador, no una regla que el sistema deba imponer. `[CONFIRMADO POR PROPIETARIO]`
- El pago se identifica por **medio de pago**: Yape, transferencia (BCP) o efectivo. Si el medio es Yape, se captura adicionalmente el **código de seguridad de 3 dígitos** de la transacción. Si es transferencia o efectivo, solo se selecciona el tipo de medio. `[CONFIRMADO POR PROPIETARIO]`
- El código QR del carné codifica un enlace con el código correlativo del participante (ej. `https://dominio/carne/{codigo}`), y la vista digital del carné a la que apunta es de **acceso abierto**: cualquiera con el enlace puede verla, sin control de acceso adicional. `[CONFIRMADO POR PROPIETARIO]`

---

## 4. Campos obligatorios de la ficha `[CONFIRMADO POR PROPIETARIO]`

**Institución Educativa** (catálogo global): nombre de la I.E., distrito, provincia, departamento, tipo (pública/privada), dirección.

**Docente Delegado / encargado de la delegación** (vive en `apoderados` desde D-28, no embebido en la I.E.): apellido paterno, apellido materno, nombres, celular, correo electrónico (opcional), **DNI (obligatorio — D-28)**. Es el apoderado de todos los participantes que inscribe su colegio, y sin documento no hay forma de reconocerlo para reutilizarlo en vez de duplicarlo.

**Director de la I.E.** (persistente en la I.E.): apellido paterno, apellido materno, nombres, celular, correo electrónico, DNI (opcional).

**Participante en delegación**: nivel, grado, DNI, apellido paterno, apellido materno, nombres.

**Participante libre (independiente)**: DNI, apellido paterno, apellido materno, nombres, nivel, grado.

**Apoderado del estudiante libre**: DNI, apellido paterno, apellido materno, nombres, celular, correo electrónico (opcional — D-28). Se le piden los mismos campos que al docente delegado, porque son la misma entidad; el correo es lo único que cambia de obligatorio a opcional.

**Cabecera de la ficha (general)**: tipo de inscripción (delegación/independiente), fecha.

---

## 5. Esquema de base de datos propuesto `[DECISIÓN TÉCNICA — REVISABLE]`

```sql
CREATE TABLE organizaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE concursos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organizacion_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    fecha_evento DATE NOT NULL,
    fecha_inicio_inscripcion DATE NOT NULL,
    fecha_fin_inscripcion DATE NOT NULL,
    sede VARCHAR(200),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (organizacion_id) REFERENCES organizaciones(id)
) ENGINE=InnoDB;

CREATE TABLE categorias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    concurso_id INT UNSIGNED NOT NULL,
    nivel ENUM('primaria','secundaria') NOT NULL,
    grado TINYINT UNSIGNED NOT NULL,
    UNIQUE KEY uk_categoria (concurso_id, nivel, grado),
    FOREIGN KEY (concurso_id) REFERENCES concursos(id)
) ENGINE=InnoDB;

CREATE TABLE tarifas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    concurso_id INT UNSIGNED NOT NULL,
    tipo_origen ENUM('publica','privada','libre') NOT NULL,
    monto DECIMAL(6,2) NOT NULL,
    UNIQUE KEY uk_tarifa (concurso_id, tipo_origen),
    FOREIGN KEY (concurso_id) REFERENCES concursos(id)
) ENGINE=InnoDB;

CREATE TABLE instituciones_educativas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL,
    distrito VARCHAR(100) NOT NULL,
    provincia VARCHAR(100) NOT NULL,
    departamento VARCHAR(100) NOT NULL,
    tipo ENUM('publica','privada') NOT NULL,
    direccion VARCHAR(250),
    docente_delegado_ap_paterno VARCHAR(100),
    docente_delegado_ap_materno VARCHAR(100),
    docente_delegado_nombres VARCHAR(150),
    docente_delegado_celular VARCHAR(20),
    docente_delegado_correo VARCHAR(150),
    docente_delegado_dni VARCHAR(15) NULL,
    director_ap_paterno VARCHAR(100),
    director_ap_materno VARCHAR(100),
    director_nombres VARCHAR(150),
    director_celular VARCHAR(20),
    director_correo VARCHAR(150),
    director_dni VARCHAR(15) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE apoderados (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dni VARCHAR(15) NOT NULL UNIQUE,
    ap_paterno VARCHAR(100) NOT NULL,
    ap_materno VARCHAR(100) NOT NULL,
    nombres VARCHAR(150) NOT NULL,
    celular VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE participantes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo_correlativo VARCHAR(30) NOT NULL UNIQUE,
    concurso_id INT UNSIGNED NOT NULL,
    tipo_participante ENUM('delegacion','libre') NOT NULL,
    dni VARCHAR(15) NOT NULL,
    ap_paterno VARCHAR(100) NOT NULL,
    ap_materno VARCHAR(100) NOT NULL,
    nombres VARCHAR(150) NOT NULL,
    institucion_id INT UNSIGNED NULL,
    apoderado_id INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (concurso_id) REFERENCES concursos(id),
    FOREIGN KEY (institucion_id) REFERENCES instituciones_educativas(id),
    FOREIGN KEY (apoderado_id) REFERENCES apoderados(id)
) ENGINE=InnoDB;

CREATE TABLE usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombres VARCHAR(150) NOT NULL,
    correo VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('secretaria','administrador') NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE inscripciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    participante_id INT UNSIGNED NOT NULL,
    categoria_id INT UNSIGNED NOT NULL COMMENT 'movido desde participantes — ver decisión D-01, sección 11',
    usuario_id INT UNSIGNED NOT NULL COMMENT 'secretaria/admin que registró',
    estado ENUM('pendiente','confirmada','anulada') NOT NULL DEFAULT 'pendiente',
    monto DECIMAL(6,2) NOT NULL,
    medio_pago ENUM('yape','transferencia','efectivo') NULL,
    yape_codigo_seguridad CHAR(3) NULL COMMENT 'solo si medio_pago = yape',
    fecha_pago DATETIME NULL,
    motivo_anulacion VARCHAR(250) NULL,
    requiere_devolucion BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (participante_id) REFERENCES participantes(id),
    FOREIGN KEY (categoria_id) REFERENCES categorias(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE carnes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inscripcion_id INT UNSIGNED NOT NULL UNIQUE,
    codigo_qr VARCHAR(100) NOT NULL,
    ruta_pdf VARCHAR(255) NOT NULL,
    generado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (inscripcion_id) REFERENCES inscripciones(id)
) ENGINE=InnoDB;
```

**Nota:** validar en backend que `yape_codigo_seguridad` solo se exija cuando `medio_pago = 'yape'`; para `transferencia` y `efectivo` ese campo queda NULL.

---

## 6. Flujos principales (casos de uso)

1. **Inscripción institucional por lote**: la secretaria busca o crea la Institución Educativa (con datos de docente delegado y director), selecciona/crea el Concurso vigente, y registra N participantes tipo `delegacion`, cada uno con su categoría. Cada participante genera su propia fila en `inscripciones` (estado `pendiente`).
2. **Inscripción individual (libre)**: la secretaria busca o crea el Apoderado, registra al participante tipo `libre` con su categoría. Genera su propia `inscripcion` (estado `pendiente`).
3. **Confirmación de pago**: la secretaria selecciona el medio de pago (Yape, transferencia o efectivo); si es Yape, ingresa además el código de seguridad de 3 dígitos de la transacción. Al confirmar, la inscripción pasa a `confirmada` (aquí se considera cobrado el pago) y el sistema genera automáticamente el carné (PDF + QR) y lo pone disponible para descarga inmediata.
4. **Anulación con reinscripción**: la secretaria anula la inscripción existente (estado → `anulada`) y crea una nueva inscripción para el mismo participante con la categoría corregida.
5. **Anulación definitiva**: la secretaria anula la inscripción (estado → `anulada`). Si ya estaba `confirmada` (con pago), el sistema marca `requiere_devolucion = true` para que aparezca en el reporte de fondo de devoluciones.
6. **Reporte Excel**: el administrador o la secretaria exporta el listado de estudiantes inscritos, con filtros combinables por delegación (Institución Educativa), por tipo de origen (pública/privada/libre) y por grado.
7. **Reporte fondo de devoluciones**: listado de inscripciones con `requiere_devolucion = true` y su monto.

---

## 7. Roles y permisos

| Acción | Secretaria | Administrador |
|---|---|---|
| Registrar participantes e inscripciones | ✅ | ✅ |
| Confirmar pagos / generar carné | ✅ | ✅ |
| Anular inscripciones | ✅ | ✅ |
| Exportar reportes Excel | ✅ | ✅ |
| Gestionar Concurso, Categorías, Tarifas | ❌ | ✅ |
| Gestionar Instituciones Educativas (D-40) | ❌ | ✅ |
| Gestionar Apoderados | ✅ | ✅ |
| Reinscribir a un participante que quedó fuera (D-38) | ✅ | ✅ |
| Gestionar usuarios y contraseñas en `/usuarios` (D-39) | ❌ | ✅ |
| Gestionar Organización | ❌ | ✅ |

---

## 8. Plan de fases

> Nota para Claude Code: al ejecutar este plan, calcular los días restantes hasta el 22 de agosto de 2026 respecto a la fecha real de inicio de la implementación, no asumir que quedan 11 días.

**Fase 0 — Verificación de entorno (antes de escribir código)**
- Verificar PHP CLI vs PHP del sitio en Hostinger (versión, extensiones: pdo_mysql, gd, mbstring, zip).
- Confirmar acceso a Composer por SSH y capacidad de crear la base de datos MySQL desde cPanel.
- Confirmar credenciales de conexión a la base de datos.

**Fase 1 — Base del sistema**
- Estructura de carpetas, autoload con Composer (PSR-4), conexión PDO centralizada.
- Ejecutar el esquema SQL de la sección 5.
- Sistema de login/sesión con roles (secretaria/administrador).
- Seed inicial: la Organización (UNASAM), el Concurso (COCIAP 2026) con sus fechas y sede, las 11 Categorías, las 3 Tarifas.

**Fase 2 — Instituciones Educativas y Apoderados**
- CRUD de Institución Educativa (con datos de docente delegado/director embebidos).
- CRUD de Apoderado.
- Buscador para evitar duplicados (por nombre de I.E. o DNI de apoderado) antes de crear uno nuevo.

**Fase 3 — Inscripción**
- Flujo de inscripción institucional por lote (múltiples participantes en un solo trámite de UI, aunque cada uno genere su propia fila en `inscripciones`).
- Flujo de inscripción individual (libre).
- Generación del código correlativo del participante.

**Fase 4 — Pagos, anulación y carné**
- Confirmar pago → genera carné (PDF + QR) automáticamente.
- Anulación (con y sin reinscripción), marcado de `requiere_devolucion`.
- Vista digital del carné (accesible mediante el enlace codificado en el QR).

**Fase 5 — Reportes**
- Exportación Excel de estudiantes con filtros combinables por delegación (Institución Educativa), tipo de origen (pública/privada/libre) y grado.
- Reporte de fondo de devoluciones.

**Fase 6 — Pulido y despliegue**
- Validaciones de formulario (backend, nunca confiar solo en el frontend).
- Pruebas manuales de los flujos completos.
- Despliegue en Hostinger, verificación en producción con datos reales de prueba.

---

## 9. Fuera de alcance (explícito, no construir en este MVP)

- Validación de asistencia el día del concurso (QR de verificación en el evento).
- Módulo de calificación / resultados / premiación a la mejor institución (mencionado como necesidad futura que ya influyó en el diseño — cada Inscripción queda ligada a su Institución a través del Participante, para que ese módulo futuro pueda construirse sin rediseñar el dominio).
- Onboarding self-service de nuevas Organizaciones, panel multi-tenant completo, facturación/planes SaaS.
- **Aislamiento entre organizaciones. El sistema es de UN SOLO INQUILINO hasta nuevo aviso**
  (decisión del propietario, 2026-08-18, por tiempo hasta la presentación). No es una carencia
  teórica: está comprobada y detallada en P-07. Con una sola institución ninguno de esos fallos
  puede ocurrir; **dar de alta a una segunda mezcla los apoderados y los colegios de las dos**.
  Antes de que exista un segundo inquilino hay que hacer P-05 y P-07, en ese orden.
- Pasarela de pago en línea.
- Reemplazo del Google Form externo.

---

## 10. Preguntas abiertas — todas resueltas

Las 5 preguntas que originalmente quedaron abiertas en esta sección ya fueron resueltas por el propietario y están incorporadas en el resto del documento (secciones 3, 5 y 6):

1. Medio de pago: **sí se captura** (Yape/transferencia/efectivo; Yape requiere además el código de seguridad de 3 dígitos).
2. Cierre del periodo de inscripción: **no hay bloqueo automático**, se puede inscribir incluso el mismo día del evento.
3. Contenido del QR: **aprobado** — enlace con el código correlativo del participante.
4. Acceso a la vista digital del carné: **abierto**, cualquiera con el enlace puede verla.
5. Filtros del reporte Excel: **por delegación (Institución Educativa), por tipo de origen y por grado**, combinables.

No quedan preguntas abiertas pendientes de este análisis. Cualquier ambigüedad nueva que surja durante la implementación debe tratarse igual: preguntar al propietario antes de asumir, y registrar la resolución en la sección 11.

---

## 11. Registro de decisiones técnicas nuevas durante la implementación

*(Claude Code debe añadir aquí cualquier decisión técnica tomada durante el desarrollo que no estuviera ya prevista en este plan, con fecha y justificación breve.)*

---

### D-01 — `categoria_id` se mueve de `participantes` a `inscripciones`
**Fecha:** 2026-08-16 · **Estado:** aprobado por el propietario · **Afecta:** sección 5

**Problema detectado.** El esquema original ubicaba `categoria_id` en `participantes`,
pero el flujo 4 de la sección 6 exige que un cambio de categoría se resuelva anulando la
inscripción y creando una nueva "con la categoría corregida". Ese flujo era inejecutable:
la categoría no vivía en `inscripciones`, así que crear una inscripción nueva no cambiaba
la categoría del participante.

**Decisión.** `categoria_id` pasa a `inscripciones`, con su FK a `categorias(id)`. Se
elimina de `participantes` junto con su FK.

**Justificación.** Es la única de las tres salidas posibles que no rompe una regla
`[CONFIRMADO POR PROPIETARIO]`. Crear un participante nuevo habría cambiado el código
correlativo del estudiante; editar la categoría del participante habría contradicho la
regla de la sección 3 que prohíbe editar la categoría sobre una inscripción existente.
Además deja el historial correcto: la inscripción anulada conserva la categoría errónea y
la nueva registra la corregida, que es justamente el rastro que el futuro módulo de
calificación necesitará.

---

### D-02 — Verificación de entorno local (Fase 0)
**Fecha:** 2026-08-16 · **Estado:** ejecutado · **Afecta:** sección 8, Fase 0

Entorno de desarrollo confirmado como XAMPP local, con despliegue a Hostinger al final.
Resultado de la verificación:

| Componente | Estado |
|---|---|
| PHP CLI de XAMPP (`C:\xampp\php\php.exe`) | 8.2.12 |
| PHP que usa Composer (`C:\php\php.exe`) | 8.3.28 — **discrepancia** |
| `pdo_mysql`, `mbstring`, `fileinfo`, `openssl`, `curl`, `xml` | habilitadas |
| `gd` | **deshabilitada** (`;extension=gd`, php.ini L931; DLL presente) |
| `zip` | **deshabilitada** (`;extension=zip`, php.ini L962; DLL presente) |
| MariaDB | 10.4.32, `utf8mb4` / `utf8mb4_general_ci` |
| `sql_mode` local | `NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION` — **sin modo estricto** |
| Apache y MySQL | en ejecución |
| git | 2.54.0 disponible (proyecto aún sin repositorio) |

La discrepancia PHP CLI vs. PHP de Composer es exactamente la que advierte la sección 2
para Hostinger, materializada también en local: Composer resolvería dependencias contra
8.3.28 mientras el sitio corre sobre 8.2.12.

---

### D-03 — Charset y collation explícitos por tabla
**Fecha:** 2026-08-16 · **Estado:** aprobado por el propietario · **Afecta:** sección 5

Cada `CREATE TABLE` declara `DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` en vez de
heredar el default del servidor. Motivo: los nombres de estudiantes y colegios llevan tildes
y `ñ`, y el default de Hostinger no está bajo nuestro control. `unicode_ci` además ordena y
compara mejor el español que `general_ci`, lo que importa en el buscador de I.E. de la Fase 2.

---

### D-04 — Código correlativo no enumerable
**Fecha:** 2026-08-16 · **Estado:** aprobado por el propietario · **Afecta:** secciones 3 y 5

Formato: `COCIAP2026-0042-K7M9X3` — prefijo del concurso, correlativo numérico con relleno,
y sufijo aleatorio.

**Justificación.** Las reglas confirmadas dicen que el QR codifica un enlace con el código
correlativo y que la vista digital del carné es de acceso abierto. Con un correlativo
puramente secuencial, cualquiera que viera un solo carné podría recorrer todos los demás
incrementando el número, y cada carné expone nombre completo, DNI y colegio de un menor de
edad. El sufijo aleatorio conserva el orden y la legibilidad que la secretaría necesita, y
elimina la enumeración.

---

### D-05 — Duplicados por DNI: se advierten, no se impiden `[REVERTIDA POR D-31]`
**Fecha:** 2026-08-16 · **Estado:** aprobado por el propietario · **Afecta:** sección 5

`participantes.dni` queda **sin** `UNIQUE`. Se añade el índice `(concurso_id, dni)` para que
la comprobación sea barata. La interfaz avisa a la secretaria del posible duplicado y la deja
decidir. Un `UNIQUE` habría bloqueado reinscripciones legítimas cuando un DNI se digitó mal
en una inscripción ya anulada.

---

### D-06 — Front controller en `public/`
**Fecha:** 2026-08-16 · **Estado:** aplicado · **Afecta:** secciones 2 y 8

Toda petición entra por `public/index.php`; `app/`, `core/`, `config/`, `database/`,
`storage/` y `scripts/` quedan fuera del directorio servido. Es la estructura que usa Laravel,
hacia donde el plan prevé migrar, y evita que un archivo con credenciales pueda servirse por
URL. En Hostinger, apuntar el Document Root del dominio a `public/`; el `.htaccess` de la raíz
es la red de seguridad si eso no fuera posible.

Se añadió además una regla `RedirectMatch 404` sobre los directorios internos, deliberadamente
redundante: la reescritura depende de mod_rewrite y esa regla no.

---

### D-07 — Modo estricto de MySQL forzado desde la aplicación
**Fecha:** 2026-08-16 · **Estado:** aplicado · **Afecta:** sección 2

`Core\Database` ejecuta `SET SESSION sql_mode = 'STRICT_TRANS_TABLES,...'` al conectar. El
MariaDB local de XAMPP no trae modo estricto y trunca datos en silencio; Hostinger normalmente
sí lo trae. Sin esto, un dato que se guarda "bien" en desarrollo falla en producción.
Forzándolo, el error aparece en la máquina del desarrollador. Verificado: un DNI de más de 15
caracteres ahora es rechazado en lugar de recortado.

---

### D-08 — `platform.php` fijado en composer.json
**Fecha:** 2026-08-16 · **Estado:** aplicado · **Afecta:** sección 2

En la máquina de desarrollo, Composer corre sobre `C:\php\php.exe` (8.3.28) mientras la
aplicación corre sobre el PHP de XAMPP (8.2.12). Sin fijar `config.platform.php`, Composer
resolvería dependencias contra 8.3 para código que se ejecuta en 8.2. Es la misma discrepancia
que la sección 2 manda verificar en Hostinger, reproducida en local.

---

### D-09 — La sección 4 manda sobre la nulabilidad del esquema
**Fecha:** 2026-08-16 · **Estado:** aplicado, **pendiente de confirmación** · **Afecta:** secciones 4 y 5

**Discrepancia detectada.** La sección 4 `[CONFIRMADO POR PROPIETARIO]` lista como obligatorios
la dirección de la I.E. y los seis campos del docente delegado y del director (apellidos,
nombres, celular y correo; solo el DNI es opcional). Pero el esquema de la sección 5 declara
todas esas columnas como nulables.

**Decisión.** Prevalece la sección 4, por ser regla de negocio confirmada frente a una decisión
técnica revisable. La validación del servidor los exige; las columnas se dejan nulables para no
romper registros futuros ni forzar una migración.

**Resolución del propietario (2026-08-16):** el **docente delegado sí es obligatorio** —es
quien gestiona la inscripción y siempre está presente—; el **director es opcional**, porque
sus datos suelen conseguirse después y no deben bloquear el registro de la delegación. Los
campos opcionales igual se validan en formato si vienen llenos.

> **Superado en parte por D-28 (2026-08-18):** el DNI del docente delegado dejó de ser
> opcional. No fue un cambio de criterio sino una consecuencia: al pasar el docente delegado a
> ser el apoderado de su delegación, su documento es lo único que permite reconocerlo y no
> duplicarlo. El resto de D-09 sigue vigente, incluido que el director es opcional.

---

### D-10 — Documento de identidad: DNI o carné de extranjería
**Fecha:** 2026-08-16 · **Estado:** resuelto por el propietario · **Afecta:** sección 4

El validador acepta **8 dígitos** (DNI peruano, el caso normal) **o 9 a 12 caracteres
alfanuméricos** (carné de extranjería). Se descartó aceptar cualquier texto: un DNI mal
digitado que nadie detecta termina en un carné con el documento equivocado y en un descargo
que no cuadra. La columna `VARCHAR(15)` ya soportaba ambos formatos.

---

### D-11 — La tarifa se calcula sola, sin intervención de la secretaria
**Fecha:** 2026-08-16 · **Estado:** resuelto por el propietario · **Afecta:** secciones 3 y 6

El `tipo_origen` que selecciona la tarifa se deriva automáticamente:
- participante `delegacion` → toma `instituciones_educativas.tipo` (`publica` S/10 o
  `privada` S/15);
- participante `libre` → siempre `'libre'` (S/15).

La secretaria **no elige el monto**. Con delegaciones de 30 estudiantes, depender de que
recuerde la tarifa correcta en cada fila es la vía más rápida a un descuadre en el reporte de
recaudación. El monto se copia a `inscripciones.monto` como snapshot al inscribir.

---

### D-12 — Columna `concursos.codigo` y estrategia del correlativo
**Fecha:** 2026-08-16 · **Estado:** aplicado · **Afecta:** sección 5

Se añade `concursos.codigo VARCHAR(20) NOT NULL UNIQUE` (valor `COCIAP2026`). Es el prefijo del
código correlativo. Se declara explícito en vez de deducirlo del nombre: «IV Concurso Regional
de Conocimientos COCIAP 2026» no da un prefijo fiable por parsing.

**Número correlativo:** se usa el `AUTO_INCREMENT` de `participantes` como número. Calcular
`MAX(numero)+1` habría abierto una carrera —dos secretarias registrando a la vez podrían
obtener el mismo número—; el AUTO_INCREMENT de la base ya resuelve ese problema, así que se
aprovecha en lugar de reimplementarlo. El participante se inserta con un código provisional y
se actualiza al definitivo dentro de la misma transacción.

**Alfabeto del sufijo:** `ABCDEFGHJKMNPQRSTVWXYZ23456789`. Se excluyen I, L, O, U, 0 y 1 porque
el código se dicta por teléfono y se transcribe a mano. El sufijo usa `random_int()`
(criptográficamente seguro): con `rand()` la secuencia sería predecible a partir de unos pocos
códigos observados, que es justo lo que D-04 busca evitar.

---

### D-13 — Carné anulado: se muestra con sello, no se oculta
**Fecha:** 2026-08-16 · **Estado:** resuelto por el propietario (cierra **P-03**)

La vista pública `/carne/{codigo}` sigue respondiendo tras una anulación, pero muestra el carné
con sello **ANULADO**, el código tachado, el motivo y la leyenda «no es válida para el ingreso
al concurso». Se descartó el 404: quien escanea en la puerta necesita ver *por qué* no es
válido, no una página en blanco que parece un error del sistema.

`Participante::porCodigo()` toma la inscripción activa si la hay y, si solo quedan anuladas,
la última anulada. Así, tras «corregir categoría» el carné muestra la inscripción nueva y
vigente, no la anulada.

---

### D-14 — Confirmación de pago masiva, con transacción por inscripción
**Fecha:** 2026-08-16 · **Estado:** resuelto por el propietario

La secretaria selecciona varias inscripciones pendientes y las confirma juntas con un mismo
medio de pago y un mismo código Yape. Es como ocurre de verdad: una delegación paga sus 30
inscripciones con un solo Yape.

**Cada inscripción va en su propia transacción, no todas en una sola.** Si el carné número 27
falla al generarse, no tiene sentido deshacer los 26 pagos ya confirmados: el dinero se cobró.
El fallo se reporta y el carné se regenera después desde `/inscripciones/{id}/carne/regenerar`.

Los ids llegan por POST desde casillas del listado, así que `pendientesPorIds()` los acota al
concurso vigente y al estado `pendiente`: sin ese filtro se podría confirmar el pago de una
inscripción ajena manipulando el formulario.

---

### D-15 — Anulación: dos acciones distintas, no una con casilla
**Fecha:** 2026-08-16 · **Estado:** resuelto por el propietario

- **«Corregir categoría»** → anula y reinscribe en un paso. Conserva el código correlativo del
  participante y, si ya estaba pagada, la nueva inscripción **nace pagada**: nadie paga dos
  veces por un error de categoría. **No** genera devolución.
- **«Anular definitivamente»** → exige motivo y, solo si había pago confirmado, marca
  `requiere_devolucion`.

Se descartó una sola acción con casilla «va a reinscribirse»: olvidar marcarla contaminaría el
fondo de devoluciones con montos que nunca se van a devolver.

`requiere_devolucion` se decide en el servidor a partir del estado previo, nunca se acepta
desde el formulario: una inscripción pendiente jamás cobró nada, así que no hay qué devolver.

---

### D-16 — El código de seguridad de Yape es opcional `[REVERTIDA POR D-32]`
**Fecha:** 2026-08-17 · **Estado:** resuelto por el propietario · **Afecta:** secciones 3 y 6

Lo **obligatorio** al confirmar un pago es el **medio de pago**. El código de seguridad de 3
dígitos de Yape pasa a ser **opcional**: la secretaria no siempre lo tiene a la vista al momento
de cobrar, y bloquear la caja por un dato de respaldo la detendría sin motivo.

Si lo escribe, sí se valida el formato (3 dígitos exactos): un código a medias es peor que
ninguno, porque da falsa confianza al cuadrar después. Si lo deja vacío se guarda `NULL`, nunca
cadena vacía, para que el cuadre no confunda «no anotado» con «anotado en blanco».

Esto matiza la regla de la sección 3, que decía «Si el medio es Yape, se captura adicionalmente
el código de seguridad»: se captura si está disponible, no se exige.

---

### D-17 — Orden alfabético en español peruano
**Fecha:** 2026-08-17 · **Estado:** aplicado · **Afecta:** todos los listados

**Problema detectado.** Las tablas están en `utf8mb4_unicode_ci`, donde la Ñ se considera una
variante de la N. Medido sobre el servidor real, el orden salía así:

```
Muñoz | Ñahui | Ñañez | Navarro | Nazca | Ñopo | Núñez | Ochoa
```

Las Ñ quedaban **intercaladas entre las N**. En una nómina peruana eso es sencillamente un orden
equivocado.

**Decisión.** Se ordena con `COLLATE utf8mb4_spanish_ci` **solo en el `ORDER BY`**, sin cambiar
la colación de las columnas. Resultado verificado:

```
Muñoz | Navarro | Nazca | Núñez | Ñahui | Ñañez | Ñopo | Ochoa
```

**Por qué solo en el ORDER BY y no en las columnas.** Son dos necesidades opuestas y cada
colación sirve a una:

| | `unicode_ci` (columnas) | `spanish_ci` (orden) |
|---|---|---|
| Ordena la Ñ después de la N | no | **sí** |
| Buscar «Nanez» encuentra «Ñañez» | **sí** | no |

Dejando las columnas en `unicode_ci` la secretaria sigue encontrando «Ñañez» aunque escriba
«Nanez» sin la eñe, y aplicando `spanish_ci` al ordenar el listado sale correcto. Verificado en
ambos sentidos.

**No se usa `utf8mb4_spanish2_ci`** (español tradicional): pondría «Chávez» después de «Cortez»
y «Llanos» después de «Luján», porque trata CH y LL como letras independientes. La RAE eliminó
esa consideración en 1994.

**Coste asumido:** el `ORDER BY` con `COLLATE` distinto no puede aprovechar el índice y hace
`filesort`. Con volúmenes de cientos de filas es irrelevante.

**Riesgo de despliegue:** `Database::ordenEspanol()` comprueba una vez por petición que la
colación exista en el servidor; si Hostinger no la tuviera compilada, registra un aviso en el
log y el listado sigue funcionando con el orden anterior en lugar de fallar. **Verificar en la
Fase 6** con `SHOW COLLATION LIKE 'utf8mb4_spanish_ci'`.

**Aplicado en:** listado y buscador de Instituciones Educativas, listado de Apoderados, listado
de Usuarios, listado de Inscripciones y reporte de fondo de devoluciones (ver D-18).

---

### D-18 — El listado de Inscripciones pasa a orden de nómina
**Fecha:** 2026-08-17 · **Estado:** resuelto por el propietario (cierra **P-10**)

Se ordenaba por `created_at DESC` (lo último registrado arriba). Pasa a **apellido paterno,
apellido materno, nombres**, con la colación española de D-17. Es el orden de nómina peruana y
el que espera dirección en el descargo.

Se pierde a cambio que las recién registradas salgan juntas arriba; el mensaje de confirmación
tras el alta ya informa cuántas y de qué delegación, así que la pérdida es menor.

**Desempate final por `i.id ASC`:** cuando un participante tiene una inscripción anulada y su
reinscripción, ambas quedan adyacentes y en el orden en que ocurrieron, que es como se lee un
historial. Verificado.

El reporte de fondo de devoluciones (Fase 5) adopta el mismo orden.

---

### D-19 — Pipeline de assets con Gulp, SASS y BrowserSync
**Fecha:** 2026-08-17 · **Estado:** decisión técnica aplicada (cierra **P-07**)

La sección 2 exige «HTML + SASS (compilado a CSS) + JS» pero no definía herramienta, así que
`public/css/app.css` venía manteniéndose a mano (15.2 KB) y había ~400 líneas de JavaScript
incrustadas en cuatro vistas. Se adopta el flujo de Gulp del curso *Desarrollo Web Completo*
de Juan Pablo De la Torre, a petición del propietario.

**Alcance deliberado: solo el frontend.** Las convenciones de backend del curso
(`includes/funciones.php`, `incluirTemplate()`, clase `ActiveRecord`) son simplificaciones
didácticas y adoptarlas aquí sería un retroceso: se perderían el token CSRF de `Core\Sesion`,
el escape centralizado de `Core\View` y el autoload PSR-4. Además alejarían el proyecto de la
migración a Laravel que la sección 2 declara como objetivo. La arquitectura MVC no se toca.

**Estructura:** fuentes en `src/scss` (17 parciales: `base/`, `layout/`, `componentes/`,
`paginas/`, `utilidades/`) y `src/js` (4 módulos); salida a `public/build/`. El destino es
`public/build/` y no `build/` en la raíz porque el `.htaccess` raíz reescribe todo hacia
`public/` y una carpeta fuera de ahí sería inalcanzable.

**Datos de PHP hacia el JS:** los scripts inline recibían valores por interpolación
(`<?= json_encode($montos) ?>`). Ahora viajan en atributos `data-*` del marcado, que es lo que
permite que los `.js` sean estáticos y cacheables.

**Comandos:** `gulp` (compila y levanta BrowserSync con los oyentes) y `gulp build` (compilado
de producción). Requiere `gulp-cli` instalado global: `npm install --global gulp-cli`. Con nvm
los paquetes globales son por versión de Node, así que hay que reinstalarlo si se cambia de
versión. Sin él siguen funcionando `npm run dev` y `npm run build`, que usan el gulp local.

**Ningún servidor arranca solo:** BrowserSync se levanta únicamente al ejecutar `gulp` (o
`npm run dev`) y muere al cerrar ese proceso. Apache y MySQL los controla XAMPP, como siempre.

**Node 22 LTS:** la versión instalada era la 16, EOL desde 2023, que bloquea `sass` (exige
>=20.19), `gulp-postcss` (>=18) y `cssnano` (>=22.11). Se actualizó con nvm. Node vive solo en
la máquina de desarrollo; Hostinger no lo necesita.

**`public/build/` se versiona** (a diferencia de `vendor/`): el hosting compartido no tiene Node
para compilar allá. Contrapartida asumida: hay que correr `npm run build` antes de commitear
cambios de estilos. Los sourcemaps quedan ignorados, solo los genera `npm run dev`.

**Verificación de la migración del CSS:** se comparó el CSS compilado contra el original
parseando ambos con PostCSS y contrastando, selector por selector, la lista de declaraciones.
Resultado: 132 selectores en ambos, ninguna diferencia de comportamiento. El minificado baja de
15.2 KB a 10.7 KB.

**Fuera de alcance a propósito:** el carné en PDF conserva su `<style>` embebido en
`App\Servicios\GeneradorCarne`. dompdf soporta un subconjunto reducido de CSS y necesita
estilos autocontenidos; meterlo al pipeline rompería el PDF.

**Vulnerabilidades de npm:** `npm audit` reporta 6 (postcss 7 anidado en `gulp-sourcemaps`, e
`immutable` en `browser-sync`). **No ejecutar `npm audit fix --force`**: degradaría browser-sync
de 3.0.4 a 1.9.2. Son dependencias de desarrollo que nunca llegan al servidor, y el postcss que
usan autoprefixer y cssnano es el 8.5.26, ya parchado.

---

### D-20 — Base de navegación derivada del request, separada de la canónica
**Fecha:** 2026-08-17 · **Estado:** decisión técnica aplicada

`app.url_base` estaba fija en `http://localhost/compite` y la usaban tanto `Core\View::url()`
(enlaces y assets) como `GeneradorCarne::urlPublica()` (la URL dentro del QR). Se separan:

- **Canónica (`app.url_base`)**: única fuente para lo que se persiste o se imprime. El QR del
  carné la sigue leyendo directamente. **Nunca debe derivarse del request:** un carné generado
  durante una sesión de pruebas quedaría con esa URL grabada de forma permanente en papel.
- **De navegación (`View::baseNavegacion()`)**: derivada de `HTTP_HOST`, y **solo en entorno
  local**. En producción devuelve la canónica tal cual, porque la cabecera `Host` la controla el
  cliente y no debe decidir a dónde apuntan los enlaces del sistema.

El motivo real es poder probar desde un celular en la red local
(`http://192.168.x.x/compite`), que con la base fija generaba enlaces a `localhost` inservibles
en el teléfono. Para BrowserSync no era necesario: su proxy ya reescribe los enlaces del HTML
por su cuenta, y al proxear envía `Host: localhost`.

---

### D-21 — Ningún dato de la base puede depender del entorno
**Fecha:** 2026-08-17 · **Estado:** corregido a pedido del propietario

`carnes.codigo_qr` venía guardando la **URL absoluta** de la vista pública
(`http://localhost/compite/carne/COCIAP2026-0019-KZZQMX`), escrita en
`PagoController::emitirCarne()`. Es incorrecto: al restaurar la base en Hostinger, todos los
carnés ya emitidos seguirían apuntando a `localhost`, y la única forma de arreglarlo sería
reescribir filas después de cada despliegue.

**Agravante:** la columna **nunca se leía**. Era un dato muerto que solo servía para atar la
base a la máquina donde se generó el carné.

**Corrección:** pasa a guardar el **código correlativo**. La URL se arma cuando se necesita con
`GeneradorCarne::urlPublica()`, que lee `app.url_base`. Migración idempotente en
`database/migraciones/2026-08-17-codigo-qr-sin-url.sql` (solo toca filas con forma de URL);
aplicada sobre las 4 filas existentes.

**Regla general que queda establecida:** la base guarda identificadores y rutas relativas, nunca
URLs absolutas ni rutas del sistema de archivos. `ruta_pdf` ya cumplía
(`storage/carnes/CODIGO.pdf`, relativa a la raíz del proyecto).

**Auditoría:** se recorrieron todas las columnas de texto de las 10 tablas buscando `http://`,
`https://`, `localhost`, `127.0.0.1`, `C:\` y `xampp`. Tras la migración: **cero hallazgos**.

**Lo que sí lleva la URL absoluta, y debe llevarla:** el PDF del carné, porque se imprime y el
QR tiene que ser escaneable desde cualquier teléfono. La toma de `app.url_base`, así que en
producción sale con el dominio real. **Consecuencia para el despliegue:** un PDF generado en
local queda con el QR apuntando a localhost; los carnés emitidos antes de subir a producción hay
que regenerarlos con `/inscripciones/{id}/carne/regenerar`.

---

### D-22 — Acceso externo al servidor de pruebas
**Fecha:** 2026-08-17 · **Estado:** decisión técnica aplicada

Para probar los formularios con la secretaría desde sus propios equipos y teléfonos, `gulp`
anuncia en consola el enlace de red local además del de la máquina:

```
En esta maquina : http://localhost:3000/compite
Para compartir  : http://192.168.1.115:3000/compite
```

**Detección de la IP:** se calcula en cada arranque desde `os.networkInterfaces()`, no se
configura a mano, porque la IP es por DHCP y cambia. Se descartan las **169.254.x.x** (APIPA):
Windows se las asigna a interfaces sin red real —Bluetooth, adaptadores virtuales— y en esta
máquina hay cuatro. Eran la razón de que BrowserSync no anunciara URL externa: no acertaba cuál
elegir. Se le pasa la IP buena en `host` y se fuerza el anuncio con `online: true`.

**Los enlaces no se salen del proxy:** BrowserSync reescribe las URLs del HTML al host con el
que llegó la petición. Verificado: pidiendo a `192.168.1.115:3000`, el CSS sale como
`//192.168.1.115:3000/compite/build/css/app.css`.

**Vía alternativa sin BrowserSync:** `http://192.168.1.115/compite` (Apache directo, puerto 80).
No tiene recarga automática, pero no depende de que el proceso de gulp siga vivo, así que es más
estable para una sesión larga de pruebas con usuarios. Funciona gracias a D-20: sin la base de
navegación derivada del request, los enlaces apuntarían a `localhost` y no abrirían en el
teléfono. Verificado, HTTP 200.

**Requiere abrir el puerto en el Firewall de Windows**, limitado al perfil `Private` (la red
«COCIAP» está clasificada así). No se aplica automáticamente: exige permisos de administrador y
es una decisión de seguridad del propietario, no del código.

```powershell
New-NetFirewallRule -DisplayName "COCIAP 2026 - BrowserSync (3000)" `
  -Direction Inbound -Protocol TCP -LocalPort 3000 -Action Allow -Profile Private
```

**Alcance y límite:** esto expone el sistema a **cualquiera en la misma Wi-Fi**, con datos
personales de menores a la vista. Es aceptable para una sesión de pruebas controlada en el
colegio; no es un mecanismo de publicación. Conviene retirar la regla al terminar con
`Remove-NetFirewallRule -DisplayName "COCIAP 2026 - BrowserSync (3000)"`.

---

### D-23 — El carné adopta el tamaño ID-1 y se imprime por hojas A4
**Fecha:** 2026-08-18 · **Estado:** aprobado por el propietario

El carné medía **100 × 70 mm**, que no corresponde a ningún estándar: ningún portacarné
comercial le calza. Pasa a **ID-1 (85.6 × 53.98 mm)**, el del DNI y las tarjetas bancarias.

**Por qué ID-1 y no A7 (105 × 74):** por la aritmética de la hoja A4.

| Tamaño | Grilla A4 | Por hoja | Margen lateral |
|---|---|---|---|
| **ID-1** | 2 × 5 | **10** | 18.5 mm ✅ |
| 100 × 70 (anterior) | 2 × 4 | 8 | 5 mm ⚠️ zona no imprimible |
| A7 | 2 × 4 | 8 | 0 mm ❌ |

Para 500 participantes son 50 hojas en lugar de 63.

**El PDF se maqueta siempre sobre A4, incluso para un solo carné.** Un PDF del tamaño exacto
del carné obliga a la impresora a escalarlo (~94%) para que entre en su área imprimible: el
carné dejaría de medir lo que dice, y el QR con él. Sobre A4 el tamaño impreso es fiel.

**Impresión por delegación**, no «todos los del concurso»: Dompdf tarda ~0.4 s cada diez carnés,
y quinientos de una sentada se comen el `max_execution_time` de un hosting compartido. Ruta
`/delegaciones/{id}/carnes.pdf`, con guías de corte punteadas y **solo inscripciones
confirmadas** —imprimir el carné de una pendiente o anulada pone a circular un documento que
parece válido y no lo es.

**Escudo institucional** (`public/img/logo-cociap.png`) a 12.5 mm de alto en la cabecera. Su
texto perimetral es ilegible a ese tamaño —mediría 0.6 mm, contra los 1.5 mm que necesita
cualquier texto impreso—, así que el nombre del colegio se repite **como texto real** al lado:
el escudo aporta el reconocimiento visual, el texto la información.

**Apellidos y nombres van en líneas separadas, como en el DNI.** No es estética: un nombre
peruano completo pasa de 29 caracteres, saltaba a una segunda línea y el carné crecía lo justo
para que la quinta fila no entrara en la hoja. Partido en dos, la altura es constante. Para los
casos extremos, `GeneradorCarne::tamanoQueQuepa()` encoge la letra hasta un 70% antes que
truncar el nombre, que es el dato más importante del carné.

**Calibración:** los umbrales (29 caracteres para el nombre, 46 para la procedencia) se midieron
generando hojas de 10 carnés y alargando el texto hasta que la hoja se partía. En el código se
usan 26 y 42 como margen, porque el ancho real depende de qué letras compongan el texto. **Si
cambia el ancho del carné, el del QR o el cuerpo base, hay que volver a medirlos.**

---

### D-24 — El PDF del carné se genera al vuelo y deja de guardarse
**Fecha:** 2026-08-18 · **Estado:** aprobado por el propietario

Confirmar un pago escribía un PDF en `storage/carnes/` y `carnes.ruta_pdf` guardaba su ruta.
Tres problemas:

1. **El PDF quedaba congelado** con el diseño del día en que se emitió. Este mismo rediseño
   habría dejado los carnés ya emitidos con la maqueta vieja.
2. `storage/carnes/` **tenía que viajar al despliegue**, o los carnés desaparecían en producción.
3. Si el archivo se borraba, la descarga fallaba y había que regenerarlo a mano.

El PDF es un **derivado de la base**, no un documento con vida propia. Ahora se arma en cada
descarga. La tabla `carnes` sigue registrando el hecho de negocio —qué inscripción tiene carné
emitido y desde cuándo—, que es lo único que no se puede recalcular.

Migración idempotente en `database/migraciones/2026-08-18-carne-sin-archivo.sql`.
`rutas.carnes` sale de `config/config.php`. Efecto colateral bienvenido: **emitir un carné ya no
puede fallar por permisos de disco** ni dejar un pago confirmado sin carné.

**Queda pendiente decidir** qué hacer con los cuatro PDF antiguos que siguen en
`storage/carnes/`. Son huérfanos —ya nada los lee— pero no se han borrado sin consultar.

**Corrección del 18 de agosto: esta migración no estaba aplicada, aunque aquí se afirmaba que
sí.** Salió a la luz probando el cobro: `carnes.ruta_pdf` seguía existiendo como `NOT NULL` sin
valor por defecto, el `INSERT` que ya no la escribe fallaba con el error 1364 de MySQL y ninguna
de las tres inscripciones llegó a confirmarse. La causa más probable es la restauración del
respaldo previo hecha para verificar D-28: ese respaldo era anterior a esta migración y la
deshizo sin que nadie lo notara. Ya está aplicada y comprobada dos veces sobre la base local,
con las cuatro filas de `carnes` intactas.

**Dos lecciones, y una queda abierta.** La primera se corrigió: el mensaje de error del cobro
decía «el resto sí quedó confirmado» incluso cuando no se había confirmado ninguna, que con
dinero de por medio es peor que no decir nada; ahora distingue los dos casos y nombra el archivo
de log. La segunda no tiene solución todavía: **nada comprueba qué migraciones tiene puesta una
base**. Un registro de migraciones aplicadas evitaría que el despliegue en Hostinger repita este
mismo susto, esta vez en producción y con la secretaria delante.

---

### D-25 — El QR codifica una ruta corta, y su tamaño se calcula
**Fecha:** 2026-08-18 · **Estado:** aprobado por el propietario

**Contexto:** el propietario confirmó que **no habrá verificación por QR en la puerta**. La
pregunta era si el QR seguía teniendo sentido.

**Se conserva, y por una sola razón que se sostiene:** el carné impreso es un artefacto
irreversible. Si el día del concurso aparece un carné dudoso, o si una inscripción se anula
*después* de imprimir el papel —caso que ya existe en la base—, el QR resuelve en cinco segundos
lo que sin él no tiene solución, porque los 500 carnés ya están repartidos. La vista pública
estampa el sello «Anulado»; el papel no puede. El costo de conservarlo son 15 mm²; el de no
tenerlo, reimprimirlo todo.

**Consecuencia:** deja de ser protagonista. Baja del 38% del ancho a una esquina.

**Y eso obliga a acortar la URL**, porque cada carácter le añade módulos al QR:

| URL | Caracteres | Módulos | A 15 mm |
|---|---|---|---|
| `/carne/COCIAP2026-0042-K7M9X3` | 46 | 37² | 0.405 mm ❌ |
| `/c/K7M9X3` | 26 | 29² | **0.517 mm** ✅ |

El umbral de lectura fiable con cámara de celular es 0.5 mm por módulo. De ahí la ruta `/c/{sufijo}`
y que **el sufijo tenga que ser único por sí solo** (`Participante::existeSufijo()`, con reintento
al crear el participante). La ruta larga `/carne/{codigo}` sigue viva por compatibilidad.

**Corrección de errores: Quartile, no High.** Parece un downgrade y es lo contrario: más
corrección significa más módulos en el mismo espacio impreso, y un QR de módulos diminutos con
corrección alta se lee peor que uno de módulos grandes con corrección media.

**El tamaño impreso no es fijo:** `GeneradorCarne::ladoQr()` lo calcula entre 15 y 17 mm según
los módulos que pida la URL, para sostener los 0.5 mm/módulo. Si ni al máximo se llega, el carné
se genera igual pero **queda constancia en el log**.

> ⚠️ **Para el despliegue:** la longitud de `app.url_base` decide si el QR se lee.
> `https://cociap.pe` da 0.52 mm/módulo (bien). Un dominio con subcarpeta como
> `https://www.colegioaplicacion.edu.pe/cociap` baja a 0.46 mm y el sistema avisará en el log.
> **Conviene elegir un dominio corto.**

---

### D-26 — Pantalla de control de ingreso para el día del concurso
**Fecha:** 2026-08-18 · **Estado:** aprobado por el propietario

Pregunta abierta: qué pasa si un estudiante pierde su carné impreso. Hasta ahora la única
respuesta era que la secretaría lo reimprimiera.

**Decisión del propietario:** una pantalla de búsqueda para la mesa de la puerta (`/control`).

**El razonamiento:** con estudiantes de primaria y secundaria, la pérdida del carné no es un
riesgo, es una certeza estadística. Diseñar el ingreso asumiendo que todos traerán su papel es
diseñar para fallar. El carné pasa a ser lo que acelera la fila; **la fuente de verdad es esta
consulta**, que además funciona aunque el internet esté lento porque es la red propia.

Busca por apellido, documento o código. Muestra el veredicto —*Puede ingresar* / *Pago
pendiente* / *No puede ingresar*— en verde, ámbar y rojo, con el texto escrito además del color,
porque hay quien no distingue el rojo del verde. Exige al menos dos caracteres y corta a 25
resultados: si hay más, la solución no es hacer scroll sino escribir el apellido completo.

**Se descartó un buscador público por DNI**, que habría dado autoservicio total: convertiría el
sistema en un consultador de datos de menores por número de documento, y sin fecha de nacimiento
en `participantes` no hay segundo factor con qué protegerlo.

---

### D-27 — Marca de agua institucional y campos del participante desglosados
**Fecha:** 2026-08-18 · **Estado:** aprobado por el propietario · **Afecta:** D-23, D-25

Dos peticiones del propietario sobre el carné, que resultaron estar acopladas.

**1. El escudo a 12.5 mm era «prácticamente una mancha».** Lo era: D-23 ya había
aceptado que su texto perimetral es ilegible a ese tamaño y lo justificaba como
reconocimiento visual. El propietario propone en su lugar el logo de aniversario
(`logoaniversario2026.png`) como marca de agua de fondo.

Se adopta, y **el escudo pequeño de la cabecera desaparece**: el logo de aniversario
*ya contiene el mismo escudo*, y repetirlo dos veces en 85 mm no informaba de nada.
Quitarlo tuvo dos efectos que resolvieron problemas abiertos:

- El nombre del concurso dejó de partirse en dos líneas: recupera el ancho completo.
- Los 12.5 mm de alto que el escudo imponía a la cabecera son justo los que
  necesitaban los campos nuevos. Sin ese hueco, el desglose no habría entrado.

**La transparencia se hornea en el PNG, no se pide por CSS.** Dompdf soporta
`opacity` de forma parcial y desigual entre versiones, y un carné cuya opacidad
dependa de la versión de una librería es un carné que se imprime distinto cada
año. `scripts/generar_marca_agua.php` genera el derivado al 10% —por encima del
12% compite con los rótulos de 4.6 pt, por debajo del 7% no se ve— siguiendo el
patrón que ya usaba el escudo: original en `resources/img/`, derivado en
`public/img/`, ambos versionados.

**Consecuencia no evidente: el QR pierde su zona de silencio.** La norma del QR
exige 4 módulos de margen limpio alrededor del símbolo, y hasta ahora los aportaba
«el espacio en blanco que el layout deja alrededor». Con un fondo, ese espacio deja
de ser blanco y el lector confunde el borde del símbolo con datos. El QR pasa a
apoyarse sobre **un recuadro blanco opaco** de 2.1 mm por lado. No es decoración:
sin él, la marca de agua habría roto el QR de forma silenciosa —seguiría
imprimiéndose igual de bonito y fallando en la puerta.

Verificado leyendo las matrices de colocación del propio PDF: la marca se dibuja a
**70.6 × 54.0 mm**, embebida una vez y referenciada diez, y el QR a 16.5 mm con 33
módulos = 0.500 mm/módulo.

**Y un defecto que la marca de agua destapó:** la celda de relleno de las hojas
impares también recibía el fondo, y quedaba un carné en blanco con la marca
institucional listo para recortar y rellenar a mano. `.celda--vacia` conserva las
guías de corte pero no el fondo.

**2. Los datos del participante se desglosan** en DNI, Apellidos, Nombres, Grado,
Modalidad y —solo si no es libre— Procedencia.

- **Apellidos y Nombres ganan rótulo propio.** D-23 los había dejado sin rótulo
  para ahorrar 2 mm. Con el escudo fuera, esos 2 mm sobran, y el rótulo resuelve
  una ambigüedad real: «Nolasco Mendoza Sara» sin rótulos se puede leer con el
  apellido en cualquiera de los dos sitios.
- **Modalidad** (Libre / Pública / Privada) se deriva de `tipo_participante` y
  `instituciones_educativas.tipo` —los mismos tres valores que `tarifas.tipo_origen`
  usa para decidir cuánto paga el estudiante— para que el carné no pueda contradecir
  a la tarifa que se cobró. **Esto convirtió a P-04 en bloqueante de verdad:** hasta
  entonces esa correspondencia solo afectaba a un cálculo interno; al imprimirse pasó a
  un documento irreversible. **Confirmado por el propietario el 2026-08-18** — ver P-04
  en «Decisiones pendientes».
- **Un estudiante libre no lleva Procedencia.** Repetirla como «Estudiante libre»
  sería decir dos veces lo que ya dice Modalidad.

**Orden de los campos:** el propietario los enumeró empezando por el DNI. En el
carné van con el nombre arriba y el DNI en la fila de tres, porque el nombre es el
único dato que se lee a un metro de distancia en la fila de la puerta y encabezarlo
con un número de ocho dígitos le quita esa función. Si el propietario prefiere el
orden literal, es mover un bloque.

**La franja de Modalidad y Procedencia va dentro de la columna de datos, no a lo
ancho del carné.** A lo ancho parecía mejor —le daba 56 mm al nombre del colegio en
vez de 33— pero añadía altura *encima* de la del QR en lugar de aprovechar el hueco
que el QR deja debajo, y la hoja se partía en dos páginas. Medido: diez carnés en
una página con los diez casos extremos que el sistema puede recibir.

**Recalibración obligada por D-23.** El recuadro blanco del QR le quitó 2.2 mm de
ancho a la columna de datos (61.4 → 59.2 mm): `NOMBRE_POR_LINEA` baja de 26 a 25 y
`ORIGEN_POR_LINEA` de 42 a 40.

**La vista pública del carné se alinea con los mismos campos y el mismo orden.** Es
lo que abre el QR del papel: si la mesa de la puerta ve ahí una estructura distinta
de la que tiene en la mano, deja de poder contrastar un dato con el otro.

**Queda huérfano** `public/img/logo-cociap.png` (y su original en `resources/img/`):
ya no lo usa nada. No se borra sin consultar — el escudo suelto puede quererse en la
interfaz web. **Resuelto en D-33** (2026-08-19): vuelve a la cabecera del carné, a 6 mm
y maquetado en dos columnas.

---

### D-28 — El encargado de la delegación ES el apoderado de sus participantes
**Fecha:** 2026-08-18 · **Estado:** aprobado por el propietario · **Afecta:** secciones 3, 4 y 5, D-09

Petición del propietario: que un apoderado pueda inscribir a varios participantes, y que
**a cada delegación se le asigne como apoderado a su encargado**.

**El encargado ya existía, con otro nombre.** Es el *docente delegado*, que hasta ahora vivía
embebido en seis columnas de `instituciones_educativas`, mientras los participantes de
delegación se guardaban con `apoderado_id = NULL`. La misma persona podía existir dos veces
—como docente delegado de su colegio y como apoderado del hijo que inscribió como libre— con
dos celulares destinados a divergir y sin forma de saber cuál era el bueno.

**Decisión: unificar.** `apoderados` pasa a ser *el adulto responsable de uno o varios
participantes*, cubriendo los dos casos. `instituciones_educativas` deja de embeber al docente
y guarda `docente_delegado_id` (NOT NULL, con clave foránea). El **director se queda embebido**:
no es apoderado de nadie.

Se descartaron las otras dos salidas:

- *Copiar el docente a `apoderados` al inscribir*: no toca el CRUD de instituciones, pero deja
  a la misma persona en dos sitios. El día que alguien corrija el celular en un sitio y no en
  el otro, divergen para siempre.
- *Derivar por join sin asignar*: cero duplicación, pero no cumple lo pedido y hace que cambiar
  de docente delegado reescriba retroactivamente quién inscribió las delegaciones de años
  anteriores.

**El vínculo se guarda en cada participante, no se deduce.** `participantes.apoderado_id` se
rellena al inscribir con el encargado vigente en ese momento. Si el año que viene encabeza otro
docente, estas inscripciones siguen diciendo quién las hizo, no quién manda hoy.

**Contradicción que esto obligó a resolver.** No se pueden sostener las tres a la vez:

1. el DNI del apoderado es obligatorio —lo es, y es lo único que permite reconocer a la persona
   en vez de duplicarla—;
2. el encargado de delegación es un apoderado;
3. el DNI del docente delegado es opcional, como declaraba la §4 marcada
   `[CONFIRMADO POR PROPIETARIO]` y reafirmaba D-09.

El propietario eligió sacrificar la tercera: **el DNI del docente delegado pasa a ser
obligatorio**. La §4 queda actualizada.

**Los dos formularios piden los mismos datos** (decisión del propietario). El único campo que
no encajaba era el correo, que el docente delegado tenía y el apoderado de un libre no.
`apoderados` gana `correo VARCHAR(150) NULL` y **ambos formularios lo piden, opcional en los dos**:
es la misma persona y el mismo campo, y no hay razón para exigírselo a uno y no al otro.

**Quién puede borrar ese correo, y quién no.** `Apoderado::actualizar()` solo toca la columna
si quien llama trae la clave, y los dos llamantes la usan distinto a propósito:

- La ficha de `/apoderados` la manda **siempre**, incluso vacía: esa pantalla existe para editar
  a esa persona, así que desde ahí sí se puede borrar un correo equivocado.
- La inscripción libre la manda **solo si trae valor**. Si el apoderado de ese estudiante resulta
  ser también el docente delegado de un colegio, dejar el campo en blanco mientras se inscribe a
  un niño no puede borrarle el canal por el que se coordina con su delegación entera. Es un
  formulario que está haciendo otra cosa: puede añadir, no vaciar.

**Reutilizar sin pisar.** Cuando el documento reconoce a alguien ya registrado, sus datos se
autorrellenan y quedan **en solo lectura**, con un botón «Editar sus datos» para desbloquearlos
a conciencia (decisión del propietario). El formulario actualiza la ficha del apoderado al
guardar, y esa ficha la comparten todos sus participantes: sin el freno, un tipeo al inscribir
al tercer hijo reescribía en silencio el apoderado de los otros dos, y en el caso del docente,
el de su delegación entera. Si el documento deja de reconocer a nadie, los campos
autorrellenados **se vacían**: conservar el nombre de una persona bajo el documento de otra
habría creado un registro nuevo con datos ajenos.

El comportamiento vive en `src/js/apoderado-reutilizable.js`, compartido por los dos
formularios. En un archivo aparte expuesto en `window` porque el pipeline de assets copia cada
`src/js/*.js` por separado, sin empaquetador: no hay forma de importarlo, y duplicarlo
garantizaba que algún día divergieran.

**El listado de `/apoderados` distingue la modalidad.** Al ser una sola tabla para los dos
casos, sin esa columna la secretaria no puede saber si una fila es el encargado de un colegio o
el padre de un estudiante libre —y no da igual: borrar a uno rompe una delegación entera—. Las
etiquetas **no son excluyentes**: el docente que encabeza la delegación de su colegio y además
inscribió a su propio hijo como libre lleva las dos. Un apoderado sin ninguna es alguien que se
registró y todavía no se usó, que también conviene ver.

Los tres recuentos van como subconsultas y no como JOIN con GROUP BY: hay dos relaciones
distintas hacia el mismo apoderado —sus participantes y las instituciones que encabeza— y
unirlas en la misma consulta multiplicaría las filas, inflando cada recuento por el tamaño de la
otra relación.

**Un error que apareció de paso:** `/api/apoderados/buscar` exigía `^\d{8}$`, más estricto que
la regla con la que se registran los apoderados (`Validador::dni()` acepta también carné de
extranjería de 9 a 12 caracteres). Un apoderado dado de alta con carné de extranjería era
**imposible de encontrar** desde el formulario, y se duplicaba cada vez que volvía. Ahora ambos
usan el mismo criterio.

**Migración** en `database/migraciones/2026-08-18-encargado-delegacion-es-apoderado.sql`,
idempotente y aplicada. Promueve a cada docente delegado a `apoderados` reutilizando la fila si
el DNI ya existía, enlaza las I.E., **asigna el encargado a los participantes de delegación ya
inscritos** —si no, las inscripciones anteriores a la migración serían las únicas sin encargado
y el listado mentiría sobre ellas— y solo al final suelta las seis columnas embebidas, para que
hasta ese punto siga siendo reversible. Si algún docente no tuviera DNI, se detiene a propósito
en el paso 5 y el paso 0 lista cuáles completar.

Verificado sobre una restauración del respaldo previo, dos pasadas seguidas, más siete
comprobantes contra la base real: el docente migrado conserva su correo, una I.E. nueva queda
enlazada a su encargado, el documento repetido no crea una segunda persona, y actualizar sin
correo no borra el correo.

---

### D-29 — Dos fallos de maquetación que apagaban la caja

**Fecha:** 2026-08-18 · **Estado:** corregido · **Afecta:** listado de inscripciones, D-16, D-28

Dos reportes del propietario —el botón «Editar sus datos» visible antes de reconocer a
nadie y «no se puede confirmar ningún pago»— resultaron ser dos defectos distintos, ambos
en el HTML/CSS y ninguno en la lógica de negocio, que ya era correcta.

**1. El atributo `hidden` perdía contra el `display` del componente.** El navegador trae
`[hidden] { display: none }`, una regla de especificidad mínima: cualquier clase con
`display: flex` o `grid` la gana. Tres bloques que el JS oculta se maquetan así, y por
eso salían siempre, sin importar lo que el JS dijera:

- el aviso de apoderado reutilizado con su botón «Editar sus datos» (`.reutilizado`,
  `display: flex`) — el reporte C.7, en los dos formularios de D-28;
- el código de seguridad de Yape (`.barra-cobro__campo`, `display: grid`) — visible con
  transferencia y efectivo, cuando D-16 lo reserva a Yape;
- la barra de cobro entera (`.barra-cobro`, `display: flex`), que debía aparecer solo al
  marcar inscripciones.

Se corrige una vez, en `base/_globales.scss`, con `[hidden] { display: none !important; }`.
El `!important` no es negociable aquí: sin él la regla vuelve a perder contra cualquier
clase, que es exactamente el fallo. Se descartó apagar el `display` componente por
componente —tres parches hoy y el mismo error la próxima vez que algo se oculte con JS—
y se descartó cambiar el JS a una clase `.oculto` propia: obligaría a recordar la
convención en cada pantalla, cuando el atributo estándar ya expresa la intención y lo
leen los lectores de pantalla.

**2. Un formulario anidado cerraba el de cobro antes de tiempo.** El listado envuelve la
tabla en `#form-cobro`, y la fila de cada inscripción confirmada llevaba dentro su propio
`<form>` para «Regenerar». Anidar formularios no es HTML válido: el navegador ignora la
etiqueta de apertura del interior, pero su cierre **cierra el formulario de cobro**. Desde
la primera fila confirmada en adelante, las casillas restantes y el botón «Confirmar pago
y emitir carnés» quedaban fuera de todo formulario, y un botón sin formulario no envía
nada: el clic no hacía absolutamente nada, sin error ni mensaje.

Explica por qué el fallo parecía intermitente: filtrando por «pendiente» no hay filas
confirmadas, no hay anidamiento y la caja funciona. Es en el listado completo —el uso
normal— donde se rompe.

Ahora hay **un solo** `#form-regenerar` fuera del formulario de cobro, y el botón de cada
fila se asocia a él con `form="form-regenerar"` y pone su destino con `formaction`. Se
descartó sacar la regeneración a un enlace `GET`: cambia el estado del sistema y quedaría
sin token CSRF, expuesta a que la dispare cualquier recarga o precarga del navegador.

Verificado parseando el HTML antes y después: con el formulario anidado, el botón
«Confirmar» tenía como ancestros `body < html` —ningún formulario—; ya no. Queda por
comprobar en el navegador con la base real, junto con el resto del banco de pruebas.

---

### D-30 — Los avisos van donde está mirando quien los necesita

**Fecha:** 2026-08-18 · **Estado:** aprobado por el propietario · **Afecta:** todas las pantallas

Petición del propietario tras probar IE-6: el aviso de institución duplicada aparecía al pie del
formulario, a pantalla y media del campo del nombre que lo dispara. Pidió además un criterio
general, no un parche: **ningún banner de alerta puede quedar fuera de la vista**.

El criterio que se adopta tiene tres reglas y una prohibición.

**1. El mensaje de un campo vive pegado a ese campo.** No al pie del formulario, no en una caja
al principio. La ficha de institución tiene veinte campos en cuatro grupos: un aviso al final no
dice *cuál* de los veinte lo provocó, que es lo único que hace falta saber. Se movió
`#aviso-duplicados` junto al nombre, y el aviso de documento repetido de la delegación pasó de
una caja al pie de la nómina a marcar **la celda concreta** de la fila repetida. En una nómina de
treinta filas, esa diferencia es la que separa un aviso útil de uno decorativo.

**2. El resultado de una acción va en una franja pegajosa.** «Se confirmaron 3 pagos por
S/ 30.00» aparecía arriba del listado y se perdía en cuanto la secretaria bajaba a comprobar las
filas. Ahora la franja de avisos queda fija en el borde superior mientras se desplaza.

**3. Cuando el servidor devuelve errores de campo, el foco viaja al primero.** Antes la página
volvía arriba y el error podía estar a dos pantallas de distancia. El desplazamiento deja el
campo centrado, no pegado al borde: la franja pegajosa lo taparía.

**Lo que se descartó: los toast que se desvanecen solos.** Es la solución de moda y aquí es la
equivocada. En estas pantallas se confirman cobros, y un mensaje de dinero que desaparece a los
tres segundos es un mensaje que alguien no llegó a leer, sin forma de recuperarlo. Los avisos se
cierran a mano, con su botón, o se quedan.

Implementación: `.avisos` en `_avisos.scss` y el layout, `src/js/avisos.js` cargado en todas las
páginas, `.entrada--error` para las celdas de la nómina.

---

### D-31 — Un documento, un participante por concurso

**Fecha:** 2026-08-18 · **Estado:** aprobado por el propietario · **Afecta:** sección 5, D-05

**Revierte D-05**, que decidía advertir el duplicado sin impedirlo, con el argumento de que la
secretaria tenía delante más contexto que el sistema. La decisión del propietario ahora es
literal: *cada estudiante tiene un DNI único y NUNCA debe repetirse*.

**La prueba le dio la razón antes de discutirlo.** En la base de pruebas quedó la misma
estudiante —Jimena Elizabeth Gonzáles, DNI 20000014— inscrita **cinco veces**, cada una con su
código, su carné emitido y sus S/ 15.00 confirmados: S/ 75.00 de recaudación por una sola
persona. Nadie ignoró el aviso a propósito. Con la nómina de un colegio delante, un aviso que no
frena es una línea más que se lee de pasada.

**La unicidad es por concurso, no absoluta.** `participantes` guarda una fila por concurso y por
persona, así que un `UNIQUE` solo sobre `dni` habría dejado la edición 2027 sin poder registrar a
nadie que ya compitiera en 2026. La restricción es `UNIQUE (concurso_id, dni)`.

**En la base y en la aplicación, no en uno de los dos.** La validación en PHP da el mensaje que
la secretaria puede entender —dice *quién* ocupa ese documento, con su código, para que sepa si
se equivocó de dígito o si el colegio ya lo mandó—. La restricción de la base es la que aguanta
lo que PHP no puede: dos secretarias registrando a la vez pasan las dos la misma comprobación y
solo una debe entrar.

**Tres casos que había que cubrir y no eran obvios:**

- *El duplicado dentro del mismo formulario.* Ninguna consulta a la base lo detecta —todavía no
  se ha escrito nada— y es el más frecuente de todos: la fila pegada dos veces al copiar la
  nómina del colegio. Se comprueba contra las filas ya validadas del propio lote.
- *«Corregir categoría» no se rompe.* Reutiliza el mismo participante y solo crea otra
  inscripción, así que el UNIQUE no lo toca. Se verificó antes de escribir la restricción.
- *El documento mal escrito no genera dos quejas.* El duplicado solo se comprueba si el documento
  ya pasó su propia validación de formato.

**Lo que esta regla cierra, y el propietario debe saber:** tras una anulación definitiva, ese
estudiante **ya no puede volver a registrarse** desde el formulario, porque su participante sigue
existiendo. Hoy el único camino de vuelta es «Corregir categoría». Si eso llega a hacer falta el
día del concurso, hace falta una pantalla para reinscribir a un participante existente; queda
anotado, no construido.

**Migración** en `database/migraciones/2026-08-18-documento-unico-por-participante.sql`,
idempotente, con el diagnóstico primero y **aplicada el 2026-08-18**. Se detuvo en el primer
intento, como estaba previsto, porque la base de pruebas tenía cuatro grupos duplicados —los
cinco de Jimena y tres filas de basura sobre documentos de estudiantes reales—. El propietario
autorizó borrarlos por tratarse de datos de prueba: se conservó la fila más antigua de cada grupo
y se soltaron 7 participantes con sus 8 inscripciones y 4 carnés, en orden de clave foránea y
dentro de una transacción, con respaldo completo previo de la base.

Comprobado después sobre la base real, las dos mitades de la regla: insertar el mismo documento
en el **mismo** concurso lo rechaza MySQL con el error 1062, e insertarlo en un concurso de 2027
entra sin problema. Las dos pruebas se hicieron con `ROLLBACK`, sin dejar datos detrás.

---

### D-32 — El código de seguridad de Yape vuelve a ser obligatorio

**Fecha:** 2026-08-18 · **Estado:** aprobado por el propietario · **Afecta:** D-16

**Revierte D-16.** El 17 de agosto se decidió que el código era opcional para no detener la caja
por un dato de respaldo. El propietario lo revierte: con Yape, los tres dígitos son obligatorios.

Es defendible y probablemente mejor: el código es lo único que ata un cobro a una operación
concreta en la aplicación del banco. Sin él, cuadrar la caja al final del día es la palabra de
quien cobró contra un extracto que no se puede emparejar. El costo que D-16 temía —la secretaria
sin el dato a la vista— se paga una vez, al pedirle que mire el celular antes de confirmar.

**El detalle que lo habría roto en silencio:** el campo está oculto salvo con Yape, y un campo
`required` dentro de un bloque oculto **bloquea el envío sin explicar por qué** —el navegador
intenta enfocar algo que no se ve y no muestra ningún mensaje—. Cobrar en efectivo se habría
quedado colgado sin error visible. Por eso el `required` no está en el HTML: lo pone y lo quita
el JS junto con la visibilidad, y el servidor lo exige igual aunque el JS no llegue a cargar.

---

### D-33 — El escudo vuelve a la cabecera del carné, a 6 mm y en dos columnas

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** D-27

El propietario pide devolver `public/img/logo-cociap.png` a la cabecera del carné, al
costado del nombre del concurso. Revierte en parte D-27, que lo había quitado.

**Por qué D-27 lo quitó, y por qué eso no invalida traerlo de vuelta.** Entonces el
escudo iba a 12.5 mm y **apilado encima** del texto, así que le cobraba su altura
entera al cuerpo del carné —y esa altura era justo la que necesitaban Modalidad y
Procedencia—. Maquetado **en dos columnas**, escudo y texto se reparten los mismos
milímetros en vez de sumarlos, y a 6 mm el escudo cabe dentro de lo que el bloque de
texto ya ocupaba.

**El obstáculo real no era la altura del escudo sino el ancho del titular.** Medido
con las métricas de la fuente, «IV Concurso Regional de Conocimientos COCIAP 2026»
ocupa **75.2 mm de los 80.4 mm útiles**: le sobran 5.2 mm. Cualquier escudo, aunque
midiera 3 mm, se los come y empuja el nombre a una segunda línea que cuesta 2.6 mm de
alto —y con ellos, la hoja de diez se parte en dos páginas—. Por eso el titular ahora
**se encoge lo justo para seguir en una línea** (6.4 → 6.1 pt con el nombre actual),
igual que ya hacían los apellidos y la procedencia. Ese ajuste es lo que hace que el
escudo salga gratis en altura, no el tamaño que se le dé.

**Calibrado generando hojas, no estimando.** El techo está en **6.2 mm**: a 6.4 mm la
hoja se parte. Se fija **6.0 mm** (6.0 × 5.0 mm impresos, verificado sobre las
matrices de colocación del PDF) para no trabajar al filo. A esa altura la hoja aguanta
exactamente los mismos casos que aguantaba sin escudo, y el QR conserva sus 16.5 mm:
el escudo no le quitó ni un módulo.

**Medio milímetro que costaba un carné.** La imagen es un elemento en línea y arrastra
debajo el hueco del descender de la fuente. Es invisible en pantalla, pero se multiplica
por las cinco filas de la hoja y bastaba para empujar la última a una página nueva. La
celda del escudo lleva `line-height: 0` por eso.

**Queda un aviso en el log** si algún año el nombre del concurso es tan largo que no
entra en una línea ni al cuerpo mínimo (5.4 pt): el carné se genera igual con dos
líneas, pero conviene comprobar la hoja antes de imprimir mil. Mismo criterio que el
aviso de `app.url_base` en D-25.

**La duplicación con la marca de agua es consciente.** El logo de aniversario del fondo
contiene el mismo escudo, así que ahora aparece dos veces. Al 10% de opacidad la marca
funciona como textura y no como logo, de modo que en el papel se leen como cosas
distintas; si el propietario prefiere lo contrario, la salida es bajar la marca de agua,
no encoger el escudo de la cabecera.

**La vista pública lleva el mismo escudo.** Es lo que abre el QR del papel, y el
criterio de D-27 sigue mandando: si la mesa de la puerta ve en la pantalla una
estructura distinta de la que tiene en la mano, deja de poder contrastar un dato con
el otro. Ahí no hay pelea por milímetros —es una página, no una tarjeta de 54 mm—, así
que va a 3.4 rem y se le distingue el texto del borde curvo.

**El escudo deja de estar huérfano:** D-27 lo había dejado sin uso y anotado como
pendiente de decidir. Queda resuelto.

---

### D-34 — La fila DNI / Grado / Modalidad estaba mal repartida desde D-23

**Fecha:** 2026-08-19 · **Estado:** corregido · **Afecta:** D-23, D-27

Encontrado midiendo para D-33, **es anterior al escudo y no lo causó el escudo**.
Generando hojas de diez carnés con los casos más largos que el sistema puede recibir,
**tres de cada diez partían la hoja en dos páginas con el código tal como estaba**.
Dos causas independientes, las dos con la misma firma: un valor que no cabe en su
columna salta a una segunda línea, esa línea suma altura, y con cinco filas por hoja
la última se va a una página nueva.

**1. El reparto 34 / 36 / 30 no daba el ancho que decía dar.** El comentario del código
afirmaba que cada columna tenía «el ancho justo para que no partan» los valores más
largos. Medido con las métricas de la fuente a 7.2 pt sobre los 57.7 mm de la columna
de datos, no era cierto:

| Columna | Valor más largo | Necesita | Tenía |
|---|---|---:|---:|
| DNI | `CE1234567890` (extranjería) | 21.3 mm | 19.6 mm |
| Grado | `1° Secundaria` | 20.0 mm | 20.8 mm |
| Modalidad | `Privada` | 10.9 mm | 17.3 mm |

El DNI de extranjería partía **siempre**; el grado partía en cuanto era de secundaria,
porque su margen de 0.8 mm se lo comía el relleno por defecto que Dompdf aplica a las
celdas de tabla. Pasa a **38 / 36 / 26** con `padding: 0` explícito, que recupera algo
más de milímetro y medio repartido entre las tres.

**2. El suelo del 70% dejaba la procedencia a dos milímetros de caber.** El nombre
oficial de una I.E. peruana pasa de los 70 caracteres. Al 70% del cuerpo base se
quedaba en 4.34 pt ocupando 59.8 mm de los 57.7 disponibles: dos líneas por dos
milímetros. El suelo pasa a ser **parámetro por campo** —el nombre conserva el 70%
porque es el dato que se lee a un metro en la fila de la puerta; la procedencia baja al
65%, donde entra en una línea y sigue en el mismo orden de tamaño que los rótulos de
4.6 pt del propio carné—.

**Resultado medido:** los diez casos extremos entran en una hoja, y también el peor
carné que el sistema puede producir —extranjería de 12 dígitos, `1° Secundaria`,
apellidos de 26 caracteres, nombres de 24 y una I.E. de 81— repetido diez veces. Antes
de esto entraban siete de diez.

**La lección para la próxima recalibración:** el comentario daba por medido algo que no
lo estaba, y sobrevivió a dos revisiones del carné. Las medidas del carné se comprueban
generando la hoja y contando páginas, que es barato; deducirlas leyendo el CSS es lo
que dejó el fallo dentro.

---

### D-35 — Cabecera y pie miden lo mismo y se apoyan en los extremos

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** D-33, D-23

Revisando el carné impreso, el propietario pide dos cosas: que **la cabecera y el pie
tengan el mismo tamaño y queden en los extremos** del carné, y que **el escudo sea más
grande** —a 6 mm «parece una mancha de color nada más»—. Las dos resultaron ser la
misma cosa.

**El carné pasa a repartir su altura de antemano.** Antes las tres zonas fluían una
detrás de otra desde arriba, y eso tenía dos consecuencias visibles en el papel: el pie
quedaba pegado al cuerpo, así que su distancia al canto inferior dependía de lo largo
que fuera el nombre del estudiante y dos carnés de la misma hoja no se parecían; y
cualquier dato más largo de lo previsto empujaba la altura del carné y mandaba la
última fila a otra página. Ahora cabecera y pie son franjas de **9.0 mm** apoyadas en
los extremos, y el cuerpo trabaja dentro de lo que queda.

**Los filetes se dibujan en las franjas, no bajo el texto.** Es lo que hace que las dos
líneas queden a la misma distancia de sus cantos; dibujadas en `.cab` y `.pie`, su
posición cambiaba con el alto del escudo.

**El escudo casi dobla: de 6.0 a 8.5 mm** (8.5 × 7.08 mm impresos, verificado sobre las
matrices del PDF). Y no se consiguió apretando el diseño:

- **Repartir la altura de antemano** subió el techo de la franja de 7.6 a 10.0 mm.
- **Quitar el relleno por defecto de la tabla del cuerpo** —el mismo fallo que D-34
  encontró en la fila del trío— valió por sí solo 2.4 mm de techo.
- Se descartó apretar los márgenes entre los datos: probado, solo daba 0.4 mm más, y no
  compensaba encoger el aire entre los campos que se leen en la puerta.

Se fija la franja en 9.0 mm con el techo en 10.0, y el escudo en 8.5 dentro de ella: un
milímetro de margen en la franja y medio de aire para el escudo. Medido: los diez casos
extremos entran en una hoja, y también el peor carné que el sistema puede producir.

**El titular recupera su cuerpo completo.** D-33 lo encogía a 6.1 pt para meterlo en una
línea, porque la segunda costaba 2.6 mm de altura. Con la franja fija esos milímetros ya
están pagados, así que vuelve a 6.4 pt y usa dos líneas si las necesita. **Encogerlo ya
no compraba nada.**

---

### D-36 — El pie se apoya en el canto, y deja de medir lo mismo que la cabecera

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** D-35

Con el carné impreso delante, el propietario pide que **el código y la fecha queden al
final del carné**. Estaban ya en la franja del pie, pero flotando: medido sobre el PDF,
el texto caía a **7 mm del canto inferior** con su filete 6 mm más arriba.

**La causa fue una lectura demasiado literal de D-35.** Aquella decisión igualó cabecera
y pie en 9 mm por simetría, y centró el contenido en cada franja. En la cabecera
funciona —el escudo llena la franja casi entera—, pero el pie es **una sola línea de
2.3 mm**: centrarla en 9 mm dejaba casi 7 mm de aire repartidos arriba y abajo.

**Las dos peticiones eran incompatibles y se eligió cuál manda.** Con una línea de texto
en el pie no se puede a la vez mantener las franjas iguales y apoyar el texto en el
canto: pegándolo abajo dentro de una franja de 9 mm, el filete quedaba a 10 mm del
texto. **La simetría que se percibe en el papel es la de los dos filetes enmarcando el
cuerpo, no la de dos franjas invisibles de igual altura.** El pie pasa a **4 mm** —lo
que su contenido necesita— y se alinea abajo; la cabecera sube a **11 mm** con los
milímetros liberados, y el escudo de 8.5 a **10.5 mm**.

**Tres cosas que solo aparecieron midiendo:**

1. **`height` en una celda es un mínimo, no una medida.** Dompdf repartía el sobrante
   entre las filas y engordaba la del pie, que por eso no llegaba al canto. Se resuelve
   dando altura también a la fila del cuerpo: sin sobrante que repartir, cada franja
   mide lo que dice.
2. **`position: absolute` no es una alternativa.** Se probó anclar el pie al canto y
   Dompdf directamente no lo renderiza: el pie desaparecía del carné.
3. **El carné había crecido 0.44 mm.** El modelo de caja es content-box, así que los dos
   filetes se suman por encima de la altura declarada. Se descuenta su grosor (1 pt +
   0.5 pt) del interior del marco. Verificado sobre la distancia entre guías de corte:
   **53.98 mm**, el mismo valor que el propietario comprobó con regla.

**Geometría resultante,** medida en el PDF: filete de cabecera a 13.32 mm del canto
superior, filete del pie a 5.95 mm del inferior, código y fecha apoyados a 2.89 mm del
canto. Escudo de 10.5 × 8.74 mm y QR intacto en 16.5 mm.

---

### D-37 — La I.E. organizadora inscribe a sus propios estudiantes

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** secciones 3, 5, 6 y P-04

**La situación nueva.** El COCIAP tendrá delegaciones propias: los estudiantes matriculados
este año en la I.E. que organiza el concurso. Cada tutor de sección lleva su nómina impresa a
secretaría e indica a quiénes inscribir; **la secretaria los registra uno por uno**, delante del
tutor, para que ambos queden conformes. No hay autoservicio, no hay carga de archivos y no hay
usuarios nuevos: la regla de la §3 —único canal de captura, la secretaria— sigue intacta.

**Las dos opciones que planteó el propietario, y por qué no se tomó ninguna tal cual.**

- *«Una delegación cualquiera, diferenciada por el nombre de la sección.»* Habría metido ~20
  pseudocolegios en `instituciones_educativas` —un catálogo declarado **global y compartido** en
  la §3—, con dirección, distrito y los seis campos del director repetidos y destinados a
  divergir: exactamente el problema que D-28 acababa de resolver. Y agrupar «todo el COCIAP»
  habría quedado atado a un `LIKE` sobre el nombre, que es la clase de dependencia frágil que
  D-21 sacó de la base.
- *«Una modalidad COCIAP.»* El eje es el correcto —la modalidad **sí** es lo que separa las
  bolsas—, pero el valor no puede llevar el nombre del inquilino: el modelo tiene
  `organizaciones` justamente para que el organizador sea un dato y no una constante. El valor
  almacenado es `'organizadora'`; el rótulo que ve la gente dice «COCIAP».

**La regla de competencia, que no estaba escrita en ninguna parte de este plan.** Confirmada por
el propietario: los participantes compiten dentro de su grado **y** dentro de su modalidad, con
estas bolsas por cada nivel + grado:

| Bolsa | Modalidades |
|---|---|
| 1 | privada **+** libre (juntos) |
| 2 | publica |
| 3 | organizadora |

De esta regla depende entera la Fase 5. Queda registrada aquí porque no se deducía de nada de lo
ya escrito.

**La tarifa.** S/ 10.00, igual hoy que la de una I.E. pública, y aun así **una fila aparte de
`tarifas`** por decisión expresa del propietario: la tarifa del COCIAP puede cambiar a futuro, y
reusar `'publica'` habría obligado a reclasificar el colegio anfitrión entero para moverle el
precio, arrastrando en el cambio a todos los demás colegios públicos.

**Lo que se hizo** (migración `database/migraciones/2026-08-19-modalidad-organizadora.sql`):

1. `organizaciones.institucion_id` — enlace explícito «esta organización **es** esta I.E.».
   Nulable, y no de forma provisional: una organización puede no tener estudiantes propios. Va
   aquí y no como un booleano `es_organizadora` en la I.E. porque ser anfitriona es propiedad de
   la *relación* con la organización, no del colegio; un flag en un catálogo global sería falso
   para cualquier otro inquilino en cuanto exista un segundo (misma familia que P-07).
2. `tarifas.tipo_origen` gana el valor `'organizadora'`, con su fila a S/ 10.00.
3. `inscripciones.tipo_origen` — la modalidad pasa a guardarse como **snapshot** junto al monto.
4. La I.E. anfitriona **no se siembra**: sus datos, incluido un docente delegado con DNI que
   `docente_delegado_id NOT NULL` exige, los captura la secretaria en `/instituciones`.

**La modalidad se decide y se rotula en un solo sitio.** `Concurso::modalidad()` la deriva —una
comparación de enteros contra `organizaciones.institucion_id`, nunca contra el nombre del
colegio— y `Concurso::etiquetaModalidad()` la rotula. El valor guardado y el rótulo van
separados a propósito: en la base se llama `'organizadora'` porque el esquema no puede llevar el
nombre de un inquilino, y en pantalla dice «COCIAP» porque es lo que espera quien lee el carné.
Cambiar el rótulo es una línea; cambiar el valor sería una migración.

Con eso, los cinco sitios que antes rederivaban la modalidad por su cuenta pasan a leerla:
el alta por lote, el filtro del listado, la píldora de cada fila, el carné en PDF y la vista
pública del QR. La caja de tarifa del formulario de delegación también: su `data-tipo` llevaba
el tipo del colegio, así que habría cotizado al anfitrión como pública —hoy sin diferencia
visible, porque ambas valen S/ 10, y equivocada en cuanto la tarifa organizadora se mueva, que
es justamente para lo que existe. `instituciones_educativas.tipo` deja de viajar en esas
consultas: ya no lo consume nadie, y tenerlo al lado de la modalidad congelada solo invitaba a
volver a derivarla de él.

**Comprobado.** «COCIAP» ocupa 9.56 mm en la columna de modalidad del carné, que mide 15.00 mm
—medido con las métricas de DejaVu Sans a 7.2 pt, las mismas que usa Dompdf—, prácticamente lo
mismo que «Privada» (9.51 mm), que ya se imprime. No parte en dos líneas, así que la maqueta de
D-33 a D-36 no se mueve.

**Hallazgo colateral: la modalidad podía contradecir al monto que la eligió.** `inscripciones.monto`
era snapshot desde el principio, pero la modalidad se derivaba **en vivo** de
`instituciones_educativas.tipo` cada vez que se pintaba un carné (`GeneradorCarne.php`,
`carne/publico.php`) o se filtraba el listado. Reclasificar un colegio de pública a privada
cambiaba la modalidad impresa en los carnés **ya emitidos** mientras su monto seguía diciendo
S/ 10.00 — justo lo que el comentario de `GeneradorCarne` afirmaba estar evitando. Congelar la
modalidad junto al monto lo cierra, y de paso elimina las copias de la misma derivación: el
filtro de `Inscripcion::listar()` deja de necesitar su `if/else` de dos ramas.

**Y la base no protege esa columna.** Comprobado sobre MariaDB 10.4 con `STRICT_TRANS_TABLES`
activo —el modo que fuerza D-07—: un `INSERT` que omite una columna `ENUM NOT NULL` sin default
**no se rechaza**, se rellena con el primer valor del ENUM, que aquí es `'publica'`. Es decir,
olvidarse de la modalidad en cualquier camino de alta no daría error: marcaría como pública una
inscripción privada de S/ 15.00, y el carné saldría contradiciendo a la tarifa cobrada. Por eso
`Inscripcion::crear()` valida la modalidad en PHP y lanza si no es una de las cuatro: la
garantía que el motor no da, la da la aplicación.

**Ampliación del 2026-08-19 — el papel del colegio se marca, y la interfaz dejaba de decir la
verdad.** Al ir a dar de alta el colegio anfitrión, el propietario señala que marcarlo como
«Pública» induce a pensar que cobra la tarifa de las públicas. Tenía razón, y **estaba escrito en
la pantalla**: el formulario de institución anunciaba bajo el selector de tipo «Define la tarifa:
pública S/10, privada S/15». Esa frase era cierta hasta D-37 y dejó de serlo con él.

Se evaluó darle a `instituciones_educativas.tipo` un tercer valor `'anfitrion'`. Se descartó por
cuatro razones concretas:

1. **Rompe el cobro de inmediato.** `Concurso::modalidad()` devuelve `$institucion['tipo']` para
   las delegaciones y ese valor va directo a `Concurso::tarifa()`, que buscaría una tarifa
   llamada `'anfitrion'`; la fila se llama `'organizadora'`, así que lanzaría excepción y no se
   podría inscribir a nadie. El precio permanente sería mantener dos ENUM sincronizados.
2. **Deja de ser imposible tener dos anfitriones.** Siendo una columna de `organizaciones`, marcar
   un colegio desmarca al anterior **por construcción**. Un valor de ENUM no lo impide, y entonces
   ninguna consulta sabe cuál manda.
3. **Se pierde un dato cierto.** El colegio anfitrión es de gestión pública; con el ENUM el
   catálogo dejaría de saberlo y el filtro «públicas» no lo encontraría.
4. **El catálogo es global** (§3): «anfitrión» no diría de qué concurso, y con un segundo
   inquilino el mismo colegio sería anfitrión de uno y delegación normal de otro. Es P-07 otra vez.

Lo que se hizo en su lugar:

- El selector pasa de **«Tipo»** a **«Gestión»** —el término real en el Perú— y su ayuda deja de
  hablar de tarifas. El hecho (qué clase de colegio es) se separa de la consecuencia (cuánto cobra).
- Campo nuevo y **obligatorio** en el mismo formulario: **Papel en el concurso** → «Delegación
  externa» (por defecto) o «Anfitriona — organiza el concurso». Va como campo que hay que
  responder y no como casilla que se puede pasar por alto, porque un anfitrión sin marcar cobra
  como pública y compite en la bolsa equivocada **sin ningún aviso**. Al elegir «anfitriona» se
  escribe `organizaciones.institucion_id` dentro de la misma transacción que guarda el colegio.
- En el listado, el anfitrión lleva la píldora **`ANFITRIÓN`** en ámbar en vez de la de gestión.
  El ámbar es deliberado: el anfitrión *es* público, y son justo esas dos las que hay que poder
  distinguir de un vistazo.
- Se desmarca solo si el anfitrión anterior era ese mismo colegio; sin esa condición, editar
  cualquier colegio externo —que llega con papel «externa»— le quitaría la marca al anfitrión real.
  Y como solo puede haber uno, trasladar la marca lo avisa por nombre, porque afecta a una ficha
  que quien guarda no está mirando.

**Vocabulario, confirmado por el propietario.** Son dos cosas distintas y llevan dos palabras
distintas: **«Anfitrión»** es el *papel del colegio* y solo aparece en el catálogo de
instituciones; **«COCIAP»** es la *modalidad de la inscripción* y es lo que se muestra en el
carné, en el listado y en los reportes. En la base, el valor sigue siendo `'organizadora'`.

**Consecuencia aceptada.** Al tratarse como una delegación cualquiera, el encargado registrado
para todo estudiante del colegio anfitrión es el **docente delegado de la I.E.** (su coordinador),
no el tutor de cada aula, y la sección no se guarda ni se imprime —solo el grado, confirmado por
el propietario—. Es reversible sin migración: `participantes.apoderado_id` ya es por participante.

**Pendiente de un paso manual.** Mientras `organizaciones.institucion_id` siga en NULL, ninguna
inscripción resuelve a `'organizadora'` y el sistema se comporta exactamente como antes. El
enlace se hace una sola vez, tras dar de alta la I.E. anfitriona. El paso 7 de la migración lo
recuerda cada vez que se ejecuta.

**Inconsistencia reportada (§0), ajena a esta decisión.** La base local tiene el UNIQUE de
`concursos.codigo` con el nombre `uk_concurso_codigo`, mientras que `schema.sql` lo declara en
línea y MySQL lo nombra `codigo`. Mismas columnas y mismo comportamiento; solo difiere el nombre
del índice. No se toca: renombrarlo a tres días del concurso es riesgo sin beneficio.

---

### D-38 — Reinscribir: la salida del callejón que abrió D-31 · resuelve P-06

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** secciones 6 y 7, P-06

**El callejón, verificado en el código.** Con el documento único de D-31, un participante cuya
**única** inscripción se anulaba quedaba fuera del concurso y sin salida por pantalla:

- `Participante::porDocumento()` bloquea el alta de ese DNI, sin mirar el estado, así que no se
  puede volver a registrar.
- `AnulacionController::inscripcionVigenteOFallar()` **rechaza lo anulado**, así que «Corregir
  categoría» tampoco lo recupera. La nota de P-06 decía que sí; no era exacto.
- No existía ninguna ruta de reinscripción.

La única salida era SQL a mano. **Y ya había pasado**: en la base de pruebas quedaron dos
participantes atrapados (los códigos `-0021-` y `-0031-`) antes de que nadie lo buscara.

**La acción nueva.** «Reinscribir» crea una inscripción nueva para el mismo participante. No
revive la anulada: esa se queda con su motivo, que es el rastro de lo que pasó, y el participante
conserva su correlativo, así que cualquier carné ya impreso sigue sirviendo.

- Si **había pagado** —se deduce de `fecha_pago`, que la anulación no borra, y no de
  `requiere_devolucion`—, la nueva nace `confirmada`, con su mismo medio de pago y su carné
  emitido, y el monto **sale del fondo de devoluciones**: ese dinero no se devolvió, se está
  volviendo a aplicar. Si el marcador se quedara puesto, el reporte pediría entregar un dinero ya
  gastado en esa misma inscripción y el concurso lo pagaría dos veces.
- Si no había pago, nace `pendiente`.
- El motivo, opcional, se **añade** al `motivo_anulacion` sin borrarlo: la razón por la que alguien
  quedó fuera es media historia y perderla dejaría la otra media sin sentido.

**Dónde está el freno, y por qué no es el rol.** El propietario preguntó si la acción debía ser
solo del administrador. Se decidió que **no**, y el freno es otro:

- La secretaria ya puede hacer lo *más* peligroso —anular definitivamente, que marca dinero para
  devolución— y confirmar cobros. Dejarle crear la trampa y no deshacerla convierte cada error en
  una escalada, justo durante los dos días de registro y con el tutor delante.
- Reinscribir **no puede fabricar un pago**: hereda lo que ya había. De una pendiente sale una
  pendiente. No hay forma de convertir a alguien en pagado sin pasar por el cobro.
- El freno real es que **solo aparece cuando al participante no le queda ninguna inscripción
  viva**. `Inscripcion::listar()` trae ese dato calculado (`participante_activo`) y el controlador
  lo vuelve a comprobar al guardar. Sin esa condición se podría reinscribir sobre la anulada que
  cada corrección de categoría deja detrás, y el estudiante acabaría con dos inscripciones
  activas, dos carnés y dos montos.
- El rastro queda en `inscripciones.usuario_id` y `created_at` de la fila nueva: quién reinscribió
  y cuándo.

Cambiar a solo-administrador es una línea (`Auth::exigirAdministrador()` en los dos métodos).

**Dos fallos preexistentes que salieron al escribir esto, en «Corregir categoría».** La corrección
crea la inscripción nueva heredando estado y monto, pero no heredaba `medio_pago`,
`yape_codigo_seguridad` ni `fecha_pago`, y no emitía el carné:

1. Un estudiante que había pagado quedaba «confirmada» **sin decir cómo se le cobró**. Cuadrar la
   caja al final del día dejaba de salir, y el código de seguridad de Yape —la prueba de esa
   transacción— desaparecía.
2. La inscripción corregida nacía confirmada **y sin carné**, así que el enlace «PDF» del listado
   respondía «todavía no tiene carné emitido» a alguien que ya había pagado. Había salida —el
   botón «Regenerar»— pero solo si alguien se acordaba.

Los dos van arreglados aquí, porque «Reinscribir» necesita exactamente la misma lógica y no tenía
sentido escribir el fallo dos veces.

---

### D-39 — Quién hizo qué, y una pantalla para gestionar a quién

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** secciones 5 y 7

Preguntado por el propietario antes de dar de alta a las secretarias: cómo se cambia una
contraseña, y si las acciones quedan firmadas para poder detectar al responsable de un registro
incorrecto. Las dos respuestas eran peores de lo esperado.

**No había forma de cambiar una contraseña.** Ni la secretaria ni el administrador. No existía
ruta de perfil ni pantalla de usuarios, y `scripts/crear_usuario.php` se niega si el correo ya
existe. Una credencial filtrada no se podía rotar sin entrar por SSH. Lo llamativo es que la mitad
de la plomería llevaba escrita desde la Fase 1 y **sin usar**: `Usuario::actualizarPassword()`,
`Usuario::cambiarEstado()` y `Usuario::todos()` no los llamaba nadie.

**La firma existía a medias, y faltaba justo donde importa.** Solo se firmaba *crear* la
inscripción (`inscripciones.usuario_id`). `confirmarPago()` escribía medio y fecha pero no quién
cobró; `anular()` escribía estado y motivo pero no quién anuló. Es decir: los dos actos que tocan
el dinero —cobrarlo, y mandarlo al fondo de devoluciones— **no tenían dueño**. Y el único que sí
se guardaba no se mostraba en ninguna vista: había que consultar la base a mano.

**Lo que se hizo:**

1. `inscripciones.confirmado_por` y `inscripciones.anulado_por`, nullables con clave foránea a
   `usuarios`. NULL significa «no ha pasado», no «no se sabe»: una pendiente no la ha cobrado
   nadie y la mayoría no se anulan nunca. Los métodos del modelo **exigen** el usuario en su
   firma, para que no se pueda cobrar ni anular sin decir quién.
   Las filas anteriores se quedan en NULL a propósito —16 cobros y 3 anulaciones—: rellenarlas
   con quien registró sería inventar, y una firma inventada es peor que ninguna.
2. **Columna «Responsable» en `/inscripciones`**, con el nombre de quien registró. Por decisión
   del propietario es lo único que se muestra; quién cobró y quién anuló quedan guardados pero no
   se pintan, para no ensanchar una tabla que ya tiene nueve columnas.
3. **Pantalla `/usuarios`, exclusiva del administrador**: alta, edición de nombre/correo/rol,
   cambio de contraseña y activar/desactivar. Con tres frenos: nadie puede desactivarse a sí
   mismo, no se puede dejar el sistema sin ningún administrador activo —ni desactivando ni
   degradando el rol—, y **los usuarios no se borran nunca**, porque las tres firmas apuntan aquí
   y tienen que seguir resolviendo cuando la persona ya no trabaje en el concurso.
   El formulario de contraseña va **separado** del de datos: guardar un cambio de nombre no puede
   tocarla por descuido. Y separado de verdad, no anidado — anidar formularios es el fallo que ya
   apagó la caja de cobro una vez (D-29), y se comprueba en la prueba automática.

**Sin autoservicio de contraseña, por decisión del propietario.** No hay `/perfil`: la contraseña
se asigna y se cambia solo desde `/usuarios`, por el administrador. Tampoco se obliga a cambiarla
en el primer inicio de sesión. Consecuencia asumida: una secretaria que necesite cambiarla depende
del administrador. Añadir `/perfil` después es aditivo y no toca nada de esto.

**Fuera de alcance por tiempo:** una bitácora general que firme también instituciones y apoderados.
El propietario la aplaza a después del concurso. Hoy esas dos tablas siguen sin registrar quién
las creó o editó.

---

### D-40 — Instituciones pasa a ser administrativa, y el despliegue deja de ser folclore

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** secciones 7 y 8

**El catálogo de colegios pasa a ser exclusivo del administrador.** Decisión del propietario, y
en realidad **corrige el código para que cumpla el plan**: la §3 ya decía que las funciones
administrativas incluyen «gestión de Concurso, Categorías, Tarifas, **Instituciones Educativas**,
Usuarios». El controlador era más permisivo que el documento.

Tiene sentido más allá de la jerarquía: el catálogo es **global y compartido**, y al dar de alta
un colegio se decide su gestión y su papel en el concurso, que es lo que fija **su tarifa y su
bolsa de competencia**. Un alta mal hecha no se nota en esa pantalla, se nota en el cobro de toda
una delegación.

Se cerró el controlador entero, incluida la API `/api/instituciones/buscar` —comprobado que solo
la consume el propio formulario de instituciones, no el de inscripción—. Y se taparon los tres
sitios que habrían dejado a la secretaria contra una puerta cerrada: el enlace de la barra, el
módulo del panel y, sobre todo, el «¿No está en la lista? Regístrala primero» del formulario de
delegación, que ahora le dice **a quién pedírselo**. `Apoderados` sigue siendo suya: lo necesita
para inscribir estudiantes libres.

**El tope del listado deja de cortar en silencio.** `Inscripcion::listar()` terminaba en
`LIMIT 500` sin paginación y sin avisar. Daba igual mientras las delegaciones fueran de 5 a 30,
pero con D-37 el colegio anfitrión entero cuelga de **un solo `institucion_id`**. Y la misma
consulta alimenta `/delegaciones/{id}/carnes.pdf`: la hoja habría salido incompleta y **nadie lo
habría notado hasta que faltaran carnés en la puerta**.

El tope sube a 2000 y, sobre todo, se vuelve visible: `contarFiltradas()` comparte las condiciones
con `listar()` a través de un método privado —con el WHERE duplicado, un filtro nuevo se aplicaría
en un sitio y no en el otro y el aviso mentiría—, el listado dice «se muestran N de M», y la hoja
de carnés **se niega a generarse** si la delegación pasa del tope, antes que salir incompleta.

**Y el despliegue deja de depender de que alguien recuerde los pasos:**

- `scripts/verificar_despliegue.php` — comprueba PHP y extensiones, que `depurar` esté en false y
  que `url_base` no apunte a localhost, **el esquema de la base columna por columna**, las cuatro
  tarifas y las 11 categorías, que la I.E. anfitriona esté marcada, que haya un administrador
  activo, que los assets estén minificados y que `storage/logs` sea escribible. Sale con código 1
  si algo bloquea.
  Existe por el fallo real de las pruebas: restaurar un respaldo dejó la base desfasada de las
  migraciones y el cobro se cayó con un error 1364 sin ningún aviso previo. No hay tabla de
  migraciones aplicadas, así que comprobar el esquema es la única forma de saberlo.
- `DESPLIEGUE.md` — la guía de una pasada, con lo que de verdad se olvida: que `public/build` está
  rastreado por git y hay que compilar en producción **antes de commitear**; que en una base nueva
  **no se ejecuta ninguna migración** porque `schema.sql` ya las lleva; que `url_base` es lo que
  codifica el QR y equivocarlo obliga a repartir los carnés otra vez; y el `mysqldump` al cerrar
  cada jornada, que es la única red que hay sobre el dinero cobrado.

**Lo que sigue sin cubrirse:** no hay tabla de migraciones aplicadas —el verificador la sustituye
comprobando el esquema, que resuelve el síntoma— ni respaldo automático: el `mysqldump` está
documentado pero es manual.

---

### D-41 — El sistema deja de asumir que hay un escritorio delante

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** sección 2

**El punto de partida, medido y no supuesto:** el proyecto tenía **una sola** media query en toda
la hoja de estilos, y solo encogía el escudo del carné. El `<meta viewport>` sí estaba en las tres
plantillas, así que no había zoom forzado, y varias rejillas (`minmax(230px, 1fr)`, `minmax(170px,
1fr)`) y los `flex-wrap` ya se adaptaban solos. Lo que quedaba roto era concreto:

1. **La barra de navegación no envolvía.** Con el administrador son seis enlaces; solo «Control de
   ingreso» mide unos 130 px, y sumando marca y bloque de usuario el ancho mínimo real pasaba de
   900 px. En un teléfono los enlaces se comprimían y partían su texto. Afectaba a **todas** las
   pantallas.
2. **Los listados son tablas de hasta nueve columnas** con cabeceras `nowrap`, un código
   correlativo de 22 caracteres y una celda de acciones con hasta cuatro enlaces: el ancho mínimo
   ronda los 1200 px. El `overflow-x` del contenedor evitaba que se rompiera la página —eso ya
   estaba— pero en 360 px dejaba ver menos de un tercio de la fila: para leer el estado de un
   estudiante había que arrastrar la tabla y perder de vista su nombre.
3. **Los campos de la nómina estaban en `.9rem` (14.4 px).** Safari en iPhone amplía la página al
   enfocar un campo de menos de 16 px y **no vuelve a alejarla sola**. Recorriendo treinta campos
   seguidos, eso convierte el formulario en un pulso con el navegador.
4. **Áreas de toque de ~36 px**, por debajo de los 44 que piden WCAG 2.5.5 y Apple. No es teoría:
   parte del uso es de pie en la puerta, con el teléfono en una mano.

**Lo que se hizo:**

- `base/_medios.scss` declara **tres** puntos de corte y un mixin para punteros gruesos, en `rem`
  y no en `px` para que quien suba el tamaño de letra reciba el diseño simple antes. Los anchos
  viven ahí y en ningún otro sitio, salvo el `30rem` del carné, que mide otra cosa.
- **La barra se parte en dos filas** y el menú pasa a ser una tira que se desplaza en horizontal
  con anclaje por enlace. **No se hizo un menú hamburguesa**: esconde la navegación tras un toque,
  necesita JavaScript y un estado abierto/cerrado que puede quedarse pegado, y aquí los enlaces
  son pocos y todos de uso diario.
- **Por debajo de 48rem los listados dejan de ser tablas**: cada fila es una ficha y cada celda una
  línea «rótulo → valor», con el rótulo sacado de `data-etiqueta` mediante `attr()`. La cabecera se
  oculta con `clip-path` y no con `display: none`, para que los lectores de pantalla conserven la
  relación entre celda y columna. La celda de identidad —el nombre del estudiante, del colegio, de
  la persona— va sin rótulo y encabeza la ficha: es el título, no un dato más.
- Nómina, filtros, barra de cobro, encabezados y pantallas centradas, adaptados. Los campos de la
  nómina suben a 16 px **solo en pantallas táctiles**; en escritorio siguen compactos, que es donde
  eso ayuda a ver muchas filas.
- Áreas de toque de 44 px y casillas de cobro más grandes, aplicadas por **tipo de puntero** y no
  por ancho: una tableta grande o un portátil táctil también se manejan con el dedo.
- **No se usó `overflow-x: hidden` en el body.** Eso no arregla un desborde, lo esconde, y el
  siguiente que aparezca pasaría inadvertido. En su lugar se le quita a los sospechosos —correos,
  códigos, nombres largos— la capacidad de forzar el ancho.

**Lo que NO se hizo, y por qué:**

- **No se reescribió a móvil primero.** Es lo que se estila, pero este sistema se diseñó y se probó
  en escritorio —donde la secretaria pasa el día— y darle la vuelta a toda la hoja habría cambiado
  el riesgo de sitio a tres días del concurso. Las reglas van en `max-width`, y el comentario de
  `_medios.scss` lo dice en vez de disimularlo.
- **La nómina de la delegación queda usable en un teléfono, no cómoda.** Cada estudiante es una
  ficha con sus campos apilados; registrar treinta así son treinta pantallas de desplazamiento.
  Nadie debería inscribir una delegación entera desde un teléfono, y ninguna maquetación arregla
  eso: lo que se buscaba era que **no estuviera roto** si toca corregir una fila desde el móvil.
- **Sin verificación en navegador.** El puente con Chrome no estaba conectado en esta sesión, así
  que el trabajo salió de la estructura del CSS y del HTML, no de medir píxeles en pantalla. Queda
  **pendiente de comprobación del propietario**, con la lista que se le entregó.

Cubierto por 25 comprobaciones automáticas que verifican que cada celda de cada listado lleva su
rótulo o es la celda de identidad, que ninguna lo lleva vacío, que hay tantas celdas como
cabeceras —así una columna nueva sin rótulo falla en vez de salir en blanco— y que el CSS
compilado trae de verdad los tres puntos de corte y la regla `attr(data-etiqueta)`.

---

### D-42 — Lo responsive, ahora medido: cuatro desbordes reales que D-41 no vio

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** D-41

El propietario reporta dos síntomas en `/inscripciones` al 100% de zoom: la tabla aparece pegada a
la izquierda y hay que desplazarse para verla, y **la barra superior se corta justo en la zona que
no se ve**. Son el mismo fallo: el documento era más ancho que la ventana, y la barra mide el 100%
de la VENTANA, no del documento, así que al desplazarse a la derecha se acababa.

**Y desmiente lo que yo había afirmado en D-41.** Ahí escribí que el `overflow-x` del contenedor
«evitaba que se rompiera la página — eso ya estaba». Era falso, y lo había dado por bueno sin
medirlo.

**La causa, medida:** `.contenido` es una rejilla, y **un elemento de rejilla no se encoge por
debajo de su contenido** (`min-width: auto`). La tabla de inscripciones tiene un ancho mínimo de
1176 px, así que la PISTA se estiraba hasta ahí. `.contenido` seguía midiendo sus 960 px —el
`max-width` sí se respeta— pero su pista se salía, y con ella el encabezado, las métricas y los
filtros, que se estiran a la pista. De ahí que el contenido pareciera pegado a la izquierda: el
bloque aparente medía 1176 y no 912.

**Corregido el error de método antes que el de código.** Se montó una medición real con Chrome sin
interfaz. La primera versión también mentía: `--window-size=360` **no baja de ~500 px en Windows**,
así que las medidas «de teléfono» eran de 485 px y no probaban nada. La segunda versión carga cada
pantalla **dentro de un `<iframe>` de ancho exacto**, que sí crea un viewport de verdad. Vive en
`scripts/medir_responsive.php`.

**Cuatro desbordes reales, ninguno detectado por revisión visual:**

1. **La rejilla de `.contenido`** — descrito arriba. `min-width: 0` en sus hijos, y el
   `overflow-x` de la tabla por fin hace su trabajo: se desplaza la tabla, no la página.
2. **`.aviso` era `display: flex`, y eso rompía los 22 avisos escritos a mano.** En un contenedor
   flex **cada elemento hijo es un ítem**; solo los tramos de texto suelto se agrupan. Un párrafo
   como «Cada inscripción guarda `<strong>`quién la registró`</strong>`, y cada cobro…» se
   maquetaba como **cinco columnas en fila**, con 12 px de separación metidos alrededor de cada
   `<strong>`. Medido en `/usuarios` a 320 px: caja de 293, contenido de 380. Pasa a `display:
   block`; el flex queda solo para el aviso de resultado, que sí lleva texto y aspa de cerrar y
   ahora pide `--cerrable`. **Este se veía mal en todos los anchos, también en escritorio.**
3. **El `<select>` de delegación** toma su ancho de la opción más larga, no del hueco: forzaba una
   columna de 406 px en un teléfono. `min-width: 0` en la rejilla y `width: 100%` en el campo.
4. **Las píldoras con `white-space: nowrap`** no podían encogerse dentro de la celda-ficha. Ahora
   el valor cae bajo su rótulo si no cabe.

**Y dos fallos de la propia D-41, encontrados mirando un pantallazo y no un número:** las reglas
`.tabla__principal` y `.tabla__acciones` (0,1,0) **perdían por especificidad** contra `.tabla td`
(0,1,1), así que nunca se aplicaron — la celda del nombre seguía repartiéndose a los extremos
(«Arellano Luciano … , Claudio»). Pasan a `td.tabla__principal`.

**Además, dos mejoras de UX que la medición dejó a la vista:**

- **Columna ancha para el listado.** La tabla necesita 1176 px y la columna daba 910, **igual en un
  monitor de 1920 que en uno de 1024**: la secretaria arrastraba la tabla con media pantalla vacía.
  `.contenido--ancho` (1280 px) solo en `/inscripciones` — medidas las cuatro tablas del sistema,
  las otras tres entran de sobra en 910 y ensancharlas solo dificultaría leerlas.
- **Métricas de dos en dos en el teléfono.** Por 23 px las cuatro cifras se apilaban y empujaban la
  tabla fuera de la pantalla.

**Estado:** 6 pantallas × 8 anchos (320 a 1440) = **48 medidas, ningún desborde**, más las 137
comprobaciones de `scripts/pruebas/`. Verificado además por pantallazo a 360 px y a 1366 px. Sigue
sin comprobarse en un teléfono físico.

---

### D-65 — El cierre del sitio es una línea de configuración, no un archivo en el servidor

**Fecha:** 2026-08-24 · **Estado:** implementado y probado · **Afecta:** sección 3, D-49

Terminado el concurso, el sistema tiene que dejar de estar en pie sin borrarse: los datos siguen
ahí, el código sigue desplegado, pero nadie de fuera debe poder entrar a las inscripciones ni a los
cobros. Pedido por el propietario (24-ago).

**Bloqueo total, sin puerta trasera.** Se evaluaron tres formas de dejarse una entrada —una llave
secreta por URL que dejara cookie, la sesión de administrador, una lista de IP— y el propietario
las descartó todas: si el sitio está cerrado, está cerrado también para nosotros. La consecuencia
hay que asumirla y está escrita en el código: para volver a consultar un reporte hay que reabrir el
sitio entero, y reabrirlo es un commit. Media hora abierto y otro commit para cerrarlo, si hace
falta.

**El interruptor vive en `config/config.php`, versionado.** Es la decisión que parece menor y no lo
es. El sitio se despliega con `git push` y `config.local.php` no se sube: si el interruptor viviera
en el archivo local, abrir y cerrar el sitio significaría entrar por cPanel a editar un archivo a
mano en producción, que es justo la operación que este proyecto ha evitado en todas partes. Puesto
en el archivo versionado, cerrar es cambiar `false` por `true` y empujar. El precio es que un `true`
también cierra el XAMPP de quien haga `git pull`, y por eso queda dicho en el propio comentario que
en local se anula declarándolo en `config.local.php`.

**En el front controller, no en el `.htaccess` ni en una ruta.** `public/index.php` es el único
punto por el que pasan todas las peticiones. Puesto antes de `Sesion::iniciar()`, del router y de la
primera consulta, un visitante bloqueado no abre la base de datos ni se lleva una cookie. En una ruta
habría que acordarse de cubrir las diecinueve; en el `.htaccess`, el bloqueo dependería de
mod_rewrite, que es exactamente la dependencia que la sección de seguridad del propio `.htaccess`
evita a propósito.

**503 y `no-store`, las dos por el mismo motivo.** El código es 503 «servicio no disponible» y no un
200 ni un 404: un 200 le diría a los buscadores que este aviso *es* el contenido del sitio, y un 404
que las direcciones dejaron de existir. Y la respuesta va con `Cache-Control: no-store`, que aquí no
es higiene sino necesidad: delante hay un CDN que cachea siete días (D-49). Una página de
mantenimiento guardada en esa caché seguiría cerrando el sitio después de haberlo reabierto, y esa
avería —el interruptor en `false` y el sitio cerrado igual— es mucho más difícil de diagnosticar
que la que arregla.

**La página no lleva layout.** `layouts/limpio` arranca llamando a `Sesion::tomarFlash()`, y el
cierre ocurre antes de que la sesión exista; envolverla en él obligaría a abrir sesión solo para
poder decir que no hay servicio. Es HTML completo, con la hoja compilada —que Apache sirve como
archivo estático, sin pasar por el front controller— y un puñado de reglas en línea de respaldo por
si algún día se despliega sin compilar los assets. No enlaza a ninguna parte: con el bloqueo total
no queda una sola ruta viva a la que mandar a nadie.

**Estado:** probado sobre HTTP real. Con el interruptor en `true`, ocho rutas —raíz, login, panel,
reportes, control, carné público, inscripciones y una inexistente— y un POST de login devuelven las
nueve un **503**, sin `Set-Cookie` y sin tocar la base. Con el interruptor en `false`, el sitio
responde como siempre. Las 20 pruebas de `scripts/pruebas/` siguen pasando.

---

### D-64 — Cada operación de cobro enseña de quién es

**Fecha:** 2026-08-22 · **Estado:** implementado y probado · **Afecta:** D-59, D-14

El arqueo agrupaba los cobros por operación —cuándo, quién, con qué medio, cuánto— y ahí se
quedaba: una línea que decía «S/ 350,00, 28 inscripciones» **sin decir de quiénes**. Para cuadrar
contra la nómina de una delegación o contra el extracto del banco, esa línea no sirve; hay que poder
ver los 28 nombres. Pedido por el propietario (22-ago), y visible **también en el papel**.

**Ficha y no fila de tabla.** Lo que va dentro de cada operación es otra lista, y una tabla con otra
tabla anidada en una celda no se lee ni en pantalla ni impresa. Cada operación pasa a ser una ficha:
cabecera con los datos del cobro y, debajo, una línea por participante con su código, su grado, su
procedencia y su importe.

**Una sola consulta, agrupada en PHP — y esta es la decisión que importa.**

Lo natural habría sido un `GROUP BY` para las cabeceras y un segundo `SELECT` para el detalle. Se
descartó: repetiría la clave de agrupación en dos sitios, y el día que una de las dos cambie, el
total de la cabecera dejaría de ser la suma de lo que hay debajo. En un papel que se firma al
entregar dinero, decir **S/ 350,00 arriba y listar S/ 340,00** es el peor fallo posible, porque no
revienta: se firma. Ahora **el total se calcula a partir de las mismas filas que se listan**, así
que no puede contradecirlas, y hay una prueba que recorre las 142 fichas comprobándolo.

**Lo que esto sacó a la luz, y no es menor.** Con los participantes a la vista se ve que **5
operaciones tocan más de una procedencia**, y una de ellas mezcla **14 colegios distintos** en un
solo «cobro por transferencia». La conclusión es que lo que la reconstrucción agrupa **no es una
operación bancaria: es un clic de confirmación**. La confirmación es masiva (D-14), así que una
secretaria puede seleccionar treinta inscripciones de catorce colegios que pagaron por separado y
confirmarlas todas de una vez; para Yape el código las distingue, pero transferencia y efectivo no
llevan referencia y quedan fundidas.

Eso ya estaba dicho en D-59 —«es una heurística, no un hecho»— pero era una advertencia teórica.
Ahora la ficha **avisa en el sitio**: cuando una operación toca varias procedencias, lo dice encima
de la lista. Es la diferencia entre documentar una limitación y enseñarla donde estorba.

**Detalles que no son obvios:**

1. **Orden alfabético dentro de la ficha**, con la colación española. No es estético: es el orden en
   el que se cotejan los nombres contra la lista que trae la delegación.
2. **`inscripciones` pasó a llamarse `cantidad`**, y `participantes` es la lista. Tener una clave
   llamada «inscripciones» que era un número, al lado de un array de inscripciones, era pedir un
   error.
3. **El momento de la operación sigue siendo el más antiguo del grupo**, como hacía el
   `MIN(fecha_pago)` de antes.

**Impresión: `break-inside: avoid` NO va en la ficha entera.** Una operación de treinta inscritos no
cabe en media hoja y forzarla dejaría páginas casi vacías. Lo que sí se impide es que **la cabecera
se quede huérfana** al pie de una página —«21/08 16:50 · Tatiana · S/ 300,00» sin nadie debajo— y
que la línea de un participante se parta por la mitad.

**Medido:** 142 fichas y 804 líneas de participante en **43 ms** y 688 KB, con 8 MB de pico. Es la
vista del administrador, que ve las tres cajas; la de una secretaria es una fracción de eso.

**Comprobado.** 9 comprobaciones nuevas (suite contable: **134**): que la ficha lista exactamente a
los participantes de su operación, que **ninguna ficha discrepa de sus propios participantes**, que
todas las operaciones juntas dan el cobrado bruto, que hay una ficha por operación y una línea por
persona en el HTML, y que una operación de un solo colegio no se marca como mezclada.

Una de ellas nació mal y conviene dejarlo escrito: comparaba los participantes **como lista
ordenada** contra los ids creados. Falló, y con razón — dentro de la ficha el orden es alfabético, y
los dos casos de prueba se llaman igual, así que su desempate es arbitrario. Afirmaba algo que el
código no promete. Se corrigió comparando conjuntos. Total del proyecto: **20 pruebas, 467
comprobaciones**.

---

### D-63 — La hora de Ancash, en todo el sistema y en los dos entornos

**Fecha:** 2026-08-22 · **Estado:** implementado y probado · **Afecta:** D-62, D-53, `Core\Database`

D-62 corrigió la hora **en los reportes contables**. El propietario pidió lo evidente: que la fecha
y hora reales se vean **en todas partes**. Al hacerlo apareció que el problema era más ancho de lo
que D-62 describía.

**La base mezcla dos tipos que MySQL trata de forma distinta.**

| Columna | Tipo | Qué hace MySQL al leer |
|---|---|---|
| `inscripciones.fecha_pago` | `DATETIME` | La entrega **literal**, sin tocarla |
| `created_at`, `updated_at`, `generado_en` | `TIMESTAMP` | La **convierte a la zona de quien lee** |

Con el servidor en UTC y la máquina de desarrollo en hora de Lima, eso significaba que **los mismos
datos daban horas distintas en cada entorno**, y que aplicar una única corrección a las dos habría
arreglado una y roto la otra. Peor: `created_at` se veía **bien en local y cinco horas adelantado en
producción**, que es exactamente la clase de fallo que este proyecto no puede permitirse — allí los
errores no se ven.

**La solución es fijar la zona de la sesión, no parchear cada columna.** `Core\Database` ejecuta
ahora `SET SESSION time_zone = '+00:00'` junto al `sql_mode`. A partir de ahí **todo instante que
sale de la base está en UTC, en cualquier máquina**, y `Core\Fecha` lo pasa a hora de Ancash al
mostrarlo. Una sola regla en lugar de una por tipo de columna, y la hora que se ve en desarrollo es
la que se verá en el servidor.

**No cambia ningún dato**, y conviene entender por qué: `TIMESTAMP` ya se almacena internamente en
UTC —la zona de sesión solo decide cómo se presenta— y `DATETIME` se entrega tal cual esté escrito.
No se reescribe una sola fila.

`'+00:00'` y no `'UTC'`: el nombre exige que las tablas de zonas horarias estén cargadas en el
servidor, y en un hosting compartido no siempre lo están. El desplazamiento numérico funciona
siempre.

**Se comprobó antes de tocarlo** que ninguna consulta compara contra `NOW()` ni `CURDATE()` para
decidir nada de negocio: las únicas apariciones son al **escribir** la fecha de un cobro y la de un
carné. Si hubiera habido un `WHERE fecha <= CURDATE()`, cambiar la zona de la sesión habría movido
resultados en silencio.

**Un día de calendario NO es un instante, y ahora está escrito en el código.** `Core\Fecha` expone
dos métodos y la diferencia es deliberada:

- `mostrar()` — para `DATETIME` y `TIMESTAMP`. **Convierte.**
- `dia()` — para las columnas `DATE`: fecha del evento, cierre de inscripción. **No convierte.**

El concurso es el sábado 22 en Ancash y en Tokio; es un día, no un momento. Pasarlo por la
conversión lo movería al **21 a las 19:00**, y ese día sale impreso en el carné y en el acta. Son
dos métodos y no uno con un parámetro para que la próxima persona que vea un `date()` junto a un
`Fecha::mostrar()` no «arregle» el que no tocaba.

**Y dos ayudantes más, por la misma razón de fondo.** `Fecha::ahora()` y `Fecha::hoy()` no dependen
del `php.ini`: `date()` a secas usa la zona por defecto de PHP, que la aplicación fija en
`public/index.php` pero **un script de consola no**, y que en el servidor puede venir en UTC. Un
documento que se firma no puede llevar una hora de emisión que dependa de por dónde se lanzó, y la
cuenta atrás del panel con un PHP en UTC diría «ya pasó» desde las siete de la tarde de la víspera —
la misma familia de fallo que D-53 corrigió con «faltan 1 día».

**Lo que se tocó**, todo de presentación: `Core\Database` (la sesión), `Core\Fecha` (`dia`, `ahora`,
`hoy`), el historial de correcciones, la vista pública del carné, `GeneradorActa`, `GeneradorCarne`,
la cabecera de identidad de los reportes, la cuenta atrás del panel, el nombre del ZIP de actas y la
tabla por día de la rendición.

**Comprobado.** Cinco comprobaciones nuevas (suite contable: **125**), y la más útil no mira una
fecha sino el código: **recorre `app/` y `core/` y falla si alguna fecha se pinta fuera de
`Core\Fecha`** —descontando los comentarios, que citan `date()` para explicar el problema—. Las
otras cuatro: que la sesión habla UTC, que los dos tipos de columna se leen ya en la misma zona, que
un día de calendario no se mueve, y que sigue sin moverse **aunque el desplazamiento no sea cero**,
que es cuando el fallo aparecería. Total del proyecto: **20 pruebas, 458 comprobaciones**.

**Confirmado en producción por el propietario el 23-ago-2026: la hora sale correcta.** Quedaba la
única comprobación que no se podía hacer desde local —mirar que la hora de un cobro conocido
coincida con la real—, porque todo lo anterior se apoyaba en una inferencia: 803 filas con un
desfase de exactamente 18 000 segundos. La inferencia era sólida, pero era inferencia; ahora es un
hecho comprobado, y con ello **`app.zona_datos = 'UTC'` queda validado contra el servidor real**.

---

### D-62 — La rendición de cuentas: los sobre registros se declaran, no se corrigen

**Fecha:** 2026-08-22 · **Estado:** implementado y probado · **Afecta:** D-59, D-60, D-61, Fase 5 (§8)

Concurso terminado. El propietario fija dos condiciones: **no se cambia ningún dato** y hay que
resolver los **sobre registros** dentro de una rendición contable profesional. Todo lo que sigue es
de solo lectura: `GET /reportes/rendicion`, **solo administrador**.

---

**Primero, un error mío que conviene dejar escrito porque casi cuesta una decisión equivocada.**

Al auditar los datos informé de que **805 filas pagadas tenían `fecha_pago` posterior a su propia
`updated_at`**, que la aplicación no puede producir, y concluí que los datos de dinero eran
fabricados. Estuvo mal. La causa es de tipos: **`fecha_pago` es `DATETIME` y se guarda literal;
`created_at` y `updated_at` son `TIMESTAMP` y MySQL los convierte al leer**. El volcado se escribió
en un servidor con MySQL en UTC y se estaba leyendo en una máquina con MySQL en hora de Lima, así
que los `DATETIME` conservaron su valor y los `TIMESTAMP` se recalcularon.

Lo que lo demostró: **803 de las 805 filas dan exactamente 18 000 segundos de desfase**, y las dos
restantes se modificaron después del cobro por otra razón. Un desfase constante y redondo no es una
falsificación, es una zona horaria. Los datos son reales y completos.

---

**El hallazgo verdadero, que sí afecta a la rendición.** Las horas de pago están **cinco horas
adelantadas** respecto de la hora de Ancash, y eso no es cosmético:

> **191 cobros, S/ 1 965,00, están archivados con fecha del sábado 22 y se cobraron el viernes 21
> por la noche.** En un cierre por día, esa es la diferencia entre cuadrar y no cuadrar.

Con la hora corregida, el cierre real del concurso es:

| Día (hora de Ancash) | Cobros | Importe |
|---|---:|---:|
| Jueves 20 | 19 | S/ 215,00 |
| Viernes 21 | 437 | S/ 5 215,00 |
| Sábado 22 | 348 | S/ 3 815,00 |

**Se corrige AL LEER, nunca tocando los datos** (`Core\Fecha`, `app.zona_datos` en configuración).
Reescribir 805 fechas de pago es reescribir el libro de caja, y un libro que se reescribe deja de
ser prueba de nada. El dato guardado es correcto en su zona; lo que faltaba era decir en cuál está.

**Por qué la zona de los datos es configuración y no se detecta sola.** Se intentó deducirla
comparando el `NOW()` de MySQL con la hora de PHP: no sirve. Eso dice en qué zona escribe el
servidor **de ahora**, no aquel en el que se escribieron las filas — y este volcado, creado en UTC y
leído en una máquina en hora de Lima, es justo el caso en el que la detección respondería «cero» y
dejaría el error intacto. Es una propiedad **del volcado**, no del servidor que lo lee.

---

**Los sobre registros: tres casos, y tres tratamientos distintos.** Se buscaron por tres vías
independientes —pagos escritos dos veces, nombres repetidos, y apellidos + colegio + grado
repetidos— y no hay más.

| | Caso | Importe | Tratamiento |
|---|---|---|---|
| I.1 | **Cobro duplicado**: misma persona, mismo colegio, mismo grado, cobrada dos veces | S/ 10,00 | **Se descuenta** del ingreso |
| I.2 | **Homónimos**: mismo nombre, distinta procedencia | — | Se declara, no se descuenta |
| I.3 | **Un pago en dos filas**: la copia que deja la reinscripción | S/ 15,00 de riesgo | Se declara; el dinero entró una vez |

**El umbral de I.1 es deliberado y está en el código.** Dos personas con el mismo nombre completo
pueden existir; dos con el mismo nombre **en el mismo colegio y el mismo grado** son la misma
persona registrada dos veces. Solo ese caso se descuenta. El resto se declara para que lo juzgue
una persona, que es lo que corresponde cuando hay que decidir si a un niño se le cobró dos veces.

**Nada de esto se corrige en la base, y es la decisión de fondo del documento.** Un registro
contable no se arregla borrando: se arregla revelando. El volcado del concurso es prueba de lo que
ocurrió, y el anexo explica cada diferencia entre las filas, las personas y los soles.

Por decisión del propietario (22-ago), el cobro duplicado se declara **cobro indebido pendiente de
devolución**: ese dinero no es del concurso. De ahí la cadena de conciliación del documento:

```
809  inscripciones registradas
 −5  anuladas
804  confirmadas y cobradas          →  S/ 9 245,00  recaudado bruto
 −1  competidor duplicado            →  S/     10,00 cobro indebido
803  competidores efectivos          →  S/ 9 235,00  ingreso legítimo
```

**La propiedad que hace defendible el documento, y que hay una prueba vigilando:** añadir una
persona duplicada sube el bruto y sube lo indebido **en el mismo importe**, así que el ingreso
legítimo no se mueve. Cobrar dos veces a la misma persona no le añade un sol al concurso.

**El padrón nominal completo va dentro** (una fila por inscripción, incluidas las anuladas), por
decisión del propietario: una rendición que solo lista a quien pagó no permite rastrear las bajas,
que es lo primero que alguien va a querer comprobar.

---

**Se retira la propuesta de la tabla `pagos`.** En el análisis previo recomendé un libro de pagos
con migración en cuatro pasos. **Con el concurso cerrado y sin tocar datos, ya no procede**: su
valor era impedir duplicaciones futuras, y no habrá más cobros. La duplicación es ahora un hecho
histórico en **una** fila, identificada, excluida de los totales y declarada en el anexo. Montar esa
migración sería asumir el riesgo de tocar la tabla del dinero para prevenir algo que no puede
volver a ocurrir en este concurso. **Queda para el COCIAP 2027**, que es donde sirve, junto con
`fecha_anulacion` y el registro de la devolución efectuada.

---

**Una discrepancia entre la documentación y los datos, reportada como pide el §0.**
`PENDIENTE.md` afirma que el 21-ago se intercambiaron por consola los documentos de los
participantes 20 y 21, dejando el bueno (`…439`) en el registro vivo. **Los datos dicen lo
contrario**: el `…439` está en el participante 20, que es el anulado, y quien compitió lleva el
`…438`. O se revirtió —el propio documento cuenta que la primera vez faltó el `COMMIT`— o nunca
llegó a aplicarse. **El estudiante que compitió está registrado con el documento equivocado**, y eso
hay que decidirlo antes de emitir cualquier constancia a su nombre. No se tocó nada.

---

**Comprobado.** 30 comprobaciones nuevas (secciones 13 a 15), que suben la suite contable a **120**.
Las tres que importan:

- **PHP y SQL convierten la hora igual**, también en el cobro más antiguo y en el más reciente. Si
  divergieran, el día por el que se agrupa la recaudación no sería el que se imprime al lado y el
  documento se contradiría sin que nada fallara.
- **Un cobro de las nueve de la noche del viernes se filtra por su día real** y no por el día con el
  que quedó guardado. Se reproduce con una fecha fijada a mano y no con el reloj, para que la prueba
  diga lo mismo un martes a las tres.
- **Los cuatro desgloses cuadran con el bruto**, antes y después de añadir un duplicado.

Total del proyecto: **20 pruebas, 453 comprobaciones**, todas en verde.

---

### D-61 — La grilla de cobros: todas las inscripciones, en el orden del dinero

**Fecha:** 2026-08-22 · **Estado:** implementado y probado · **Afecta:** D-59, D-18, D-48, Fase 5 (§8)

Pedido por el propietario: **ver TODAS las inscripciones** con su estado, quién confirmó el pago,
con qué medio, el código de verificación cuando es Yape, y la fecha y hora de la confirmación —
**ordenadas por esa fecha**. `GET /reportes/cobros`, **solo administrador**.

**Por qué es una pantalla nueva y no seis columnas más en `/inscripciones`.** Son dos documentos
con dos públicos y **dos órdenes incompatibles**: el listado de trabajo va en orden de nómina
—apellido paterno, materno, nombres, con la colación española (D-18)— porque se usa para encontrar
a una persona; este va en orden de reloj, porque se usa para reconstruir qué pasó con el dinero.
Un solo listado con las dos cosas obligaría a elegir un orden y estropear el otro uso, y arrastraría
además la columna de acciones y la casilla de cobro (D-48) a una pantalla donde no se opera nada.

**El orden, entero y decidido.** Pagadas primero, de la más reciente a la más antigua; **lo no
cobrado al final**. Esa segunda mitad es tan parte del requisito como la primera: `fecha_pago` es
`NULL` en toda pendiente, y ordenar por una columna nulable sin decir dónde caen los nulos deja ese
trozo del listado al criterio del motor — que puede cambiar con la versión. Va explícito en el SQL
(`ORDER BY (i.fecha_pago IS NULL) ASC, i.fecha_pago DESC, i.id DESC`) y hay una prueba que recorre
la lista entera comprobando que ninguna pagada aparece después de una sin cobrar.

**Aquí NO se suma dinero, y es la decisión importante de esta pantalla.**

La grilla enseña **filas crudas**, una por inscripción. Por D-59 sabemos que una reinscripción deja
el mismo pago escrito en dos filas —la anulada conserva el suyo y la nueva lo copia—, así que
**sumar esta lista cobraría dos veces al mismo estudiante**. Poner un total al pie habría sido
regalar una cifra que contradice a `/reportes/saldos`, y con dos cifras distintas en pantalla la que
se cree es la que uno tiene delante.

En vez de eso, cada fila trae `pago_contado`: vale 1 en la fila que los reportes de dinero cuentan y
0 en la copia, que sale marcada **«ya contado»**. Un aviso arriba dice cuántas hay y por qué, y solo
aparece si las hay — una advertencia permanente deja de leerse. Los totales se piden donde
corresponde, con un enlace a Estado de la caja.

Eso obligó a un refactor pequeño y necesario: la regla de «qué fila cuenta» estaba dentro de
`DESDE_COBROS_VIGENTES`, y ahora vive suelta en `Inscripcion::FILA_DE_PAGO_VIGENTE`, que usan los
dos. **Una sola copia**: si la grilla la reimplementara, marcaría como contadas filas que el saldo
no cuenta, y las dos pantallas se contradirían sin que nadie lo notara.

**Los filtros salen de la misma `condiciones()` que el listado.** Se le añadieron cuatro claves
—`medio_pago`, `confirmado_por`, `desde`, `hasta`— y ninguna la envía `/inscripciones`, así que allí
no cambian nada. Van en esa función y no en una paralela por lo mismo que dice su propio comentario:
es donde un filtro se convierte en SQL, y con dos funciones `contarFiltradas()` contaría con unas
condiciones y la grilla pintaría con otras, con lo que el aviso de «hay más filas» mentiría. Hay una
prueba que compara las dos cuentas con el mismo filtro.

Cuatro detalles que no son obvios:

1. **`FILTROS_COBROS` es una lista aparte de `FILTROS`.** `FILTROS` es lo que `urlListado()` acepta
   al reconstruir la vuelta al listado tras un cobro fallido (D-48); esa pantalla no sabe nada de
   estas cuatro claves y no tiene por qué empezar a aceptarlas.
2. **`hasta` se compara contra el final del día.** `fecha_pago` es `DATETIME`: un `<= '2026-08-22'`
   dejaría fuera todo lo cobrado ese día después de medianoche, es decir, todo.
3. **Dos valores que no son datos:** `medio_pago = sin_cobrar` busca `IS NULL`, y
   `confirmado_por = sin_firma` busca los cobros sin firmar de antes de D-39. Se resuelven en
   `condiciones()` y nunca viajan como parámetro, porque no son un medio ni un id de usuario.
4. **El desplegable de cobradores lista a TODOS los usuarios, no solo a los activos.** Quien cobró
   en julio puede estar desactivado hoy, y sin su nombre en la lista sus cobros no se podrían
   filtrar. Es la misma razón por la que los usuarios se desactivan y no se borran.

**Solo administrador.** Enseña de una vez quién cobró cada inscripción y **el código de Yape de
todas**, que es justo lo que D-59 reservó a las filas propias cuando mira una secretaria. Cada una
sigue teniendo su arqueo con lo suyo. Si algún día se abre a secretaría, el cambio es una línea en
el controlador — y habría que decidir antes qué se hace con los códigos ajenos.

**Medido, no supuesto** (base local, 809 filas —que incluyen las generadas, ver `PENDIENTE.md`—):
consulta **15 ms**, render **17 ms**, **1,6 MB** de HTML y 12 MB de pico. En producción, con unas
115 inscripciones, la página baja a ~230 KB. El `TOPE_LISTADO` de 2000 se aplica igual que en el
listado, con el mismo aviso de «hay más» apoyado en `contarFiltradas()`: cortar en silencio en una
pantalla de auditoría sería peor aquí que en ninguna otra.

**Comprobado:** 28 comprobaciones nuevas en `scripts/pruebas/reportes-contables.php` (secciones 10 a
12), que suben esa suite a **90**. Las que importan: que salen los tres estados y no solo lo
cobrado; que **el orden se cumple en toda la lista**, nulos incluidos; que la copia de una
reinscripción va marcada y la fila viva no; que cada filtro deja solo lo suyo; que la grilla y el
contador aplican el mismo filtro; y que a la secretaria **no se le ofrece siquiera el enlace**.
Total del proyecto: **20 pruebas, 423 comprobaciones**, todas en verde.

---

### D-60 — La firma del cobro sobrevive a la reinscripción

**Fecha:** 2026-08-22 · **Estado:** implementado y probado · **Afecta:** D-38, D-39, D-59

**El defecto, verificado en el código.** `Inscripcion::crear()` (`app/Models/Inscripcion.php:107-128`)
inserta nueve columnas y **`confirmado_por` no es ninguna de ellas**. Al reinscribir a quien ya
había pagado, la fila nueva nace `estado = 'confirmada'` con `medio_pago`, `fecha_pago` y el código
de Yape copiados —eso sí se cuidó— pero **sin firma**. El resultado es un cobro confirmado que no
tiene dueño, que es exactamente la situación que D-39 vino a cerrar; la reinscripción la
reintroduce en silencio.

No es histórico: **se está generando hoy**. Los `NULL` anteriores a D-39 son otra cosa —el retrato
de lo que pasó antes de que el sistema supiera registrarlo— y esos se quedan como están, por el
mismo motivo que dice aquella migración: *una firma inventada es peor que ninguna*.

**Se arrastra la firma del cobrador ORIGINAL, no la de quien reinscribe.** El arqueo cuenta dinero
recibido, y esa plata la recibió el primero; atribuírsela a quien reinscribe movería un importe
entre dos cajas que nunca lo tocaron. Quién reinscribió no se pierde: queda en `usuario_id` de la
fila nueva, que es la firma de quien registra (D-39).

**Alcance exacto, para que no crezca.** De los tres sitios que llaman a `Inscripcion::crear()`, solo
uno crea filas ya pagadas: `AnulacionController.php:191`. Los otros dos —`InscripcionController.php:264`
y `:458`— nacen pendientes y no tienen firma de cobro que arrastrar. El cambio es aceptar
`confirmado_por` en `crear()` y pasarlo allí. **Cero migraciones**: la columna existe desde D-39.

**Comprobado** en `scripts/pruebas/reportes-contables.php` (caso 4) y no en `firmas-y-usuarios.php`,
como se había previsto: la firma solo se puede leer sobre el caso completo de reinscripción, que es
el que esa suite monta. Seis comprobaciones, y **dos son de distinta naturaleza a propósito**:

- Que el modelo **sabe** guardar la firma, y que sin cobro no se la inventa (queda `NULL`).
- Que el controlador **se la pasa**, leído sobre la fuente de `reinscribir()`. Esta segunda hace
  falta porque el defecto no estaba en el modelo sino en el cableado: una prueba que solo llamara a
  `crear()` con la firma puesta pasaría en verde con el defecto intacto — exactamente el tipo de
  prueba que no puede fallar que D-55 vino a erradicar.

Sin esto, el arqueo de D-59 empezaría a acumular cobros sin dueño el mismo día que se publica.

---

### D-59 — Los reportes contables, y la regla de contar el dinero una sola vez

**Fecha:** 2026-08-22 · **Estado:** implementado y probado · **Afecta:** Fase 5 (§8), flujo 7 (§6), D-14, D-15, D-38, D-56

El acta de los jurados no lleva **ni un dato de dinero**, y es deliberado (D-56 §4). Éste es el
otro reporte, el de dirección: el que responde **cuánto entró, por qué medio, en manos de quién y
qué falta por cobrar**. Tres pantallas, todas de solo lectura y **cero migraciones**:

| Pantalla | Qué contesta |
|---|---|
| `/reportes/caja` | Arqueo: cuánto recibió cada usuario, desglosado por medio de pago |
| `/reportes/saldos` | Las cinco líneas del saldo, cuadradas contra la caja física |
| `/reportes/devoluciones` | El fondo de devoluciones, que ya calcula `Inscripcion::fondoDevoluciones()` y llevaba desde la Fase 5 sin vista ni ruta |

**Cuatro decisiones del propietario (22-ago), preguntadas y no supuestas:**

1. **Pantalla imprimible antes que Excel.** Lo que hace falta esta noche es cerrar caja, y una
   pantalla no depende de que PhpSpreadsheet esté instalado en el servidor — que a día de hoy
   **sigue sin confirmarse** (D-56, último párrafo). El `.xlsx` se añade después, sobre los mismos
   cálculos y sin reimplementarlos.
2. **El administrador ve las tres cajas; cada secretaria ve solo la suya.** Es la línea de D-52
   —cada quien opera sus propios registros— aplicada al dinero, y no inventa una excepción nueva:
   el cierre de caja es el papel con el que se entrega lo recaudado, así que quien lo entrega tiene
   que poder imprimirlo sin pedírselo a nadie. El código de seguridad de Yape aparece **solo en las
   filas propias**, y para el administrador en todas: es la única llave de conciliación que existe.
   · Derivado al implementarlo: **el arqueo es la única de las tres pantallas que ve la secretaria**.
   `/reportes/saldos` y `/reportes/devoluciones` exigen administrador —el reparto del dinero del
   concurso entero y lo que hay que devolver son de dirección, y anular ya es exclusivo suyo por
   D-51—. Por eso la barra lleva **un solo enlace**, «Caja», y las otras dos se alcanzan desde
   dentro: una puerta que va a dar 403 no se le enseña a nadie, igual que con Instituciones.
3. **El dinero en limbo tiene línea propia**, no entra al fondo de devoluciones. Ver más abajo.
4. **La firma del cobro se arregla antes de emitir el primer arqueo** → D-60.

**La regla de contar, que es lo que hace contable a este reporte.**

`Inscripcion::resumen()` cuenta como recaudado solo `estado = 'confirmada'`. **Eso no cuadra con
el cajón**: el dinero de una inscripción cobrada y luego anulada desaparece del recaudado, pero
sigue físicamente en poder de la organización hasta que alguien lo devuelva. Y sumar «todo lo que
tenga `fecha_pago`» es peor todavía, porque **cobra dos veces al mismo estudiante**: al reinscribir
(`app/Controllers/AnulacionController.php:186-207`) la fila nueva **copia** `medio_pago`,
`fecha_pago` y `yape_codigo_seguridad`, y la anulada **conserva los suyos**. El mismo S/ 10.00
está escrito en dos filas a propósito, para que la nueva sepa cómo se cobró.

El discriminador correcto no es el estado ni el marcador de devolución, es **si el participante
tiene todavía una inscripción viva**:

```
Cobrado bruto   = confirmadas con fecha_pago
                + anuladas pagadas cuyo participante NO tiene inscripción viva
(-) Devoluciones efectuadas                              ← HOY NO SE REGISTRA (ver abajo)
(=) En poder de la organización
Por cobrar      = pendientes
```

y ese segundo sumando se parte en dos líneas que no significan lo mismo:

- **Por devolver** (`requiere_devolucion = 1`): anulación definitiva de algo ya pagado. Es el fondo.
- **Cobrado pendiente de reasignar**: anulada *para reinscribir* que todavía no se reinscribió.
  `Inscripcion::anular()` pone `requiere_devolucion = esDefinitiva && estado === 'confirmada'`
  (`app/Models/Inscripcion.php:538`), así que estas quedan en 0 y **hoy no salen en ningún sitio**:
  ni en recaudado, ni en el fondo. Es el hueco entre los dos botones de D-15.

La anulada pagada **con** inscripción viva es la reinscrita, y se excluye: su dinero ya está contado
en la fila confirmada.

**Corrección hecha al implementarlo, y merece quedar escrita.** La primera versión de esta regla
era un `NOT EXISTS` —el mismo que sostiene la columna `puede_reinscribir` de `Inscripcion::listar()`
(`:189`)— sobre «anuladas sin ninguna hermana viva». **Se rompe con la cadena larga**: pagó, se
anuló, se reinscribió, y esa segunda se anuló definitivamente. Entonces quedan **dos** anuladas
pagadas y ninguna viva, y el mismo importe se sumaría dos veces. No es hipotético: cada
reinscripción deja una anulada pagada detrás, y basta con anular después la fila buena.

Lo que se implementó es más fuerte y no depende de la longitud de la cadena: **una fila por
participante**. De todas las filas pagadas de una persona se elige la viva si la tiene y, si no, la
más reciente; el destino de ese dinero lo decide esa fila. Vive en una sola constante,
`Inscripcion::DESDE_COBROS_VIGENTES`, que usan los tres reportes — y de ahí sale gratis que el
arqueo y el saldo cuadren entre sí, en vez de cuadrar por casualidad.

**Por qué NO se toca `anular()` para meter el limbo en el fondo.** Ese dinero no se devuelve: está
esperando la reinscripción, y `limpiarDevolucion()` (`:595`) existe precisamente para que el reporte
no pida entregar una plata que la secretaria va a reutilizar — el concurso la pagaría dos veces. Lo
que falta no es marcarlo como devolución, es **verlo**; y para verlo basta con leer.

**Por qué NO se crea la tabla `pagos` hoy.** Es la deuda estructural de verdad: la confirmación es
masiva (D-14), así que un Yape de S/ 300 por treinta estudiantes escribe **el mismo código de tres
dígitos en treinta filas**, y la reinscripción lo copia a filas de otra fecha. **No existe la
entidad «operación de cobro»**, solo una marca repetida, y el arqueo tiene que reconstruirla
agrupando por `(confirmado_por, medio_pago, yape_codigo_seguridad, minuto de fecha_pago)` — que es
una heurística, no un hecho. Hoy es el día del concurso, con cobros entrando por la puerta: una
migración sobre la tabla que guarda el dinero no se hace hoy. Queda anotada para después.

**Lo que este reporte NO puede decir, y hay que saberlo antes de firmarlo:**

- **Devoluciones efectuadas.** El sistema sabe decir «se debe»; nunca «se devolvió, cuándo, por qué
  medio y quién firmó». `limpiarDevolucion()` borra el marcador sin dejar rastro. La línea existe
  en el saldo, en cero y rotulada como no registrada, para que el cuadre no mienta por omisión.
- **La fecha de anulación no existe.** `fondoDevoluciones()` selecciona `i.updated_at` (`:660`) como
  si lo fuera, y cualquier `UPDATE` posterior —`anotarEnAnulacion()`, `limpiarDevolucion()`,
  `cambiarProcedencia()`— la mueve. En la pantalla se rotula «última modificación», no «fecha de
  anulación», hasta que exista la columna.
- **Transferencia y efectivo no llevan ninguna referencia externa**, así que solo se concilian por
  importe y fecha. Solo Yape tiene llave, y de tres dígitos.

**Falta medir contra la base de PRODUCCIÓN, y no se da por bueno hasta hacerlo.** La local no puede
responderlo: al medir salieron **312 inscripciones con `fecha_pago` en el futuro** —hasta las 15:56
de un día en el que eran las 12:35— y 808 participantes donde `PENDIENTE.md` registraba 113
confirmadas. Son filas generadas, probablemente las de la medición de rendimiento de D-57, que
nunca se limpiaron. **Ninguna cifra de dinero sacada de la base local es real**, y por eso aquí no
se anota ninguna. Lo que sí vale de esa corrida es que el mecanismo funciona y que el cuadre cierra
sobre 809 filas. En el servidor, con sesión de administrador, la pantalla `/reportes/saldos` da la
respuesta directamente; por consola:

```sql
SELECT SUM(i.estado='confirmada' AND i.confirmado_por IS NULL)  AS cobros_sin_firma,
       SUM(i.estado='anulada'    AND i.fecha_pago IS NOT NULL
           AND i.requiere_devolucion = 0)                       AS anuladas_pagadas_sin_marcador,
       SUM(i.requiere_devolucion)                               AS por_devolver
  FROM inscripciones i JOIN participantes p ON p.id = i.participante_id
 WHERE p.concurso_id = 1;
```

De `anuladas_pagadas_sin_marcador`, las que tengan inscripción viva son reinscripciones correctas;
las que no, son el limbo.

**Cómo se comprueba.** `scripts/pruebas/reportes-contables.php`, **62 comprobaciones**, con el
patrón de D-55: la suite **crea su propio caso** dentro de la transacción que `_comun.php` revierte,
en vez de buscar filas reales que mañana pueden no existir. Y **mide por diferencia** contra el
estado real de la base, nunca contra cifras absolutas: cualquier número escrito a mano aquí
caducaría con el cobro siguiente.

Lo que de verdad vigila, y que una prueba de «el total sale» no vería:

- Que **una reinscripción pagada suma una sola vez**. Se monta la cadena entera —cobrar, anular
  para reinscribir, recrear copiando el pago— y se comprueba que las dos filas tienen `fecha_pago`
  y que aun así el bruto no se mueve.
- Que **la anulada en limbo aparece en su línea** y no en el fondo de devoluciones.
- Que **el cuadre cierra**: la suma de los tres medios da el total de cada cobrador, y la suma de
  todos los cobradores da el cobrado bruto del saldo.
- Que **un cobro masivo se reconstruye como una sola operación**. Las dos filas se escriben con la
  misma `fecha_pago` fijada a mano y no con la del reloj: si no, la prueba fallaría sola cuando la
  corrida cayera a caballo entre dos minutos.
- La frontera de rol, sobre el controlador: el arqueo exige sesión y acota, las otras dos exigen
  administrador.
- Que **las tres pantallas se dibujan de verdad**, renderizándolas con datos reales y las dos
  sesiones. Leer el código de una vista no ejecuta ni una línea: un índice mal escrito pasaría
  entero hasta que alguien abriera la pantalla, y estas se abren el día que hay que entregar
  dinero. De paso comprueba lo que la secretaria **no** ve: ni la caja de otra persona, ni los
  enlaces a las dos pantallas de dirección.

**Un efecto colateral que hubo que arreglar:** `frontera-de-roles.php` comprobaba que el acta está
detrás del rol buscando `Auth::exigirSesion()` **en todo el archivo** del controlador. Con el arqueo
dentro —que sí es de los dos roles y usa esa guarda con toda razón— la comprobación empezó a fallar
señalando código correcto. Se acotó al cuerpo de `acta()`, que es lo que mantiene viva la pregunta
original en vez de convertirla en «¿alguien en este archivo dijo sesión?».

**Total de la suite: 20 pruebas, 395 comprobaciones**, todas en verde.

---

### D-58 — Nombres propios en mayúsculas: al mostrar, nunca al guardar

**Fecha:** 2026-08-22 · **Estado:** implementado y probado · **Afecta:** D-56, D-57, vistas de listado

El propietario pidió uniformar los registros en mayúsculas —inputs, tablas y actas—, porque unos
usuarios teclean «RODRIGUEZ CAMILO» y otros «Rodriguez Camilo».

**Lo primero fue medir, y el diagnóstico contradijo la premisa.** Clasificando los 209 registros
de texto de la base: de 114 participantes, **112 estaban ya en «Tipo Título»** y **uno solo** en
mayúsculas (el id 67). En apoderados, 46 de 51. Las instituciones cuentan como «otros» solo
porque llevan siglas y preposiciones —`IE Colegio Parroquial Nuestra Señora del Sagrado Corazón
de Jesús`—, que es la forma **correcta** en español. No había caos: había una fila desviada.

**El problema real estaba en otro sitio, y sí existía:** el carné pintaba el nombre tal como
estuviera en la base y el acta ponía los apellidos en mayúsculas y los nombres no. **El mismo
estudiante salía de dos formas distintas en dos documentos oficiales del mismo concurso.**

**Por qué NO se normaliza la base.** En los datos hay apellidos con preposición bien escritos:
`De la Cruz`, `De Moreno`, `De Loli`. Una capitalización automática los rompe —`MB_CASE_TITLE`
devuelve «De La Cruz» y convierte `IE` en `Ie`—, así que normalizar al guardar habría **empeorado
datos correctos y de forma irreversible**. `mb_strtoupper`, en cambio, no puede equivocarse: «DE
LA CRUZ» es correcto y aplicarlo dos veces da lo mismo. Esa idempotencia es lo que hace segura la
transformación, y solo se conserva si se aplica al mostrar.

**Por qué NO se toca ningún `input`.** `text-transform: uppercase` sobre un campo de captura hace
que la pantalla enseñe mayúsculas mientras se envía lo que se tecleó: la interfaz mentiría sobre
lo que se va a guardar, y la mezcla seguiría existiendo, solo que invisible. Se descartó
explícitamente y hay una prueba que lo vigila.

**Lo que se hizo:**

1. `Core\Texto::nombrePropio()` — único sitio donde se decide. Mayúsculas y colapso de espacios
   repetidos, para que un doble espacio de tecleo no se imprima.
2. **Acta**: nombres, apellidos e institución. El estudiante libre pasa a rotularse `LIBRE`.
3. **Carné**: apellidos, nombres y procedencia. Es lo que cierra la incoherencia entre ambos.
4. **Tablas y grillas**: una clase `.mayus` en CSS —presentación pura, no toca el valor que viaja
   en los formularios ni el que se compara al buscar—, aplicada al nombre del participante, al
   del apoderado, al de la institución y a la ficha de `/control`. La píldora de modalidad queda
   fuera: es un rótulo del sistema, no un dato tecleado.
5. **El registro 67 no se corrige**: al mostrarse en mayúsculas deja de desentonar por sí solo.
   Decisión del propietario, y evita un `UPDATE` sobre datos reales el día del concurso.

**Un riesgo que se verificó antes de darlo por bueno.** Las mayúsculas son más anchas, y la
maqueta del carné está medida al milímetro: `NOMBRE_POR_LINEA` está calibrado generando hojas de
diez hasta que se parten en dos páginas. Se generó la hoja con **los diez casos más largos reales**
—incluido `RAMÍREZ RONDAN, MAURICIO RENATO` con `IE COLEGIO PARROQUIAL NUESTRA SEÑORA DEL SAGRADO
CORAZÓN DE JESÚS`— y sigue ocupando **una sola página**.

**Comprobado.** `scripts/pruebas/mayusculas.php`, 20 comprobaciones. Las que importan: que la
**base conserva los nombres con minúsculas** —es lo que demuestra que la normalización sigue
siendo de presentación—, que el acta y el carné coinciden, y que **ningún input ni textarea lleva
la clase**. Dos comprobaciones iniciales resultaron tautológicas —comparaban el valor esperado con
el mismo helper que lo produce, y habrían dado verde siempre— y se rehicieron leyendo las celdas
del archivo. Total de la suite: **19 pruebas, 333 comprobaciones**.

---

### D-57 — Un libro por bolsa, y el rendimiento medido en vez de supuesto

**Fecha:** 2026-08-22 · **Estado:** implementado y probado · **Afecta:** D-56, D-54

El propietario pidió dos cosas el día del concurso: que el proceso fuera **óptimo para unos 1000
participantes**, y **separar las actas en un libro por modalidad**, con una hoja por grado dentro
de cada uno. La segunda se aplicó corregida, y la primera resultó no hacer falta.

**1. Por BOLSA, no por modalidad. La corrección importa y es cara.**

Las modalidades son cuatro; las bolsas, tres. Un libro por modalidad habría puesto a los
**privados y a los libres en archivos separados**, y de cada archivo habría salido un ganador por
grado: **dos ganadores donde las bases dicen uno** (D-37). Es exactamente el fallo que D-54 cerró
en el código, reintroducido por el reparto de archivos.

Se planteó al propietario con las dos opciones y sus consecuencias, y eligió **tres libros por
bolsa**: `acta-privada-libre.xlsx`, `acta-publica.xlsx`, `acta-cociap.xlsx`. La estructura del
ZIP se corresponde ahora con cómo se premia: cada libro contiene exactamente a quienes compiten
entre sí. `GeneradorActa` recorre `Concurso::bolsas()` y **nunca** la lista de modalidades.

El nombre del archivo sale del **rótulo** y no del identificador de la base —«cociap», no
«organizadora»—: quien reparte las actas busca la palabra que ve en el carné.

**2. Once hojas por libro, también las vacías.** Cada libro lleva las 11 categorías; donde esa
bolsa no tiene a nadie, la cabecera dice «sin inscritos». Una pestaña que falta se confunde con
un fallo del reporte. La bolsa de un solo participante sigue avisando, ahora en la cabecera de la
hoja: «1 inscrito — COMPITE SOLO, gana su bolsa por defecto».

**3. Se entrega un ZIP**, `GET /reportes/actas.zip`. La ruta se renombró desde
`/reportes/acta.xlsx`: una URL que termina en `.xlsx` y devuelve un ZIP engaña a quien la lee y
puede confundir a un intermediario. `ext-zip` ya era requisito —PhpSpreadsheet lo necesita para
escribir cualquier `.xlsx`—, así que no añade dependencias.

**4. El rendimiento no era el problema, y conviene que quede escrito.**

Medido con filas sintéticas, proceso completo incluido:

| Participantes | Tiempo | Memoria pico |
|---|---|---|
| 113 (los reales) | 0,46 s | 30 MB |
| **1000** | **0,96 s** | 32 MB |
| 2000 | 1,81 s | 36 MB |

Escalado lineal y con holgura: con 1000 participantes las actas tardan **menos de un segundo**.
Aunque el servidor compartido sea tres veces más lento, son tres segundos. **No se optimizó el
generador, porque no hacía falta.** Lo que peor escala del sistema son los carnés en PDF —Dompdf
tarda ~0,4 s cada diez, así que mil de una sentada serían ~40 s—, y eso ya está mitigado
generando por delegación y nunca «todos».

De paso queda corregido un número que se dio el 21-ago: los «~2,4 s con 113» de D-56 incluían el
arranque en frío del proceso y la consulta. El coste real del generador con 113 filas es 0,36 s.

**Tres mejoras que sí se aplicaron, por ser mejor código y no por necesidad:** los estilos se
aplican **por rango** y no celda a celda —con mil filas eso multiplicaba por nueve los objetos de
estilo—, la altura de fila se fija **por hoja** en vez de fila a fila, y cada libro se libera con
`disconnectWorksheets()` en cuanto se escriben sus bytes. El resultado es que la versión de tres
libros usa **menos** memoria que la de uno solo (32 MB contra 36 con 1000), pese a generar 33
hojas en vez de 11.

**Un fallo propio, encontrado al escribirlo:** la entrega del ZIP tenía el `exit` de la descarga
**dentro** del `try`, y `exit` **no ejecuta los bloques `finally`** en PHP. El archivo temporal se
habría quedado en el disco del servidor en cada descarga. Ahora los bytes se leen, el `finally`
borra, y la entrega ocurre fuera del bloque.

**Comprobado.** `acta-jurados.php` sube a **27 comprobaciones** y las dos que más importan son
nuevas: que **todos los privados** y **todos los libres** caen en el mismo libro, verificado
abriendo el archivo. Si alguna vez alguien reparte por modalidad, esa prueba se pone roja. Total
de la suite: **18 pruebas, 313 comprobaciones**.

---

### D-56 — El acta de los jurados, primer reporte de la Fase 5

**Fecha:** 2026-08-21 · **Estado:** implementado y probado · **Afecta:** Fase 5 (§8), D-54

El acta que va a la mesa el día del concurso: quién compite en cada bolsa, con las columnas que
el jurado llena a mano. `GET /reportes/acta.xlsx`, **solo administrador**.

**Diez decisiones, todas del propietario (21-ago), preguntadas una a una y no supuestas:**

1. **Excel**, no PDF. Se planteó el PDF —un acta se firma, y la maquetación fija de Dompdf lo
   haría más fiel al imprimir—, y el propietario confirmó Excel.
2. **Una hoja por categoría** (11), con las tres bolsas dentro. Cada jurado imprime su pestaña y
   tiene su grado completo.
3. **Las bolsas vacías salen igual**, rotuladas «sin inscritos». Un bloque que falta se confunde
   con un fallo del reporte; así se ve de un vistazo que ahí no hay nadie.
4. **Columnas que el jurado llena: Correctas, Incorrectas, Puntaje y H/E** (hora de entrega).
5. **Van EN BLANCO, sin fórmula.** Se ofreció calcular el puntaje con una fórmula de Excel y el
   propietario prefirió que no: una fórmula la pisa cualquiera al escribir encima, y el acta se
   usa impresa. El sistema no calcula ni guarda nada — la calificación sigue fuera de alcance (§9).
6. **Con DNI.** Decisión suya, sabiendo que son menores y que el documento se fotocopia.
7. **Solo confirmadas.** Al acta entra quien pagó. Como el sábado hay inscripción en la puerta,
   el libro se genera **al vuelo en cada descarga** —igual que el carné desde D-24—, así que
   recoge los cobros del momento sin que nadie tenga que acordarse de regenerarlo.
8. **Orden alfabético con la colación española**, así que la Ñ cae entre la N y la O. Con
   apellidos como Ñopo o Ñiquén en los datos reales, no es hipotético.
9. **Firma el «Comité de Inscripción»**, una sola línea. Corrige la propuesta inicial, que ponía
   dos líneas de jurado: el sistema certifica quién está inscrito, no quién califica.
10. **Solo administrador**: es el documento oficial del concurso y sale de una sola mano.

**La bolsa no se decide aquí.** `GeneradorActa` pregunta a `Concurso::bolsa()` (D-54). Si el
generador reimplantara el agrupamiento habría dos copias de la regla que reparte los premios,
que es exactamente lo que D-54 vino a cerrar. Por lo mismo, `Inscripcion::paraActa()` devuelve
filas planas y **no** agrupa en SQL.

**La bolsa de un solo participante se avisa en el propio título del bloque** —«1 inscrito —
COMPITE SOLO, gana su bolsa por defecto»— y no en una nota al final. Hay que verlo al repartir
las hojas, no descubrirlo en la premiación.

**Dos detalles medidos contra los datos reales, no estimados:**

- El **ancho de la columna del código**: el correlativo ocupa 22 caracteres
  (`COCIAP2026-0026-ZRK44Z`) y la columna estaba puesta a 14, así que salía cortado justo en la
  impresión, que es donde el documento se usa. Corregido a 24 tras verlo en el archivo generado.
- Al **estudiante libre** la columna Institución le pone «Libre» y no un guion: compite en la
  misma bolsa que los privados, y una casilla vacía obligaría al jurado a adivinar si es un libre
  o un dato que falta.

**Comprobado de verdad, no solo que se genere.** `scripts/pruebas/acta-jurados.php` **vuelve a
abrir el `.xlsx`** y comprueba lo que dice dentro: que hay una fila por confirmada y ningún código
repetido, que **ninguno cae en la bolsa equivocada** (leyendo bajo qué título quedó cada código),
que salen las tres bolsas de cada categoría, que las cuatro columnas están y **ninguna trae valor
ni fórmula**, que firma el Comité, y que el enlace lo ve el administrador y no la secretaria. Un
libro que se escribe sin error pero con la gente mal repartida pasaría cualquier prueba que solo
mirase que hay bytes. Son **19 comprobaciones**, más 4 de frontera de rol en
`frontera-de-roles.php`. Total de la suite: **18 pruebas, 305 comprobaciones**.

**Riesgo medido y anotado:** generar el libro con 113 confirmadas tarda **~2,4 s** en local. En
el hosting compartido será más, y crecerá con las inscripciones del sábado. Queda por debajo de
cualquier `max_execution_time` razonable, pero si algún día se acerca, la salida es generar por
categoría en vez del libro entero — el mismo razonamiento que llevó a que los carnés se impriman
por delegación y no «todos los del concurso» de una sentada.

**Pendiente de verificar en el servidor, y no es menor:** `vendor/` está en `.gitignore` y **no
viaja con el autodeploy**. PhpSpreadsheet está en `composer.json` desde el primer commit (17-ago),
antes del despliegue, y Dompdf del mismo bloque funciona allá porque los carnés se generan — así
que debería estar. Pero eso es deducción, no comprobación, y en ese servidor los errores no se
ven. Antes de publicar el acta hay que confirmarlo por SSH con
`php -r 'require "vendor/autoload.php"; var_dump(class_exists("PhpOffice\\PhpSpreadsheet\\Spreadsheet"));'`.

---

### D-55 — La suite deja de poder mentir

**Fecha:** 2026-08-21 · **Estado:** implementado y probado · **Afecta:** `scripts/pruebas/`

Salió al revisar los pendientes, y son **dos agujeros independientes** en la red de seguridad,
descubiertos el día antes del concurso.

**1. Cuatro pruebas no podían fallar nunca.** `vistas-formularios-usuario`, `vistas-instituciones`,
`vistas-reinscribir` y `vistas-usuarios` imprimían `OK`/`FALLA` pero no llevaban contador ni
terminaban con `exit()`: salían con código 0 pasara lo que pasara. Como `todas.php` decidía
mirando solo el código de salida, su resultado era **invisible para el corredor**. Una de ellas
estuvo fallando de verdad mientras el resumen decía «Todas pasan» — y se dio por bueno.

**Se arregla en el corredor, no en los cuatro archivos.** `todas.php` captura ahora la salida de
cada prueba (con `2>&1`, para no perder los fatales, que es justo cuando una prueba deja de
imprimir su propio resumen) y marca fallida toda la que imprima `FALLA` al principio de una
línea, aunque haya salido con 0. El motivo de hacerlo aquí es que cubre además **a la próxima
prueba que nazca con el mismo olvido**, que es lo que va a volver a pasar. El resumen lo dice con
todas las letras: `vistas-usuarios (salió con 0 pese a imprimir FALLA)`. Verificado rompiendo una
comprobación a propósito: antes pasaba en verde, ahora el corredor la caza.

**2. Dos pruebas se pusieron rojas solas, sin que nadie tocara el código.**
`firmas-y-usuarios` y `reinscribir` obtenían su caso con
`SELECT id FROM inscripciones WHERE estado='pendiente' LIMIT 1`: **secuestraban una fila real de
trabajo**. Estaba anotado como deuda consciente en `PENDIENTE.md` —«volverán a fallar solas cuando
se cobren»— y ocurrió tal cual: al cobrarse el lote del 21-ago no quedó **ninguna** pendiente y
las dos suites reventaron con `esperaba 4, obtuvo 0`.

**Ahora crean su caso en vez de buscarlo**, con `inscripcionPendienteDePrueba()` en `_comun.php`.
La modalidad y el monto no se escriben a mano: se derivan con `Concurso::modalidad()` y
`Concurso::tarifa()`, así que siguen siendo coherentes aunque a la I.E. elegida le toque ser la
anfitriona. Todo se revierte con la transacción de la prueba que lo llama, como el resto de la
carpeta.

Es el mismo principio que ya regía aquí y que estas dos incumplían: **nada atado al estado del
entorno**. Una prueba que depende de que la secretaría haya dejado algo sin cobrar no comprueba
lo que dice comprobar.

**Estado:** 17 pruebas, **282 comprobaciones**, exit 0, sin una sola línea `FALLA`.

**Queda una de la misma familia, sin arreglar y a propósito:** el caso 2 de `reinscribir` toma una
confirmada real con `LIMIT 1`. Hoy hay 113, así que no puede fallar por falta de material, y
ampliar el cambio la víspera del concurso tenía peor relación riesgo/beneficio que anotarlo.

---

### D-54 — La bolsa de competencia sube al dominio, y por fin se comprueba

**Fecha:** 2026-08-21 · **Estado:** implementado y probado · **Afecta:** D-37, Fase 5 (§8)

**El problema.** D-37 dejó escrita la regla que decide **contra quién compite cada participante**
—privada **+** libre juntos, pública, organizadora, por cada nivel y grado—, y advirtió que «de
esta regla depende entera la Fase 5». Pero la regla nunca llegó al código de la aplicación: su
única encarnación era un `CASE` de SQL dentro de `scripts/pruebas/modalidad-organizadora.php`.

Y ese `CASE` estaba **dentro de un `printf`**. Imprimía un resumen y no lo comprobaba nadie: en un
archivo llamado «pruebas» había cero aserciones sobre la regla más cara del sistema. Escribir el
acta partiendo de ahí habría significado copiarla a un segundo sitio —esta vez en producción— sin
que ninguna de las dos copias estuviera verificada.

**Por qué importa tanto.** El modo de fallo no es un error en pantalla: es **dos ganadores donde
las bases dicen uno**, o un premio entregado a quien compitió contra la bolsa equivocada. No
revienta, no deja traza en ningún log y se descubre en la premiación.

**Lo que se hizo.**

1. `Concurso::bolsa(string $tipoOrigen): string` — la regla, en el mismo modelo donde ya viven
   `modalidad()` y `tarifa()`. Único sitio donde se decide.
2. `Concurso::etiquetaBolsa()` — el rótulo, separado del valor igual que en D-37. El propietario
   eligió **«Privada + Libre»**: dice explícitamente que son dos modalidades en una sola bolsa,
   que es justo lo que el jurado necesita entender para aceptar un solo ganador. Un término único
   («Particular») era más corto y escondía eso.
3. `Concurso::bolsas()` — las tres, en el orden que las numera D-37. Existe para que el acta pueda
   recorrer **siempre las tres** por categoría, también las vacías: una bolsa con un solo
   participante tiene que verse en el papel y no descubrirse en la premiación.

**Tres decisiones de diseño, y su motivo.**

- **Nivel y grado no entran en `bolsa()`.** Ya los lleva `categorias`; una bolsa completa es la
  tupla (categoría, bolsa). Meterlos habría duplicado un eje que el esquema ya modela.
- **`bolsa()` lanza ante una modalidad desconocida**, en vez de devolver un valor de relleno como
  hace `etiquetaModalidad()`. La asimetría es deliberada: en el rótulo lo peor que pasa es un
  guion en pantalla; en la bolsa, un valor inventado mete a alguien en una bolsa fantasma y le
  quita el premio en silencio.
- **La agrupación se hace en PHP, no en SQL.** Así hay UNA copia de la regla. El coste es
  agrupar en memoria unas centenas de filas, que es gratis; el beneficio es que el `GROUP BY` no
  puede divergir del dominio, que es exactamente el fallo que esta decisión viene a cerrar.
  Los identificadores `BOLSA_PUBLICA` y `BOLSA_ORGANIZADORA` coinciden a propósito con su
  `tipo_origen` —esas bolsas SON una sola modalidad cada una—, con una advertencia anotada en el
  código: pasar una bolsa a `tarifa()` es un error, y con esas dos devolvería un número correcto
  por casualidad.

**Alcance deliberadamente corto.** La bolsa **no** se añade al filtro del listado ni a la píldora
de cada fila (decisión del propietario, 21-ago). Filtrar por bolsa obligaría a una segunda forma
de la regla dentro del `WHERE`, y la secretaría no la necesita para inscribir ni para cobrar.

**Comprobado.** El bloque 5 de `modalidad-organizadora.php` pasa de imprimir a **comprobar**: que
privada y libre comparten bolsa, que las otras dos no comparten con nadie, que las bolsas
distintas son exactamente tres, y que el reparto de los inscritos vivos no pierde ni duplica a
nadie. Se añade además una comprobación que **lee el `ENUM` real de `inscripciones.tipo_origen`**
y exige que el dominio sepa responder a cada uno de sus valores: si algún día se añade una
modalidad y se olvida `bolsa()`, el fallo salta aquí y no en producción, donde los errores no se
ven. La suite de modalidad pasa de 13 a **24 comprobaciones**.

**Hallazgo colateral, sin acción por ahora.** Con los datos de hoy —antes del lote— **11 de las 14
bolsas vivas tienen un solo participante**. Es información del propietario, no un fallo del
sistema, pero confirma que el aviso de «bolsa de uno» en el acta no es un adorno.

**Lo que esta decisión NO resuelve, y conviene tener presente.** D-50 permite cambiar la
procedencia de una inscripción ya pagada cuando la tarifa nueva cuesta lo mismo. Hoy `publica` y
`organizadora` valen ambas S/ 10.00, así que ese cambio está permitido — y **mueve al participante
de bolsa de competencia**, que es lo correcto si estaba mal clasificado, pero el aviso que ve
quien corrige habla solo de dinero y no menciona que cambia contra quién compite. Queda anotado
para después del concurso.

---

### D-53 — El panel deja de contar por dentro cómo está hecho el sistema

**Fecha:** 2026-08-21 · **Estado:** implementado y probado · **Afecta:** D-40

Decisión del propietario: el panel debe **mantener el funcionamiento del sistema lo más discreto
posible**. Se retiran dos cosas que eran informativas para el desarrollo, no para quien trabaja:

1. **La sección «Módulos».** Era un mapa de avance del proyecto puesto delante de la secretaría:
   «Fase 1 · listo», «Fase 3 · listo», y dos módulos anunciados como pendientes —«Pagos, anulación y
   carné · Fase 4», «Reportes · Fase 5»— que a esa altura o ya funcionan o no existen. Nada de eso
   es asunto de quien viene a inscribir estudiantes, y lo de «Fase 5» invitaba a buscar una pantalla
   que no está construida.
2. **La nota bajo las fechas**, que explicaba que el sistema no bloquea el registro por fecha y que
   el cierre lo decide la secretaría. Es una regla interna de diseño, no una instrucción de uso.

**Ningún camino se pierde.** Era la única duda que valía la pena comprobar antes de quitarla: la
sección contenía los únicos enlaces del panel a Instituciones y Apoderados. La **barra** ya los lleva
—Panel, Inscripciones, Apoderados, Control de ingreso, y para el administrador Instituciones y
Usuarios—, así que se retiró un atajo duplicado, no un acceso. La frontera de roles se sigue
comprobando, pero ahora donde de verdad vive: en la barra.

**El CSS se retiró también.** `componentes/_listas.scss` solo servía a esa sección, así que se
eliminó el parcial y su `@use`. Se verificó al byte: el build es reproducible —compilar sin tocar
nada devuelve un `app.css` idéntico—, y comparando regla a regla el antes y el después, las únicas
cuatro que desaparecen son `.lista-modulos` y sus tres variantes. Ninguna apareció ni cambió.

**Un fallo encontrado por el camino: «faltan 1 día».** Al mirar el panel ya limpio saltó la
concordancia de la cuenta atrás. La versión anterior armaba el texto a mano en cada `<dd>` con
`'faltan ' . $n . ' día' . ($n === 1 ? '' : 's')`: singularizaba el sustantivo y se olvidaba del
verbo. Estuvo bien 364 días al año y mal justamente **la víspera del concurso**, que es el único día
en que alguien mira esa cifra. Ahora la arma un solo `$cuentaAtras()` compartido por las dos fechas,
para que el acuerdo no dependa de acordarse, y la suite comprueba que «faltan 1 día» no vuelva.

**Estado:** `frontera-de-roles.php` pasa a **35 comprobaciones**, tres de ellas escritas en negativo
—que el panel no enseñe el avance del desarrollo, que no explique por dentro cómo trata las fechas,
y que la cuenta atrás concuerde—, para que ninguna de las dos secciones vuelva por descuido.

---

### D-52 — Cada quien opera sus propios registros

**Fecha:** 2026-08-21 · **Estado:** implementado y probado · **Afecta:** D-38, D-39, D-50, D-51

Decisión del propietario, el mismo día del lote grande: **una secretaria solo puede actuar sobre las
inscripciones que registró ella**. Lo de las demás lo ve, pero no lo toca.

**La regla es de escritura, no de lectura.** Es la distinción de la que depende todo lo demás, y no
es un matiz de diseño: aislar también la lectura rompe cuatro flujos que este concurso necesita
funcionando el sábado.

| Flujo | Qué pasaría si la lectura se aislara |
|---|---|
| `/control`, la mesa de la puerta | No encontraría a los estudiantes que registró la otra secretaria, con la fila delante |
| Hoja A4 por delegación | Saldría incompleta **y sin avisar**: se descubre cuando faltan carnés en la puerta |
| Cobro masivo | Una delegación de 30 registrada entre dos personas paga con **un solo Yape**; el cobro se partiría en dos |
| Aviso de documento repetido | `UNIQUE (concurso_id, dni)` es de la base: filtrada la consulta, la secretaria recibiría un **error de constraint** en vez del aviso legible |

Por eso el listado sigue mostrando el concurso entero, con su columna «Responsable» diciendo de
quién es cada fila, y lo que se cierra son **las acciones que escriben sobre una inscripción ajena**.

**Qué queda dentro de la regla y qué queda fuera:**

| Acción | Regla | Por qué |
|---|---|---|
| Corregir | **dueño o admin** | Es un UPDATE sobre datos ya impresos en un carné entregado |
| Reinscribir | **dueño o admin** | Crea una inscripción nueva y mueve el fondo de devoluciones |
| Anular | solo admin (D-51) | Sin cambio |
| **Cobrar** | exenta | Decisión del propietario: el Yape único de una delegación mixta. Quién cobró se sigue firmando en `confirmado_por` (D-39) |
| Regenerar carné | exenta | Es la reparación de un cobro; separarla del cobro la dejaría inservible |
| Descargar carné, hoja A4, `/control` | exentas | Lectura |

**El administrador queda por encima; a la inversa no.** Él opera cualquier fila —es quien tiene que
poder desatascar un registro cuando la persona que lo hizo no está delante—, pero **lo que él
registró es suyo y una secretaria no lo toca**: la regla es pareja en esa dirección, por decisión
expresa del propietario. Hoy son 20 inscripciones en esa situación.

**Cero migraciones.** `inscripciones.usuario_id` existe desde el esquema inicial (D-39) y está
escrito en las filas que ya hay, así que la regla se aplica sobre un dato que ya estaba. Lo que sí
falta —y queda **fuera de alcance a propósito**, la víspera del concurso— es la propiedad de
`apoderados` y `participantes`: ninguna de las dos tiene columna de dueño, y añadirla exige un
`ALTER TABLE` en una base con cobros reales dentro. Ver «Lo que queda pendiente» abajo.

**La acción ajena se oculta, y se dice por qué una vez.** Decisión del propietario frente a
deshabilitarla. Pero un ícono que falta sin explicación se lee como un fallo del sistema, así que la
nota al pie del listado —solo para quien no es administrador— dice que «Corregir» y «Reinscribir»
salen únicamente en lo propio y que cobrar y los carnés funcionan en todas. La columna «Responsable»
hace el resto: dice de quién es cada fila.

**Ocultar el botón es cortesía; la guarda es la protección.** Las dos acciones se rechazan también en
el servidor, dentro del mismo helper que ya cargaba y validaba la inscripción
(`inscripcionCorregibleOFallar`, `inscripcionReinscribibleOFallar`), que es lo que garantiza que la
guarda cubra **el formulario y el envío** sin tener que acordarse de ponerla dos veces. El rechazo
nombra al responsable —«Esa inscripción la registró Maritza Jara»— para que la secretaria sepa a
quién pedírselo, y no es silencioso, por la misma razón que en D-50 y D-51: un POST ignorado
devolvería la pantalla sin decir nada y ella creería que el cambio se aplicó.

Sin `http_response_code(403)`, por lo medido en D-51: un `Location:` posterior degrada la respuesta
a 302 y el 403 nunca sale por el cable.

**Lo que queda pendiente, dicho en voz alta:**

- **Apoderados.** `ApoderadoController::guardar()` sigue permitiendo a cualquier secretaria editar
  cualquier apoderado, y `guardarLibre()` actualiza los datos de contacto de un apoderado que pudo
  crear otra persona. No hay columna de dueño y la reutilización entre hermanos y con el docente
  delegado hace que «dueño» no sea una idea obvia aquí. **Después del concurso.**
- **Participantes.** Su dueño es el de su inscripción, así que la regla ya los cubre por la puerta
  de `/inscripciones/{id}/corregir`. No hay acceso directo a la tabla.

**Cómo se prueba.** Suite nueva, `propiedad-de-registros.php`, **29 comprobaciones**. Existe aparte
de `frontera-de-roles` por una razón concreta: aquella simula a la secretaria con **el id del
administrador** —le basta el rol para lo que comprueba—, así que ahí `puedeOperar()` responde que sí
a todo y no vería ninguna regresión de D-52. Esta monta sesiones con ids reales y distintos.

La suite vigila las dos mitades. La restrictiva: que `puedeOperar()` diga que no a lo de otra
secretaria, a lo del administrador y a una fila sin dueño; que «Corregir» salga en las filas propias
y **en ninguna más**; que las guardas del servidor estén y se ejecuten antes de escribir. Y la
permisiva, que es la que sostiene el sábado: que la secretaria **siga viendo todas las filas**, que
las casillas de cobro no se filtren por dueño, y que `PagoController`, `CarneController` y
`ControlController` **no** contengan `puedeOperar`. Esa última comprobación está escrita al revés a
propósito: si alguien «completa» D-52 añadiendo la guarda ahí, la suite se pone roja antes de que la
delegación mixta se quede sin poder cobrarse.

Las cuatro últimas trabajan sobre una inscripción **real** escrita con el id de una secretaria y
revertida al terminar, no sobre `usuario_id` repartidos a mano: si PDO devolviera esa columna como
cadena y alguien quitara un cast, `===` diría que no en todas las filas y la secretaria se quedaría
sin poder corregir **ni lo suyo** — el peor fallo posible en día de registro masivo, y el que una
prueba con datos fabricados en memoria nunca vería.

**Estado:** verificado que la suite detecta el fallo de verdad, saboteando `puedeOperar()` para que
devuelva siempre `true`: **cuatro comprobaciones en rojo**, y de vuelta a verde al restaurarla.

---

### D-51 — Anular pasa a ser exclusivo del administrador

**Fecha:** 2026-08-21 · **Estado:** implementado y probado · **Afecta:** D-15, D-39, D-40

Decisión del propietario, la víspera del lote grande: **una secretaria ya no puede anular una
inscripción**. Conserva todo lo demás — registrar, cobrar, corregir, reinscribir, emitir carnés—;
lo único que se le quita es la anulación.

**Por qué solo esa acción.** Es la única irreversible de la fila. Saca a un estudiante del concurso
y, si había pago confirmado, manda su monto al fondo de devoluciones. Y no se deshace: «Reinscribir»
(D-38) **crea una inscripción nueva**, no revive la anulada, así que una anulación indebida deja
rastro para siempre y descuadra el fondo mientras nadie la repare. Con dos secretarias trabajando a
la vez sobre la misma pantalla y con prisa, el coste de un clic equivocado no es simétrico con el
de las demás acciones.

**Tres piezas, no una:**

1. `AnulacionController::anular()` comprueba el rol **antes de tocar la inscripción** y responde
   **403**.
2. El **botón** desaparece de las filas para la secretaria.
3. El **formulario oculto** `#form-anular` deja de pintarse, y con él su token CSRF y la URL de
   destino. Esconder solo el botón habría dejado el mecanismo servido en el HTML.

La leyenda de acciones tampoco nombra ya «Anular» para quien no puede hacerlo: anunciarle un ícono
que no va a encontrar en ninguna fila es mandarla a buscar algo que no existe.

**Por qué no `Auth::exigirAdministrador()`.** Ese método responde «esa sección es exclusiva del
administrador» y devuelve al panel, que es lo correcto en `/usuarios` o `/instituciones` —secciones
enteras que la secretaria no pisa (D-40)—. Pero **`/inscripciones` sí es suya**: la usa todo el día.
Sacarla de ahí diciéndole que no es su sección sería desconcertante y la dejaría lejos de la fila en
la que estaba. Así que el rechazo es propio: mensaje que nombra la acción, sugerencia de usar
«Corregir» si solo hay un dato mal escrito, y vuelta **a su fila** con `#ins-{id}`.

**Se rechaza en voz alta, no en silencio.** Es la misma regla que D-50 aplicó a la procedencia: un
POST ignorado devolvería la pantalla sin decir nada y ella creería que la inscripción quedó anulada
cuando sigue viva.

**Cómo se prueba lo que no se puede invocar.** El rechazo termina en `redirigir()`, que hace `exit`,
así que una prueba de consola moriría a mitad. La guarda se comprueba **sobre el código** —que
exista, que esté antes de cargar la inscripción, que responda 403 y que el mensaje diga que no se
anuló nada—, con el mismo recurso que `iconos-y-listado-sin-filtrar` usa para vigilar los redirects
con filtro impuesto. Ocultar el botón es cortesía; la guarda es la protección.

La suite verifica además **lo que NO cambia**: que la secretaria sigue viendo «Corregir», el enlace
del carné en PDF y la barra de cobro, que «Reinscribir» sigue sin exigir administrador y que
«Corregir» sigue abierto a los dos roles. Sin esas cuatro, un cambio de permisos podría llevarse por
delante media pantalla sin que nadie lo notara hasta tener treinta tutores en la puerta.

**Estado:** `frontera-de-roles.php` pasa a **29 comprobaciones**. Se verificó que detectan de verdad
el fallo saboteando la guarda a propósito: dos comprobaciones se pusieron en rojo, y volvieron a
verde al restaurarla.

---

### D-50 — Corregir el registro de participación · sustituye a «Corregir categoría»

**Fecha:** 2026-08-20 / 21 · **Estado:** en producción, **aprobado por el propietario** (21-ago) · **Afecta:** D-01, D-31, D-37, D-38, D-39, D-48

**El agujero.** `Participante` solo tenía `crear()`. No existía ninguna forma de corregir el
documento, los apellidos, los nombres ni la institución de un estudiante mal registrado: lo único
corregible era el grado, y por un camino que **anulaba y reinscribía**. Además, `participantes` era
la **única mutación del sistema sin firma**, contra D-39.

Salió al intentar arreglar un DNI mal tecleado, y el daño ya estaba en la base cuando se buscó:

> Los participantes **20 y 21 son el mismo estudiante**. Mismo nombre completo, documentos
> `61880439` y `61880438` —un dígito de diferencia—. El 20 se anuló por institución equivocada
> («debe participar en el SAM Yungar») y, como no se podía corregir, se volvió a registrar de cero.
> En el reingreso a mano el documento salió distinto, y `uq_participante_documento` no lo detectó
> porque un dígito cambiado lo convierte, técnicamente, en otro documento.

Ese es el coste real de no poder corregir: no es incomodidad, es **una persona duplicada con dos
identidades**, un correlativo consumido y una anulada de adorno en el listado.

#### Qué se puede corregir, y quién

| Bloque | Campos | Quién |
|---|---|---|
| Datos del estudiante | documento, ap. paterno, ap. materno, nombres | ambos roles |
| Grado | categoría | ambos roles |
| Procedencia | delegación ↔ libre, institución, apoderado si pasa a libre | **solo administrador** |
| Motivo | texto **obligatorio** | siempre |

Queda **fuera** a propósito: `codigo_correlativo` —va impreso en carnés que ya están en la mochila
de un niño y es lo que se teclea en la puerta—, el flujo de caja —estado, medio, fecha, código de
Yape: eso se cobra o se anula, no se corrige— y `tipo_origen`/`monto` a mano, que se derivan de la
procedencia. Poder escribirlos sueltos es justo lo que permitía que el carné dijera «privada»
mientras la caja decía S/ 10.00.

#### La regla del cambio de procedencia

Vive en `Inscripcion::cambioDeProcedenciaPermitido()` y **no en el controlador**, para poder
comprobarla de frente:

| Estado | Regla |
|---|---|
| Pendiente | Siempre. Se recalculan `tipo_origen` y `monto`. |
| Confirmada, misma tarifa | Permitido. Se corrige la modalidad y **el monto no se toca**. |
| Confirmada, tarifa distinta | Bloqueado, diciendo los dos importes y que hay que anular y reinscribir. |
| Anulada | La acción no aparece. |

Compara **contra el monto cobrado**, no contra la tarifa vigente de la modalidad vieja. Casi siempre
son el mismo número, pero si una tarifa se moviera después de cobrar, comparar tarifas dejaría pasar
un cambio que descuadra la caja. Y los grupos **no están escritos a mano** (D-37 avisó de que la
tarifa COCIAP puede cambiar): hoy salen `publica ↔ organizadora` y `privada ↔ libre` porque hoy
cuestan igual; el día que una se mueva, la regla se ajusta sola.

#### La tabla `correcciones`

Una fila **por campo cambiado**, agrupadas por `lote`. Cuatro decisiones:

1. **FK real** sobre `participante_id`, no una tabla polimórfica: una FK polimórfica no la valida
   ninguna base, y un registro de auditoría que puede apuntar a filas inexistentes no es auditoría.
2. **`campo` con espacio de nombres** (`participante.dni`, `inscripcion.categoria_id`): una sola
   tabla cubre las dos entidades sin renunciar a esa FK.
3. **Una fila por campo**, no un JSON con el diff: «¿cuál era el DNI antes?» se responde con un
   `WHERE`.
4. **`anterior`/`nuevo` guardan el texto legible**, no el id: tiene que poder leerse dentro de un
   año sin unirlo a tablas que pueden haber cambiado. El precio es no poder agrupar por id; se
   asume, porque un texto que no se guardó no se recupera.

#### Lo que arrastra

- **`AnulacionController::corregir()` desaparece.** La acción ya no anula: pasa a
  `CorreccionController`, con la **misma ruta** `/inscripciones/{id}/corregir`, así que ningún
  enlace se rompe.
- **La inscripción conserva su id**, de modo que el redirect vuelve a `#ins-{$id}` y el `$nuevaId`
  que D-48 tuvo que introducir deja de hacer falta.
- **Corregir el grado ya no deja una anulada detrás.** El comentario del listado que lo daba por
  hecho se corrigió: a partir de ahora, una anulada sin inscripción viva es lo que parece —alguien
  que se quedó fuera— y «Reinscribir» deja de convivir con filas que no eran bajas de nadie.
- El rótulo de la acción pasa de «Corregir categoría» a **«Corregir»**, porque ya no es solo el
  grado.
- El carné **no hay que regenerarlo**: el PDF se genera al vuelo y no se guarda (D-24), y el QR
  sigue valiendo porque el correlativo no se toca. Lo único que queda viejo es el **papel ya
  impreso**, y por eso el aviso de éxito lo dice cuando el dato corregido va impreso.

#### Decisiones del propietario (2026-08-20)

- **Historial:** se guarda **y se muestra**, pero en la propia pantalla de corrección, sin pantalla
  de auditoría aparte. Con varias secretarias a la vez, quien va a corregir necesita ver si alguien
  ya tocó ese dato; la pantalla general se puede añadir después sobre la tabla ya poblada.
- **En anuladas no se corrige.** Un anulado con el documento mal se arregla en dos pasos:
  «Reinscribir» y luego «Corregir» sobre la fila viva, porque la reinscripción trabaja sobre el
  **mismo participante** (D-38). El error no queda atrapado y se evita un modo especial del
  formulario.
- **Convertir libre ↔ delegación es solo del administrador**, igual que cambiar de colegio: mueve
  la modalidad, y con ella la tarifa **y la bolsa de competencia** (D-37).
- **El buscador de apoderado se reutiliza** (`apoderado-reutilizable.js`), el mismo de la
  inscripción libre y de la ficha de institución. Capturarlo en limpio sería la tercera copia del
  mismo comportamiento y, peor, `apoderados.dni` es UNIQUE global: un alta con un documento ya
  registrado reventaría con un `1062` en la cara de quien corrige.
- **Las dos anuladas que hay en la base se quedan como están.** Se comprobó que **no** son
  correcciones de categoría antiguas, como se creyó al planificar: son anulaciones por institución
  equivocada. No hay historia mixta que preservar.

#### Permisos, con defensa en profundidad

El bloque de procedencia **no se dibuja** para una secretaria, y además el controlador **rechaza el
POST** si llega con esos campos, en vez de ignorarlos. Ignorar es peor: la pantalla diría
«corregido» y ella se quedaría creyendo que el colegio cambió. Un fallo silencioso en el dato que
decide la tarifa y la bolsa se descubre el día de la premiación.

#### Límite conocido

Corregir **no fusiona** dos registros que son la misma persona. Si el documento nuevo choca con otro
participante, se rechaza nombrándolo con su código —que es lo correcto—, pero decidir cuál de los
dos se queda es un trabajo aparte. El caso de los participantes 20 y 21 se resuelve a mano.

**Estado:** `scripts/pruebas/correcciones.php` — **43 comprobaciones**, todas sobre datos
desechables que la propia prueba crea. Las 16 suites del banco pasan, y la medición responsive pasa
a **7 pantallas × 8 anchos** con la de corrección incluida, medida con el historial lleno y como
administrador, que es su versión más ancha.

---

### D-49 — Los íconos salieron a 300 px en producción · corrige D-48

**Fecha:** 2026-08-20 · **Estado:** encontrado por el propietario en producción · **Afecta:** D-48

Desplegado D-48, el propietario reportó que en el servidor **los íconos salían enormes**, cosa que
en local no pasaba. Reproducido y medido: **300×150 px**, tanto cada ícono como el bloque del
sprite al pie de la página.

**La causa es de manual, y el error de diseño es mío.** Un `<svg>` sin atributos `width` y `height`
no mide lo que dice su `viewBox`: mide **300×150 px**, que es el tamaño por defecto de un elemento
reemplazado sin dimensiones. Yo dejé el tamaño viviendo **solo** en la regla `.icono` del CSS. Con
la hoja en su sitio se ve perfecto; en cuanto la hoja no llega —o llega la de ayer— cada fila del
listado se convierte en seis dibujos de 300 px y el sprite, que no debería verse nunca, se dibuja
como un rectángulo gigante al final de todas las páginas.

En local nunca se vio porque en local la hoja siempre era la recién compilada.

**Dos arreglos, y hacen falta los dos:**

**1. Los íconos ya no dependen del CSS para tener tamaño.** Cada `<svg class="icono">` lleva
`width="18" height="18"`, y el sprite lleva `width="0" height="0"`. Son atributos presentacionales:
pierden contra cualquier regla CSS, así que `.icono` sigue siendo quien manda cuando la hoja está.
Son la red de abajo, no el mecanismo. Comprobado en el navegador desactivando la hoja: de 300×150
a **18×18**, y el sprite a **0×0**.

**2. La hoja se enlaza con versión.** El enlace era `build/css/app.css` a secas: una dirección que
**nunca cambia**. Un navegador que ya tenía la anterior se la quedaba, y por bien que estuviera el
archivo en el servidor, al usuario no le llegaba. El nuevo `View::asset()` le añade
`?v=<filemtime>` — a la hoja y a los seis scripts—, así que cada despliegue estrena dirección.

Se usa `filemtime` y no un hash del contenido porque cuesta una llamada al sistema en vez de leer
y digerir 18 KB en cada página, y hace lo mismo: cambia cuando el archivo cambia. Al desplegar por
git, la fecha del archivo es la de la copia en el servidor, así que sirve igual. Si el archivo no
está donde se espera, devuelve la URL sin marca en vez de fallar: una página sin estilos se
arregla, una página que no carga no.

**Y una trampa del entorno que apareció por el camino, dos veces:** con `npm run dev` escuchando,
un `git checkout` que reescriba `src/scss/` o `src/js/` **dispara el watcher**, que recompila en
modo desarrollo y deja `public/build/` —que está rastreado por git— con CSS y JS sin minificar. Es
decir: el propio watcher puede deshacer el `npm run build` que `DESPLIEGUE.md` exige antes de
commitear, y hacerlo *después* de que lo hayas ejecutado. La prueba `responsive` lo cazó las dos
veces, porque comprueba que los puntos de corte estén en el CSS compilado.

**Lo que lo protege:** tres comprobaciones nuevas en
`scripts/pruebas/iconos-y-listado-sin-filtrar.php` — que todos los `<svg class="icono">` traigan sus
medidas, que el sprite mida cero por sí mismo, y que **ningún** enlace a `build/` salga sin `?v=`.
Esta última es la que impide que el problema vuelva por la puerta de atrás: basta con que alguien
añada un `<script>` nuevo copiando el patrón viejo.

**Lo que no cubre:** que el archivo llegue de verdad al servidor. Eso sigue siendo el despliegue, y
lo comprueba `verificar_despliegue.php`.

#### Lo que apareció al diagnosticarlo en el servidor: hay un CDN delante

Publicado el arreglo, el propietario probó con Ctrl+R y Ctrl+F5 y **no cambió nada**. Medido contra
producción, el porqué:

```
GET /build/css/app.css
Cache-Control: public, max-age=604800     ← siete días
x-hcdn-cache-status: HIT
Server: hcdn
```

**Hay un CDN de Hostinger delante del servidor**, y cachea la hoja **siete días** bajo un nombre de
archivo que nunca cambia. Ctrl+F5 vacía la caché del navegador, pero la petición sigue llegando al
borde del CDN, que responde con su copia guardada. Ninguna recarga, por dura que sea, puede
arreglar eso desde el lado del cliente.

Comprobado desde la propia página, pidiendo el MISMO URL dos veces —una normal y otra con un
parámetro que el CDN no había visto nunca—:

| | bytes | ¿trae `.icono`? |
|---|---|---|
| Lo que estaba usando la página | 17 680 | **no** — la hoja anterior a D-48 |
| Lo que había en el servidor | 18 711 | sí |

El mismo archivo, dos contenidos. Eso convierte el `?v=` de arriba en **la única salida posible**,
no en una mejora: con una URL estable y siete días de TTL, cualquier cambio de CSS es invisible
para quien ya haya visitado el sitio, durante una semana. El problema no era de este despliegue: lo
tiene el sistema desde el primer día y solo se notó ahora porque el síntoma era espectacular.

Y una segunda causa encima: el autodeploy **no había publicado `028b7a2`** quince minutos después
del push. El siguiente push arrastró los dos commits. Conviene comprobar que el despliegue llegó
—`curl -s DOMINIO/login | grep app.css` y ver el `?v=`— antes de dar por bueno un arreglo de CSS.

---

### D-48 — La columna de acciones pasa a íconos, y el listado deja de filtrarse solo

**Fecha:** 2026-08-20 · **Estado:** aprobado por el propietario · **Afecta:** D-30, D-38, D-40, D-41

Dos peticiones del propietario sobre `/inscripciones`, que resultaron ser la misma cosa vista de
dos lados: **lo que la pantalla enseña de más** (seis rótulos de texto compitiendo con los datos en
una tabla de nueve columnas) y **lo que la pantalla esconde de menos** (un filtro que se ponía solo
después de cobrar).

#### Los íconos: sin librería, sprite SVG en línea

Se evaluaron cuatro caminos y tres se caen por razones de ESTE proyecto, no por gusto:

| Camino | Por qué no |
|---|---|
| Font Awesome / icon font | 30–70 KB de fuentes que `gulpfile.js` no sabe copiar: el pipeline solo compila `scss` y `js`. Habría que añadir un paso o copiarlas a mano, y las copias a mano se pudren en el despliegue. Los lectores de pantalla, además, leen los glifos como caracteres sueltos. |
| lucide / feather por npm | Reescriben el DOM al cargar: los íconos entrarían con un parpadeo. Y `node_modules/` nunca sube a Hostinger, así que exigiría un paso de build que los incruste. |
| `<img src="…svg">` | Un `<img>` no hereda `currentColor`: el ícono de anular no podría ser rojo ni cambiar en `:hover`. Gulp tampoco copia `resources/`. |
| **Sprite `<symbol>` en línea** ✅ | Un bloque impreso **una vez** por página desde el layout; cada uso cuesta ~55 bytes. Cero dependencias, cero peticiones, hereda `currentColor`, no toca el pipeline y no sube nada nuevo al servidor. |

Vive en `app/Views/parciales/iconos.php`, lo imprime el layout con el nuevo `View::parcial()`, y
los seis dibujos son: lápiz, círculo tachado, hoja con flecha, flecha circular, persona con un más
y ojo.

**El de anular es un círculo tachado y no un tacho de basura, a propósito.** Anular no borra nada:
la fila anulada se queda en el listado con su motivo y su firma (D-38, D-39). Un tacho invitaría a
leerlo como un borrado.

#### Lo que se hizo para que quitar las letras no rompa nada

Tres cosas se rompían si se quitaban los rótulos sin más, y las tres se atendieron:

1. **El rótulo no desaparece del HTML.** Sigue en un `<span class="accion__texto">` que el CSS
   recorta con `clip-path`. Con `display: none` habría salido del árbol de accesibilidad y un
   lector de pantalla anunciaría «enlace» a secas seis veces por fila. El `title` da el globo del
   ratón; el `<span>` da el nombre accesible.
2. **El área táctil.** `_botones.scss` daba `min-height: 2.75rem` a estos enlaces pero **no
   `min-width`**: mientras fueron texto, «Corregir categoría» medía sus 110 px y el alto era lo
   único que faltaba. Con solo el ícono el blanco de toque se encogía a 18 px —una cuarta parte del
   mínimo de las WCAG 2.5.5— justo al lado de una acción irreversible. Se añade el `min-width`.
   Es la misma clase de fallo que costó D-41 y D-42.
3. **Una leyenda al pie de la tabla.** En el escritorio los rótulos están recortados, así que la
   leyenda es el ÚNICO sitio de la pantalla donde las palabras se leen. Sin ella, un ícono que no
   se reconoce solo se averigua probándolo.

**Y en el teléfono el texto vuelve** (decisión del propietario). Por debajo de tableta la fila es
una ficha con ancho de sobra, y cuatro dibujos sueltos sin columnas vecinas se identifican peor
que en el escritorio.

#### El listado no se filtra solo

**La vista ya listaba todo por defecto**: `index()` lee los seis filtros de `$_GET` y de ningún
otro sitio, y `listar()` no añade ningún estado por su cuenta. El problema estaba entero en los
**redirects**: ocho sitios volvían con un filtro ya puesto.

El peor, y el que reportó el propietario, era `PagoController` → `?estado=confirmada` después de
cobrar. No era solo molesto: **las pendientes que NO se cobraron desaparecían**, y con ellas la
casilla de «seleccionar todas las pendientes», que solo se dibuja si queda alguna a la vista. El
listado afirmaba que el trabajo estaba terminado justo cuando no lo estaba.

El segundo, `?estado=pendiente` al fallar la validación del cobro, tenía un defecto extra:
**tiraba el filtro que el usuario sí había elegido**. Se corregía el medio de pago y se volvía
mirando un conjunto de filas distinto del que se estaba cobrando.

**Por qué estaban ahí**, que no fue descuido: `listar()` ordena por apellido, no por fecha, así que
la fila recién creada o tocada queda enterrada a media tabla. El `?q=CÓDIGO` era la salida barata
para hacerla encontrable.

**Lo que hay ahora:**

- Las acciones de **un solo registro** (anular, corregir, reinscribir, regenerar, alta de libre)
  vuelven a `/inscripciones#ins-N`: lista completa, el navegador baja hasta la fila y `:target` la
  resalta. `corregir` y `reinscribir` devuelven desde su transacción el id de la inscripción
  **nueva**, que es la vigente y la que se quería comprobar.
- El **cobro** vuelve a `/inscripciones` a secas. El recuento y el importe van en el aviso, que es
  pegajoso desde D-30 y no se desvanece.
- El **error de validación del cobro** devuelve los filtros que el usuario tenía, que viajan en un
  campo `volver` del formulario. Como es entrada del cliente, la URL la arma
  `Inscripcion::urlListado()`, que solo deja pasar las seis claves de `Inscripcion::FILTROS`: sin
  esa lista blanca, un `volver` fabricado quedaba a un paso de una redirección abierta. No se lee
  `HTTP_REFERER` por lo mismo.

**LA EXCEPCIÓN, y es una sola:** tras registrar una delegación se sigue volviendo con
`?institucion_id=N`. Ese filtro no recorta la vista, **habilita el paso siguiente**: el botón
«Imprimir carnés de esta delegación» solo se dibuja cuando hay una delegación elegida —la hoja
necesita un destinatario; imprimir el concurso entero son cientos de páginas y un PDF que en
hosting compartido se queda sin tiempo (D-40)—. Sin el filtro, el camino de «acabo de inscribir a
30 chicos» a «imprimo sus carnés» se queda sin ningún letrero. Aprobado como excepción consciente.
Los dos redirects de error de `CarneController` la acompañan, y por otra razón: allí el usuario
**ya estaba** filtrado por esa delegación cuando pulsó el botón, así que conservar el filtro es
devolverlo donde estaba.

#### Corrección del mismo día: los botones se me quedaron pegados arriba

Lo reportó el propietario al revisar la pantalla, y tenía razón. **Fallo mío, introducido en este
mismo cambio.**

Para separar los seis íconos le puse `display: flex` y `gap` al `<td>` de acciones. Un `<td>` con
`display: flex` **deja de ser una celda de tabla**: pierde el `vertical-align: middle` del que
dependía, el navegador lo envuelve en una celda anónima, y la caja flex —con alto de contenido, no
de fila— se queda arriba del todo. En una fila alta, la columna entera queda descolgada.

**Y no afectaba solo a Inscripciones.** La regla iba en `_tablas.scss`, así que alcanzaba a las
cuatro tablas que usan `.tabla__acciones`. Medido con Chrome headless:

| Pantalla | Alto de fila | Desvío |
|---|---|---|
| Inscripciones | 94 px | **−26.8 px** |
| Apoderados | 70 px | **−12.6 px** |
| Instituciones | 47 px | −0.6 px |
| Usuarios | 47 px | −1.0 px |

Las dos últimas no estaban bien: **estaban a salvo por casualidad**. Sus filas son de una sola
línea, así que no había altura sobrante por la que descolgarse. En cuanto una fila crezca, se
tuercen igual.

**La corrección** es quitar esa regla y separar las acciones con un margen entre hermanas
(`.accion + .accion`), dejando que la celda siga siendo una celda y que la tabla centre su
contenido ella sola —que es el mecanismo correcto, no un apaño—. `vertical-align: middle` en
`.accion` completa el arreglo: una caja `inline-flex` cuyo primer hijo es un `<svg>` no tiene línea
base propia y, sin eso, los íconos se apoyan en la base del texto y quedan altos en su renglón.

Medido de nuevo: Inscripciones pasa de −26.8 a **+1.8 px de media**; Apoderados, de −12.6 a −0.5.
No es cero **y no debe serlo**: `vertical-align: middle` alinea con el medio de la equis minúscula,
no con el centro geométrico del renglón. Dos píxeles sobre una fila de 92 no se ven; decir que
quedó en cero sería falso.

**La lección, que es la tercera vez que aparece:** esto lo vio el propietario mirando la pantalla,
no yo razonando sobre el CSS. Igual que en D-42. Por eso el guardia no es una comprobación de HTML
sino una medida real, y por eso vive en `medir_responsive.php` y no en el banco de PHP: **desde el
HTML este fallo es invisible** —el marcado es idéntico antes y después—, solo existe una vez el
navegador ha calculado el diseño.

#### Segunda corrección, ya con la extensión de Chrome conectada

Con el navegador disponible se revisó la pantalla de verdad. Lo primero, bien: los íconos se ven
centrados en su fila, el ancla lleva a la fila correcta y la resalta, la leyenda se lee al pie, y
en un viewport de 390 px las seis acciones vuelven a salir **con su texto**, envueltas en dos
líneas. Lo segundo, no:

**La fila anclada quedaba debajo del aviso.** Estas acciones vuelven SIEMPRE con un mensaje, y el
bloque de avisos es pegajoso desde D-30 (`position: sticky; top: 0`). El navegador deja la fila
del `#ins-N` pegada al borde superior, y el aviso se le pone encima: medido, **71 px de una fila
de 93 quedaban tapados**. El sistema te mandaba a mirar algo que no se veía, que es peor que no
mandarte a ningún sitio — y deja el `#ins-N` sin cumplir lo que vino a hacer.

**Lo que se hizo:** `src/js/inscripciones.js` mide el alto real del bloque de avisos, se lo pone a
la fila como `scroll-margin-top` y vuelve a desplazarse. Se **mide** en vez de fijar un valor en el
CSS porque el alto no es constante: un cobro con carnés fallidos deja dos avisos, y un margen a ojo
se quedaría corto justo en el caso en que más falta hace leer esa fila. Comprobado en el navegador:
de 71 px tapados a **0**.

**Esto no lo caza ninguna prueba automática**, ni las de PHP —el HTML es idéntico con aviso y sin
él— ni `medir_responsive.php`, que carga las vistas sin mensaje flash. Queda como comprobación
humana en **ICO-3** del protocolo, que ahora exige ver la fila entera y no solo resaltada.

#### Lo que lo protege

- `scripts/pruebas/iconos-y-listado-sin-filtrar.php` — 19 comprobaciones nuevas. Entre ellas, la
  que de verdad guarda la decisión: **recorre los controladores buscando cualquier
  `redirigir('/inscripciones?…')` y falla si el filtro no es `institucion_id`**. Se mira el código
  y no el navegador porque un `header()` no deja rastro en ninguna vista. También comprueba que el
  sprite se imprima una sola vez, que ningún `<use>` apunte a un símbolo inexistente, que los seis
  rótulos sigan en el HTML y que la lista blanca de `urlListado()` descarte lo desconocido.
- `docs/protocolo-pruebas.html` — bloque **ICO**, 7 pruebas, y las 9 instrucciones que mandaban
  «pulsa Regenerar» reescritas para nombrar también la forma del ícono. Sin eso el protocolo
  quedaba inseguible.
- `scripts/medir_responsive.php` — segunda medida además del desborde: en cada pantalla de más de
  768 px compara el centro de los botones de acción con el centro de su fila y falla si se separan
  más de 6 px. La tolerancia no es cero a propósito (ver arriba). **Comprobado que el guardia
  falla de verdad**: reintroducido el `display: flex` en el `<td>`, denuncia 6 combinaciones con
  −26.1 y −12.6 px; quitado, vuelve a verde.

**Lo que no cubre:** el ancla `#ins-N` falla si la fila queda más allá del tope de 2000 filas
(D-40). Con el volumen esperado no ocurre, y el aviso —que nombra el código— sigue siendo la
garantía; el ancla es la comodidad.

**Verificado:** las 15 pruebas de `scripts/pruebas/todas.php` pasan. Sin comprobar en navegador:
la extensión de Chrome no estaba conectada en esta sesión.

---

### D-47 — `crear_usuario.php` fallaba en silencio en hosting compartido

**Fecha:** 2026-08-19 · **Estado:** encontrado durante el despliegue real · **Afecta:** scripts

Al crear el primer administrador en Hostinger, el script imprim&iacute;a
«Contraseña para …:» y **terminaba sin leer nada y sin decir por qu&eacute;**. La contrase&ntilde;a
que el propietario escrib&iacute;a a continuaci&oacute;n se la quedaba el shell, que intentaba
ejecutarla como un comando —`bash: admin1234: command not found`—.

**La causa:** para ocultar la escritura, el script llamaba a `shell_exec('stty -echo')` sin
comprobar que exista. En hosting compartido **`shell_exec` suele estar deshabilitado** por
`disable_functions`, y entonces la llamada no hace nada, `fgets(STDIN)` devuelve `false`, y el
c&oacute;digo ca&iacute;a por una rama que terminaba en una cadena vac&iacute;a.

Lo grave no era que fallara, sino **c&oacute;mo**: sin un solo mensaje. El operador no ten&iacute;a
forma de saber si el problema era la contrase&ntilde;a, la base, los permisos o el propio script.

**Lo que se hizo:**

- Se comprueba `shell_exec` antes de usarlo, mirando tanto `function_exists()` como la lista de
  `disable_functions` —hace falta comprobar las dos: una funci&oacute;n deshabilitada sigue
  existiendo—.
- Si no se puede ocultar la entrada, **se avisa y se lee igual**. Una contrase&ntilde;a visible en
  la pantalla de tu propia sesi&oacute;n SSH es un problema mucho menor que no poder crear el
  primer administrador.
- Si `fgets` devuelve `false`, se dice en voz alta —«no hay entrada por teclado»— con la causa
  m&aacute;s probable: haber pegado el comando junto con el siguiente, con lo que esa
  l&iacute;nea se consume como si fuera la contrase&ntilde;a. Tambi&eacute;n pas&oacute;, antes
  que lo otro.

**Comprobado** simulando el servidor con `php -d disable_functions=shell_exec`: sin teclado
explica el motivo y sale; con entrada disponible avisa de que la contrase&ntilde;a se ver&aacute;
y **crea el usuario**.

---

### D-46 — Limpieza de los datos de prueba, con seguro

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** producción

Desplegado el sistema, hay que dejar la base sin los datos de prueba.
`database/migraciones/2026-08-19-limpiar-datos-de-prueba.sql` borra participantes,
inscripciones, carn&eacute;s, instituciones y apoderados, y conserva
organizaci&oacute;n, concurso, categor&iacute;as, tarifas y **usuarios**.

**Tres decisiones que no son obvias:**

1. **La primera ejecuci&oacute;n no borra nada.** El archivo lleva `SET @LIMPIAR := 0` y todos los
   pasos destructivos van condicionados; ejecutarlo informa de cu&aacute;ntas filas se
   llevar&iacute;a y —lo que de verdad importa— **cu&aacute;nto dinero hay en inscripciones
   confirmadas**. Hay que cambiar la l&iacute;nea a mano para armarlo. El seguro existe porque el
   archivo no puede distinguir una base de pruebas de una de producci&oacute;n con cobros reales
   dentro, y el d&iacute;a que alguien lo ejecute por costumbre habr&aacute; dinero en esas filas.
2. **`DELETE` y no `TRUNCATE`.** TRUNCATE no respeta las claves for&aacute;neas y no se puede
   deshacer dentro de una transacci&oacute;n; a este tama&ntilde;o no hay diferencia de velocidad.
3. **Se reinician los contadores.** No es cosm&eacute;tica: el c&oacute;digo correlativo se arma
   con el `id` del participante (D-04, D-12), as&iacute; que sin reiniciar el primer estudiante
   real saldr&iacute;a con un n&uacute;mero heredado de las pruebas —`COCIAP2026-0024-…`— impreso
   en su carn&eacute; y en la n&oacute;mina.

**Consecuencia que la migraci&oacute;n avisa al terminar:** `organizaciones.institucion_id` apunta
a una instituci&oacute;n, as&iacute; que la marca se suelta antes de borrar y el concurso queda
**sin I.E. anfitriona**. Hay que volver a darla de alta y marcarla antes del primer estudiante del
COCIAP; si se olvida, sus estudiantes se cobran como p&uacute;blica y compiten en la bolsa
equivocada, en silencio.

**Probada sobre una copia exacta de la base**, no sobre la real: con el seguro puesto no
tocó nada y report&oacute; 23 participantes, 25 inscripciones y S/ 215 en 17 cobros; armada,
dej&oacute; las cinco tablas en cero, los cinco contadores en 1, las 14 claves for&aacute;neas
intactas y el seed —1 concurso, 11 categor&iacute;as, 4 tarifas, 3 usuarios— sin tocar. Y es
repetible: una segunda ejecuci&oacute;n sobre la base ya limpia no da error.

**No se ejecuta en desarrollo.** Trece de las pruebas automáticas toman filas existentes de la
base local —una inscripción confirmada, un participante, un colegio— y con la base vacía dejarían
de comprobar nada.

---

### D-45 — Sin poder mover el Document Root: raíz de `public_html` y defensa por capas

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** D-44, despliegue

El plan de Hostinger del propietario **no permite mover el Document Root**: solo deja crear
subcarpetas dentro de `public_html`. Cae por tanto la recomendaci&oacute;n de D-44, y quedan dos
sitios donde poner el proyecto — la ra&iacute;z de `public_html` o una subcarpeta.

**En seguridad son id&eacute;nticos**: en los dos casos el proyecto entero vive dentro de la
ra&iacute;z web y la &uacute;nica defensa es el `.htaccess`. As&iacute; que la decisi&oacute;n se
tom&oacute; con el otro criterio, medido:

| D&oacute;nde | URL | QR del carn&eacute; |
|---|---|---|
| **`public_html/`** | `https://dominio/c/K7M9X3` | **29 × 29 m&oacute;dulos** · 0.466 mm cada uno |
| `public_html/compite/` | `https://dominio/compite/c/K7M9X3` | 33 × 33 · 0.409 mm |

Nueve caracteres m&aacute;s de URL meten **cuatro filas m&aacute;s de m&oacute;dulos**, y en los
13.5 mm que el carn&eacute; reserva al QR cada m&oacute;dulo encoge un **12%**. Es exactamente el
criterio de D-25, que ya sacrific&oacute; legibilidad de la URL para ganar tama&ntilde;o de
m&oacute;dulo. **Va en la ra&iacute;z de `public_html`.**

**Y como el `.htaccess` pasa a ser la &uacute;nica defensa, deja de ser una sola lista.** Cada
directorio sensible —`config/`, `core/`, `app/`, `database/`, `storage/`, `scripts/`, `src/`,
`resources/`, `docs/`— lleva ahora su propio `.htaccess` con `Require all denied`.

No es redundancia decorativa; resuelve dos debilidades reales de la lista de la ra&iacute;z:

1. **Depende de estar al d&iacute;a.** Ya le faltaba `.git`, y nadie lo vio hasta que se
   busc&oacute; a prop&oacute;sito (D-44). El pr&oacute;ximo directorio que alguien a&ntilde;ada
   volver&aacute; a faltar.
2. **Depende de d&oacute;nde est&eacute; montado el sitio.** El patr&oacute;n
   `^/?(config|core|...)` est&aacute; escrito para la app en la ra&iacute;z de la URL; con el
   proyecto en una subcarpeta **no llegar&iacute;a a coincidir**, y lo &uacute;nico que salvar&iacute;a
   esos archivos ser&iacute;a que la reescritura hacia `public/` devuelve 404 por no encontrarlos —
   protecci&oacute;n por accidente, no por regla. Los archivos por directorio no dependen del
   prefijo.

**Comprobado sobre Apache**, y el c&oacute;digo de respuesta lo demuestra: esas rutas pasaron de
404 —«no existe bajo `public/`»— a **403** —«denegado»—, que es la regla del directorio actuando.
`/.git/config` sigue en 404 por la regla de los archivos con punto, y el login, el CSS y el escudo
siguen en 200.

**Dos cosas que quedan a mano en `public_html`:** borrar el `index.html` de bienvenida de Hostinger
—se servir&iacute;a antes que el front controller— y no subir `node_modules/`. `vendor/` lo crea
Composer y no lleva `.htaccess` propio, pero s&iacute; est&aacute; en la lista de la ra&iacute;z.

---

### D-44 — Dónde queda el Document Root, y el `.git` que quedaba al aire

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** despliegue

Al desplegar, Hostinger propone `public_html` —la ra&iacute;z web— y ofrece cambiarlo.

**El c&oacute;digo funciona en cualquiera de las dos disposiciones**, y est&aacute; comprobado: el
prefijo de las URL se deduce del propio front controller, y `/index.php` (Document Root en
`public/`) y `/public/index.php` (repo en `public_html`) dan los dos la ra&iacute;z. Los cuatro
`SCRIPT_NAME` posibles est&aacute;n en `scripts/pruebas/urls-sin-dominio.php`.

Lo que cambia no es si funciona, sino **qu&eacute; queda alcanzable desde internet**. Con el repo
en `public_html`, cuelgan del dominio `config/`, `core/`, `database/`, `vendor/` y el directorio
**`.git`** con el c&oacute;digo fuente completo y su historial.

**Y ah&iacute; hab&iacute;a un agujero real.** El `.htaccess` de la ra&iacute;z bloqueaba por
nombre de carpeta —`config|core|app|database|storage|scripts|vendor|resources|docs|src|node_modules`—
y por extensi&oacute;n —`sql|md|json|lock|log|ini`—, y **`.git/config` no encaja en ninguna de las
dos**. `https://dominio/.git/config` y los packfiles habr&iacute;an entregado el repositorio
entero, que es de lo primero que prueba un esc&aacute;ner autom&aacute;tico. La contrase&ntilde;a
de la base no estaba en riesgo —`config.local.php` nunca se versiona— pero el c&oacute;digo
s&iacute;.

Se a&ntilde;ade una tercera regla: **nada que empiece por punto**, con la excepci&oacute;n de
`/.well-known`, que es por donde se validan los certificados. Comprobado contra el Apache local:
`/.git/config`, `/.git/HEAD`, `/.gitignore`, `/config/config.php`, `/core/Database.php`,
`/database/schema.sql`, `/storage/logs/php-error.log`, `/vendor/autoload.php` y `/docs/` responden
403 o 404, mientras el login, el CSS y el escudo siguen dando 200.

**Recomendaci&oacute;n al propietario: mover el Document Root a `public/`.** El `.htaccess` es
defensa por lista, y una lista protege lo que alguien se acord&oacute; de poner en ella; con el
Document Root en `public/`, esos archivos no est&aacute;n en la ra&iacute;z web en absoluto. Es la
diferencia entre «bloqueado» y «no alcanzable». La regla nueva se queda igualmente: protege la
disposici&oacute;n de respaldo y no cuesta nada.

---

### D-43 — Las URL dejan de depender de un dominio fijo

**Fecha:** 2026-08-19 · **Estado:** aprobado por el propietario · **Afecta:** D-20, D-21, D-25

El propietario avisa de que el dominio es **provisional y va a cambiar**, quizá varias veces.

**El problema, mapeado antes de tocar:** `app.url_base` hac&iacute;a **dos trabajos** en un solo
valor —el prefijo de instalaci&oacute;n (`/compite` en local, vac&iacute;o en producci&oacute;n) y
el host— y de &eacute;l depend&iacute;an tres cosas: `View::url()` para **todos** los enlaces y
assets, las cabeceras `Location` de `Auth` y `Controller`, y el QR del carn&eacute;. En
producci&oacute;n no hab&iacute;a derivaci&oacute;n ninguna: se devolv&iacute;a el valor tal cual.

Cambiar de dominio sin editar esa l&iacute;nea no romp&iacute;a el sitio de forma visible. Era peor:
si el dominio viejo segu&iacute;a vivo, **la hoja de estilos cargaba desde all&iacute; y todo se
ve&iacute;a bien**, pero cada enlace sacaba a la secretaria del dominio nuevo hacia el viejo —contra
otra base de datos— y cada redirecci&oacute;n hac&iacute;a lo mismo. Un fallo silencioso.

**La separaci&oacute;n**, en `core/Url.php`, que pasa a ser el &uacute;nico sitio donde se decide
una URL:

- **`Url::a()`** — enlaces, assets y redirecciones. **Relativos a la ra&iacute;z**, sin esquema ni
  host. El sitio funciona bajo cualquier dominio sin tocar configuraci&oacute;n. Una cabecera
  `Location` relativa es v&aacute;lida (RFC 7231) y universal.
- **`Url::absoluta()`** — solo el QR, que se imprime y necesita el dominio dentro. Respeta
  `app.url_base` si est&aacute; configurado; si est&aacute; vac&iacute;o, toma el dominio por el que
  se gener&oacute; ese carn&eacute;.
- **El prefijo se deduce del servidor**, no de la configuraci&oacute;n: `dirname(SCRIPT_NAME)` sin
  el `/public` final. Producci&oacute;n con Document Root en `public/` da `/index.php` → prefijo
  vac&iacute;o; local con el `.htaccess` de la ra&iacute;z da `/compite/public/index.php` →
  `/compite`. La condici&oacute;n para saber si hay petici&oacute;n web es que `SCRIPT_NAME`
  **empiece por barra**, y no `PHP_SAPI`: as&iacute; es la misma funci&oacute;n en los dos casos y
  **se puede probar de verdad** —con `PHP_SAPI` esa rama era inalcanzable desde las pruebas, y la
  primera versi&oacute;n de la prueba llevaba una copia del algoritmo, que no comprueba nada—.

**Efecto secundario que importa:** al no usar el host en la navegaci&oacute;n, la cabecera `Host`
—que la controla quien hace la petici&oacute;n— deja de poder decidir a d&oacute;nde apuntan los
enlaces y las redirecciones. Esa era la raz&oacute;n documentada en D-20 para no derivar nada en
producci&oacute;n, y **desaparece**: no se deriva ning&uacute;n host porque no se usa ninguno.

**Lo que esto NO arregla, y hay que decirlo:** un QR **ya impreso** lleva dentro el dominio de
cuando se imprimi&oacute;. Si el dominio cambia despu&eacute;s, ese papel apunta a donde ya no
est&aacute;s, y ninguna configuraci&oacute;n lo deshace. Dos mitigaciones, las dos operativas:
imprimir los carn&eacute;s lo m&aacute;s tarde posible, y recordar que **la puerta no depende del
QR** — `/control` busca por c&oacute;digo, documento o apellido tecleado, y el correlativo va
impreso en grande en el propio carn&eacute;.

`app.url_base` pasa a ser **opcional** y solo para el QR. `verificar_despliegue.php` ya no lo
exige: avisa de la consecuencia en vez de bloquear.

**Comprobado:** 14 comprobaciones nuevas en `scripts/pruebas/urls-sin-dominio.php` —el prefijo con
los tres `SCRIPT_NAME` reales, que ninguna URL de navegaci&oacute;n lleve host, que el QR s&iacute;
lo lleve, y que sin dominio can&oacute;nico el QR siga al de la petici&oacute;n—, m&aacute;s las
151 de la suite y la medici&oacute;n responsive. Y contra el servidor local de verdad: el CSS sale
como `/compite/build/css/app.css` y responde 200, y `/panel` sin sesi&oacute;n redirige a
`/compite/login`.

**Efecto colateral en el banco de medici&oacute;n:** sus fixtures son `file://`, y una URL relativa
a la ra&iacute;z all&iacute; resuelve contra la ra&iacute;z del disco. Las pantallas se med&iacute;an
**sin CSS** y el banco denunci&oacute; 18 desbordes inexistentes —las «culpables» eran las tablas en
crudo—. `medir_responsive.php` ahora incrusta la hoja en cada fixture.

---

### Comprobación de portabilidad Windows → Linux, antes de subir

**Fecha:** 2026-08-19

El propietario corri&oacute; en el navegador los cinco bloques que se pueden validar en local
—`COC`, `REI`, `USU`, `ROL`, `RES`— y **pasaron todos**. `DEP` no se puede correr sin desplegar.

Antes de subir se busc&oacute; la clase de fallo que en Windows no se ve: **Linux distingue
may&uacute;sculas en los nombres de archivo y Windows no**, as&iacute; que una vista pedida como
`Inscripciones.index` sobre un archivo `inscripciones/index.php` funciona aqu&iacute; y da un 500
all&iacute;. Se comprobaron las 18 vistas referenciadas, los nombres de clase contra sus archivos,
los assets que piden las plantillas y los 110 archivos del proyecto en busca de nombres que
choquen al perder la distinci&oacute;n.

**Resultado: ningún problema real.** El comprobador levant&oacute; 12 falsos positivos y merece
constar, porque casi los reporto como fallos:

- *«`namespace Core` no coincide con la carpeta `core`»* — falso. PSR-4 mapea el **prefijo** a la
  **carpeta** en `composer.json` (`"Core\": "core/"`), no exige que coincidan de forma literal.
  Confirmado leyendo `vendor/composer/autoload_psr4.php` y el classmap, que trae rutas reales
  tomadas del disco: `'Core\Database' => .../core/Database.php`.
- *«rutas de Windows en `medir_responsive.php`»* — falso: es la lista de candidatos donde buscar
  Chrome, que incluye a prop&oacute;sito las de Windows, Linux y macOS.
- *«ruta de Windows en `crear_usuario.php`»* — el &uacute;nico con algo de raz&oacute;n, y no era
  c&oacute;digo sino el ejemplo de uso del comentario, que solo mostraba la forma de XAMPP cuando
  ese script se ejecuta **en el servidor**. Corregido para los dos entornos.

**Y se cubri&oacute; un riesgo que el plan ten&iacute;a anotado desde D-17 y el verificador no
miraba:** `Database::ordenEspanol()` comprueba que exista la colaci&oacute;n `utf8mb4_spanish_ci`
y, si no est&aacute;, **no falla** — deja un aviso en el log y ordena con la colaci&oacute;n por
defecto. Ese silencio es el problema: nadie lee el log el d&iacute;a del concurso, y una n&oacute;mina
con las &Ntilde; mezcladas entre las N parece correcta hasta que alguien busca a un «&Ntilde;a&ntilde;ez»
y no lo encuentra. `verificar_despliegue.php` lo comprueba ahora antes de abrir el registro.

---

### Dónde viven las pruebas

**Fecha:** 2026-08-19

Las comprobaciones automáticas que citan D-37 a D-41 estaban en archivos sueltos fuera del
repositorio, y se habrían perdido al cerrar la sesión de trabajo. Viven en **`scripts/pruebas/`**,
y se ejecutan todas con:

```
php scripts/pruebas/todas.php
```

Quince pruebas, 171 comprobaciones. Corren **contra la base real de trabajo** —no contra una
maqueta— porque es lo que las hace valer: así detectan que MariaDB rellena un ENUM `NOT NULL` en
vez de rechazar el INSERT, que la colación española ordena la Ñ donde debe y que el esquema tiene
las columnas que el código espera. Cada una abre su transacción y la revierte, y `_comun.php` la
deshace igual si la prueba se cae a mitad: **no dejan una sola fila**.

Nada en ellas está atado al entorno: ni rutas absolutas ni identificadores fijos. El administrador
y el concurso se buscan, no se escriben, así que valen igual en Hostinger o tras restaurar otro
respaldo.

**Lo que NO cubren:** renderizan las vistas y leen el HTML, así que ven si falta un rótulo o si un
enlace aparece a quien no debe; **no ven la pantalla**. Que algo se salga del ancho, que un botón
quede bajo el teclado o que el QR se lea de verdad sigue siendo comprobación humana, y para eso
está `docs/protocolo-pruebas.html`.

---

### Decisiones pendientes de resolución por el propietario
- **P-04 — CONFIRMADO** por el propietario (2026-08-18) `[AMPLIADO POR D-37]`. «Modalidad»
  —libre, pública, privada— es el criterio que elige la tarifa, y `tipo_origen` sale de
  `instituciones_educativas.tipo` para las delegaciones y de `'libre'` para el estudiante
  libre. Es lo que `Inscripcion::listar()` ya venía aplicando y lo que el carné imprime
  bajo ese rótulo desde D-27, así que la confirmación no cambia código: **lo que cierra es
  el riesgo de que el papel ya entregado contradijera la regla**. Tarifas vigentes:
  pública S/ 10.00, privada S/ 15.00, libre S/ 15.00.
- ~~**P-06** Reinscribir a un participante ya registrado~~ → **RESUELTO por D-38** (2026-08-19).
  Y era peor de lo que decía esta nota: «Corregir categoría» tampoco lo recuperaba, porque rechaza
  lo anulado. La única salida era SQL a mano, y en la base de pruebas ya había dos participantes
  atrapados. Ahora hay una acción «Reinscribir», visible solo cuando al participante no le queda
  ninguna inscripción viva.
- **P-07 — APLAZADO** por decisión del propietario (2026-08-18): por tiempo, el fix no entra
  antes de la presentación y el sistema queda declarado de un solo inquilino en la §9.
  Aislamiento entre organizaciones en `apoderados` e `instituciones_educativas`.
  **Comprobado el 2026-08-18** levantando un segundo inquilino con un concurso simultáneo, todo
  dentro de una transacción revertida. Los estudiantes salen limpios: el mismo documento entra en
  el concurso del otro inquilino sin chocar. Los adultos, no, y son tres fallos distintos:
  *(a)* el listado de apoderados del inquilino B muestra los 8 del inquilino A, porque la consulta
  no filtra por nada (`WHERE 1 = 1`); *(b)* al escribir un documento que ya existe en A, el
  formulario de B autorrellena **nombre, celular y correo de una persona de la otra institución**;
  *(c)* B no puede siquiera darla de alta por separado —`ERROR 1062, Duplicate entry` sobre
  `apoderados.dni`—, así que la misma persona queda como **una sola fila compartida** y la última
  edición gana: B reescribiendo el celular por el que A coordina su delegación entera.
  `InstitucionEducativa::eliminar` tampoco distingue inquilino: cuenta participantes de cualquier
  concurso, así que B podría borrar un colegio de A que aún no tenga inscritos.
  **La raíz es P-05:** sin `organizacion_id` en `usuarios`, la sesión no sabe de qué inquilino es,
  y sin eso no hay por dónde filtrar. Las dos preguntas son el mismo trabajo.
  Ninguna de las dos tablas tiene `organizacion_id`, y `apoderados.dni` es UNIQUE **global**.
  Hoy no se nota porque hay una sola organización, pero con dos: el apoderado que registre la
  segunda, si su documento ya existe, se «reconocerá» como el de la primera —viendo sus
  nombres, su celular y su correo— y al guardar lo sobrescribirá. El catálogo de colegios
  también quedaría compartido y borrable por cualquiera de las dos. Los participantes **no**
  tienen este problema: cuelgan de `concurso_id`, y cada concurso es de una organización.
  Decidir si el multi-tenant entra de verdad o se declara fuera de alcance del MVP.
- **P-05** `usuarios` carece de `organizacion_id` pese al diseño multi-tenant declarado.
  Se respeta el plan tal como está; añadirlo después es un `ALTER TABLE` aditivo.

### Resueltas
- ~~P-01 duplicados por DNI~~ → D-05 · ~~P-02 formato del correlativo~~ → D-04 ·
  ~~P-06 charset por tabla~~ → D-03 · ~~P-07 pipeline de compilación de SASS~~ → D-19

---

### Hallazgo reportado: sistema preexistente `siga_cociap`
**Fecha:** 2026-08-16 · **Estado:** resuelto por el propietario

Durante la Fase 0 se detectó `C:\xampp\htdocs\siga_cociap` (proyecto PHP con la misma
arquitectura MVC que este plan manda construir) y la base `siga_cociap` (51 tablas, con
`personas`, `estudiantes`, `apoderados`, `usuarios`/`roles`, y `niveles`/`grados` con las
mismas 11 combinaciones que las categorías de COCIAP). No estaba mencionado en este plan.

**Decisión del propietario:** es un proyecto suyo pero **independiente**. COCIAP 2026 se
construye standalone y `siga_cociap` no se toca ni se lee.
