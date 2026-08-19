# Despliegue en Hostinger — COCIAP 2026

Guía de una sola pasada. Al final hay un script que comprueba solo si el
servidor quedó listo, así que **no hace falta que confíes en esta lista**: la
ejecutas y te lo dice.

> Todo lo que aquí se decide está justificado en la §11 del plan
> (`PLAN_IMPLEMENTACION_COCIAP2026.md`). Si algo de esta guía contradice al
> plan, manda el plan.

---

## Antes de subir nada (en tu máquina)

**1. Compila los assets en modo producción.**

```
npm run build
```

`public/build/` está **rastreado por git**, así que lo que esté commiteado es lo
que se despliega. Con `gulp` de desarrollo escuchando, ahí queda CSS y JS sin
minificar. Este paso hay que repetirlo antes de **cada** commit que toque
`src/scss/` o `src/js/`.

**2. Commitea y sube.** El servidor toma el código de git, no de tu disco.

---

## En el servidor

### 1. Código

Sube el repositorio (git clone o subida por FTP/SSH) **a la raíz de
`public_html`**, no a una subcarpeta.

### Dónde exactamente, y por qué

Lo ideal sería apuntar el Document Root del dominio a `public/`, de modo que
nada más fuera alcanzable. **Si tu plan no lo permite** —solo deja crear
subcarpetas dentro de `public_html`— quedan dos sitios, y conviene elegir con
datos:

| Dónde | URL del sistema | QR del carné |
|---|---|---|
| **`public_html/`** (recomendado) | `https://dominio/` | **29 × 29 módulos** · 0.466 mm cada uno |
| `public_html/compite/` | `https://dominio/compite/` | 33 × 33 módulos · 0.409 mm cada uno |

La subcarpeta alarga la URL nueve caracteres, y eso mete **cuatro filas más de
módulos** en el QR. En los 13.5 mm que el carné le reserva, cada módulo pasa de
0.466 a 0.409 mm: un **12% más pequeño** para que lo resuelva la cámara de un
teléfono a la luz que haya en la puerta. Es el mismo motivo por el que el QR usa
la ruta corta `/c/{sufijo}` en vez del código completo (D-25).

**En seguridad las dos son iguales**: con el Document Root en `public_html`, el
proyecto entero queda dentro de la raíz web y la única defensa es el `.htaccess`.
Por eso no se deja en una sola lista:

- El `.htaccess` de la raíz redirige a `public/` y bloquea por carpeta, por
  extensión y todo lo que empieza por punto (incluido `.git`).
- **Cada directorio sensible lleva además su propio `.htaccess` con
  `Require all denied`**: `config/`, `core/`, `app/`, `database/`, `storage/`,
  `scripts/`, `src/`, `resources/` y `docs/`. No dependen de que la lista de la
  raíz esté al día, ni de dónde esté montado el sitio.

Comprobado sobre Apache: `/config/config.php`, `/core/Url.php`,
`/app/Models/Usuario.php`, `/database/schema.sql`, `/scripts/crear_usuario.php`,
`/storage/logs/php-error.log`, `/src/scss/app.scss` y `/docs/` responden **403**;
`/.git/config` responde **404**; y el login, el CSS y el escudo siguen en 200.

Dos cosas que hay que hacer a mano en `public_html`:

- **Borra el `index.html` de bienvenida** que Hostinger deja ahí, o se servirá
  antes que el front controller y verás su página en vez del sistema.
- **No subas `node_modules/`.** No hace falta en el servidor y son miles de
  archivos dentro de la raíz web.

`vendor/` lo crea Composer en el servidor y no lleva `.htaccess` propio, pero sí
está en la lista de la raíz.

### 2. Dependencias

```
composer install --no-dev --optimize-autoloader
```

`vendor/` no está en git a propósito.

**Ojo con la versión de PHP**: la de la consola SSH puede no ser la del sitio
web. Compruébalas por separado (`php -v` por SSH, y cPanel → PHP Configuration
para el dominio) y **iguálalas**. Hacen falta `pdo_mysql`, `mbstring`, `gd` y
`zip`.

### 3. Base de datos

Crea la base en cPanel y luego, desde la raíz del proyecto:

```
mysql -u USUARIO -p NOMBRE_BD < database/schema.sql
mysql -u USUARIO -p NOMBRE_BD < database/seed.sql
```

**No ejecutes nada de `database/migraciones/`.** Esos archivos son para bases
que ya existen y vienen de una versión anterior. `schema.sql` levanta una base
nueva **con todo incluido** — está verificado comparando estructuras.

El seed carga la organización, el concurso, las 11 categorías y las 4 tarifas
(pública S/10, privada S/15, libre S/15, organizadora S/10). No crea usuarios:
las contraseñas tienen que pasar por `password_hash()` de PHP.

### 4. Configuración

Copia la plantilla y ajústala **en el servidor**:

```
cp config/config.local.example.php config/config.local.php
```

Cuatro cosas que importan:

