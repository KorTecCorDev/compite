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
- **Tarifa**: costo de inscripción por Concurso y tipo de origen (I.E. pública S/10, I.E. privada S/15, estudiante libre S/15). No varía por categoría.
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

**Docente Delegado** (persistente en la I.E.): apellido paterno, apellido materno, nombres, celular, correo electrónico, DNI (opcional).

**Director de la I.E.** (persistente en la I.E.): apellido paterno, apellido materno, nombres, celular, correo electrónico, DNI (opcional).

**Participante en delegación**: nivel, grado, DNI, apellido paterno, apellido materno, nombres.

**Participante libre (independiente)**: DNI, apellido paterno, apellido materno, nombres, nivel, grado.

**Apoderado del estudiante libre**: DNI, apellido paterno, apellido materno, nombres, celular.

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
| Gestionar usuarios (crear/desactivar secretarias) | ❌ | ✅ |
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

### D-05 — Duplicados por DNI: se advierten, no se impiden
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

### D-16 — El código de seguridad de Yape es opcional
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

Migración idempotente en `database/migraciones/2026-08-18-carne-sin-archivo.sql`, aplicada.
`rutas.carnes` sale de `config/config.php`. Efecto colateral bienvenido: **emitir un carné ya no
puede fallar por permisos de disco** ni dejar un pago confirmado sin carné.

**Queda pendiente decidir** qué hacer con los cuatro PDF antiguos que siguen en
`storage/carnes/`. Son huérfanos —ya nada los lee— pero no se han borrado sin consultar.

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

### Decisiones pendientes de resolución por el propietario
- **P-04** Origen del `tipo_origen` que selecciona la tarifa (presumiblemente
  `instituciones_educativas.tipo` para delegación y `'libre'` para libre — sin confirmar).
  **Necesario antes de la Fase 3.**
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
