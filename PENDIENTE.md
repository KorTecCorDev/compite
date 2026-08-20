# Punto de retomada — 19 de agosto de 2026, noche

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

## Deuda consciente, aplazada por el propietario

- **Sin respaldo automático.** El `mysqldump` está documentado en
  `DESPLIEGUE.md` y es manual. Es la única red sobre el dinero cobrado.
- **Sin reportes (Fase 5).** `Inscripcion::fondoDevoluciones()` existe en el
  modelo, pero no hay pantalla ni exportación a Excel. No bloquea el registro;
  sí bloqueará el descargo cuando dirección lo pida.
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
