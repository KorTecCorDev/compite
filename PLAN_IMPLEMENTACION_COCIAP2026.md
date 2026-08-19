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

Trece pruebas, 137 comprobaciones. Corren **contra la base real de trabajo** —no contra una
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