| Clave | Valor | Por qué |
|---|---|---|
| `app.entorno` | `produccion` | |
| `app.depurar` | **`false`** | Con `true`, cualquier error enseña rutas del servidor y trozos de consulta al visitante. |
| `app.url_base` | **vacío**, o tu dominio | Desde D-43 **solo afecta al QR del carné**; los enlaces y los assets son relativos a la raíz y funcionan bajo cualquier dominio. Déjalo vacío si el dominio es provisional: cada carné tomará el dominio por el que se generó. Ponlo cuando tengas uno propio y quieras que los QR apunten siempre ahí. |
| `db.*` | credenciales de cPanel | |

`config.local.php` está en `.gitignore` y nunca debe subirse al repositorio.

### 5. El primer administrador

```
php scripts/crear_usuario.php "Nombres Apellidos" correo@dominio.pe administrador
```

La contraseña se pide por teclado, para que no quede en el historial de la
consola. A partir de aquí **no vuelvas a necesitar SSH para usuarios**: el resto
se crean desde `/usuarios`, que también es el único sitio donde se cambia una
contraseña.

### 6. Comprueba

```
php scripts/verificar_despliegue.php
```

Mira PHP y sus extensiones, la configuración, que el **esquema de la base esté
completo** columna por columna, que estén las cuatro tarifas y las 11
categorías, que haya un administrador activo, que los assets estén minificados
y que `storage/logs` sea escribible.

Sale con código 0 si todo está listo. Los `[FALLO]` bloquean; los `[aviso]` los
decides tú.

> Este script existe por un fallo real: durante las pruebas, restaurar un
> respaldo dejó la base desfasada de las migraciones y **el cobro se cayó entero
> con un error 1364 de MySQL**, sin ningún aviso previo. No hay tabla de
> migraciones aplicadas, así que comprobar el esquema es la única forma de
> saberlo.

Y si quieres ir más allá de la configuración, **las pruebas del sistema**:

```
php scripts/pruebas/todas.php
```

Trece pruebas, 137 comprobaciones, contra la base real y **sin dejar nada**: cada
una abre su transacción y la revierte, incluso si se cae a mitad. Cubren la
modalidad COCIAP, la reinscripción, las firmas de cobro y anulación, la frontera
entre secretaria y administrador, el tope del listado y los rótulos responsive.

Y la medición responsive, que necesita Chrome instalado:

```
php scripts/medir_responsive.php
```

Carga cada pantalla dentro de un `<iframe>` de ancho exacto —un iframe sí crea un
viewport de verdad— y comprueba que ninguna desborda entre 320 y 1440 px,
nombrando al elemento culpable si alguna lo hace.

Ninguna de las dos sustituye la comprobación en navegador: ven el HTML y el
ancho, no el diseño. Para eso está `docs/protocolo-pruebas.html`.

---

## Antes del primer estudiante

1. **Da de alta la I.E. anfitriona** en `/instituciones` y márcala con papel
   **«Anfitriona»**. Mientras no lo hagas, sus estudiantes se cobran como
   cualquier pública y compiten en la bolsa equivocada, **sin ningún aviso**.
   El verificador lo comprueba.
2. **Crea las secretarias** en `/usuarios`. Entrégales la contraseña en persona:
   no queda guardada en ningún sitio legible y nadie puede recuperarla, solo
   asignar otra.
3. **Prueba de humo con datos reales de prueba**: registra una delegación de
   dos, cóbrala, descarga el carné, abre el QR **con el móvil** (es lo único que
   comprueba de verdad que `url_base` está bien) y anula las dos al terminar.

---

## Durante los días de registro

**Respalda al cerrar cada jornada.** Va a haber dinero cobrado y carnés
emitidos; hoy no hay ninguna otra red.

```
mysqldump -u USUARIO -p NOMBRE_BD | gzip > respaldo-$(date +%F).sql.gz
```

Guárdalo fuera del servidor. Los `.sql`, `.sql.gz` y `.zip` de la raíz están en
`.gitignore`, así que no se cuelan en un commit por accidente.

---

## Lo que este sistema NO hace, y conviene tener presente

- **Es de un solo inquilino.** Dar de alta una segunda organización mezcla sus
  apoderados y sus colegios con los de la primera. Está comprobado y detallado
  en P-07 del plan. Antes de que exista una segunda hay que resolver P-05 y
  P-07, en ese orden.
- **No bloquea por fecha.** Se puede inscribir incluso el día del concurso; el
  cierre del periodo es una decisión operativa, no una regla del sistema.
- **La vista pública del carné es abierta**: cualquiera con el enlace la ve. Por
  eso el código correlativo lleva un sufijo aleatorio, para que no se puedan
  recorrer los carnés de los menores incrementando un número.
- **El listado se corta en 2000 filas** y avisa cuando lo hace. La hoja de
  carnés por delegación se niega a generarse si la delegación pasa de ahí, antes
  que salir incompleta.
- **Cambiar de dominio no rompe el sitio** (D-43): enlaces, assets y
  redirecciones son relativos a la raíz. Lo único que no se puede arreglar es un
  **QR ya impreso**, que lleva dentro el dominio de cuando se imprimió. Por eso:
  imprime los carnés lo más tarde posible, y recuerda que la puerta funciona
  tecleando el código en `/control` sin necesidad del QR.
