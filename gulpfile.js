/**
 * Pipeline de assets del sistema COCIAP 2026.
 *
 * Compila SASS y JavaScript desde `src/` hacia `public/build/`, y levanta
 * BrowserSync para probar los formularios con la secretaría viendo los cambios
 * en vivo.
 *
 * Dos tareas de cara al usuario:
 *   npm run dev    → compila sin minificar, con sourcemaps, y queda escuchando.
 *   npm run build  → compila minificado y sin sourcemaps, para desplegar.
 *
 * Requiere Node >=22.11 (ver package.json). El código PHP sigue corriendo sobre
 * el PHP 8.2.12 de XAMPP: Node solo existe en la máquina de desarrollo y nunca
 * se sube a Hostinger.
 */

import { src, dest, watch, series, parallel } from 'gulp';
import * as dartSass from 'sass';
import gulpSass from 'gulp-sass';
import plumber from 'gulp-plumber';
import sourcemaps from 'gulp-sourcemaps';
import postcss from 'gulp-postcss';
import autoprefixer from 'autoprefixer';
import cssnano from 'cssnano';
import terser from 'gulp-terser';
import browserSync from 'browser-sync';
import { PassThrough } from 'node:stream';
import { networkInterfaces } from 'node:os';

const sass = gulpSass(dartSass);
const servidor = browserSync.create();

/* ---------------------------------------------------------------------------
 * Rutas
 * ---------------------------------------------------------------------------
 * La salida va a `public/build/` y no a `build/` en la raíz —como suele verse
 * en los tutoriales— porque el .htaccess de la raíz reescribe todo hacia
 * public/: una carpeta build/ fuera de public/ sería inalcanzable desde el
 * navegador.
 */
const rutas = {
    scss: {
        entrada: 'src/scss/app.scss',
        vigilar: 'src/scss/**/*.scss',
        salida: 'public/build/css',
    },
    js: {
        entrada: 'src/js/**/*.js',
        salida: 'public/build/js',
    },
    // Al tocar una vista o un controlador también hay que recargar: el HTML lo
    // genera PHP, así que BrowserSync no se entera solo.
    php: ['app/**/*.php', 'core/**/*.php', 'routes/**/*.php', 'config/**/*.php'],
};

/*
 * Modo de compilación. `build` lo pone en true antes de compilar, de modo que
 * las mismas tareas sirven para desarrollo y para producción sin duplicarlas.
 */
let produccion = false;

/* ---------------------------------------------------------------------------
 * CSS
 * ------------------------------------------------------------------------ */

/**
 * plumber evita que un punto y coma olvidado en un .scss mate el proceso de
 * watch: reporta el error y sigue escuchando, en lugar de obligar a relanzar
 * `npm run dev` a cada rato.
 */
export function css() {
    const postcssPlugins = [autoprefixer()];

    if (produccion) {
        postcssPlugins.push(cssnano());
    }

    return src(rutas.scss.entrada)
        .pipe(plumber())
        .pipe(produccion ? noop() : sourcemaps.init())
        .pipe(sass({ outputStyle: 'expanded' }).on('error', sass.logError))
        .pipe(postcss(postcssPlugins))
        .pipe(produccion ? noop() : sourcemaps.write('.'))
        .pipe(dest(rutas.scss.salida))
        .pipe(servidor.stream());
}

/* ---------------------------------------------------------------------------
 * JavaScript
 * ------------------------------------------------------------------------ */

/**
 * Cada archivo de `src/js/` sale como archivo propio, sin empaquetar: las
 * vistas cargan solo el script que necesitan (delegacion.js en el formulario de
 * delegación, etc.) en vez de un bundle único.
 */
export function javascript() {
    // allowEmpty evita que la compilación entera falle si todavía no hay
    // archivos en src/js.
    return src(rutas.js.entrada, { allowEmpty: true })
        .pipe(plumber())
        .pipe(produccion ? noop() : sourcemaps.init())
        .pipe(produccion ? terser() : noop())
        .pipe(produccion ? noop() : sourcemaps.write('.'))
        .pipe(dest(rutas.js.salida))
        .pipe(servidor.stream());
}

/* ---------------------------------------------------------------------------
 * Servidor de desarrollo
 * ------------------------------------------------------------------------ */

/**
 * BrowserSync va en modo `proxy`, nunca en modo `server`.
 *
 * El modo `server` solo sirve archivos estáticos: entregaría el index.php como
 * texto plano en lugar de ejecutarlo. Con `proxy`, Apache sigue procesando el
 * PHP y BrowserSync se limita a intermediar e inyectar el script de recarga.
 *
 * Al conservar la ruta /compite, el sitio queda en http://localhost:3000/compite
 */
export function dev() {
    const ip = ipDeRed();

    servidor.init({
        proxy: 'localhost/compite',
        port: 3000,
        notify: false,
        open: false,

        // Sin esto BrowserSync no anuncia la URL externa en esta máquina: hay
        // varias interfaces con direcciones APIPA (169.254.x.x) y no acierta
        // cuál es la buena. `online` la obliga a mostrarla.
        host: ip ?? undefined,
        online: Boolean(ip),
    }, function () {
        anunciarEnlaces(ip);
    });

    watch(rutas.scss.vigilar, css);
    watch(rutas.js.entrada, javascript);
    watch(rutas.php).on('change', servidor.reload);
}

/**
 * IP de la máquina en la red local, para compartir el enlace con otros equipos.
 *
 * Descarta las 169.254.x.x (APIPA): Windows se las asigna sola a interfaces sin
 * red de verdad —Bluetooth, adaptadores virtuales— y aquí hay cuatro. Anunciar
 * una de esas daría un enlace que no abre en ningún lado.
 */
function ipDeRed() {
    for (const interfaces of Object.values(networkInterfaces())) {
        for (const red of interfaces ?? []) {
            if (red.family !== 'IPv4' || red.internal) continue;
            if (red.address.startsWith('169.254.')) continue;

            return red.address;
        }
    }

    return null;
}

function anunciarEnlaces(ip) {
    const linea = '─'.repeat(58);

    console.log('\n' + linea);
    console.log('  COCIAP 2026 — servidor de pruebas');
    console.log(linea);
    console.log('  En esta maquina : http://localhost:3000/compite');

    if (ip) {
        console.log(`  Para compartir  : http://${ip}:3000/compite`);
        console.log(linea);
        console.log('  Requiere estar en la misma red Wi-Fi y que el puerto');
        console.log('  3000 este permitido en el Firewall de Windows.');
    } else {
        console.log(linea);
        console.log('  Sin red local detectada: no hay enlace para compartir.');
    }

    console.log(linea + '\n');
}

/* ---------------------------------------------------------------------------
 * Utilidades
 * ------------------------------------------------------------------------ */

/**
 * Paso que no hace nada. Permite escribir `produccion ? terser() : noop()`
 * dentro de la cadena de pipes sin romperla.
 */
function noop() {
    return new PassThrough({ objectMode: true });
}

function activarProduccion(hecho) {
    produccion = true;
    hecho();
}

export const build = series(activarProduccion, parallel(css, javascript));

export default series(parallel(css, javascript), dev);
