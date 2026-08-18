<?php

declare(strict_types=1);

/**
 * Genera la marca de agua del carné a partir del logo de aniversario.
 *
 *     php scripts/generar_marca_agua.php
 *
 * La transparencia se **hornea en el PNG** en vez de pedirse por CSS. Dompdf
 * soporta `opacity` de forma parcial y desigual entre versiones: con el alfa ya
 * aplicado en el archivo, lo que se ve en pantalla es exactamente lo que sale
 * de la impresora, hoy y dentro de dos versiones.
 *
 * El derivado se versiona junto al original, igual que `logo-cociap.png`, para
 * que el despliegue no dependa de que alguien recuerde ejecutar este script.
 * Se vuelve a ejecutar solo si cambia el logo o la opacidad.
 */

$raiz    = dirname(__DIR__);
$origen  = $raiz . '/resources/img/logoaniversario2026-original.png';
$destino = $raiz . '/public/img/marca-agua-carne.png';

/*
 * 10%. El carné imprime rótulos de 4.6 pt: por encima del 12% la marca empieza
 * a competir con ellos y el dato deja de leerse de un vistazo en la puerta, que
 * es lo único para lo que sirve el carné. Por debajo del 7% no se distingue del
 * papel y no aporta nada.
 */
const OPACIDAD = 0.10;

/*
 * La marca se imprime a ~71 mm de ancho. A 300 ppp eso son 838 px, pero el
 * original mide 711: se deja en su tamaño nativo. Ampliarlo no añadiría detalle,
 * solo peso, y el peso aquí se multiplica por las diez veces que la hoja A4
 * repite la imagen.
 */

if (!is_file($origen)) {
    fwrite(STDERR, "No se encontró el logo original en {$origen}\n");
    exit(1);
}

$imagen = imagecreatefrompng($origen);

if ($imagen === false) {
    fwrite(STDERR, "No se pudo leer {$origen} como PNG\n");
    exit(1);
}

$ancho = imagesx($imagen);
$alto  = imagesy($imagen);

$salida = imagecreatetruecolor($ancho, $alto);
imagealphablending($salida, false);
imagesavealpha($salida, true);
imagefill($salida, 0, 0, imagecolorallocatealpha($salida, 255, 255, 255, 127));

/*
 * Se recorre píxel a píxel en vez de usar un filtro de GD porque ninguno de los
 * filtros disponibles toca el canal alfa: `imagefilter()` sabe aclarar el color,
 * pero aclarar no es lo mismo que hacer transparente —lo primero deja un
 * rectángulo blanquecino sobre el papel, lo segundo deja ver el papel.
 */
for ($y = 0; $y < $alto; $y++) {
    for ($x = 0; $x < $ancho; $x++) {
        $color = imagecolorat($imagen, $x, $y);

        $alfa = ($color >> 24) & 0x7F;

        // 0 = opaco, 127 = invisible. Un píxel ya transparente en el original
        // (el fondo alrededor del logo) tiene que seguir siéndolo del todo.
        $nuevoAlfa = (int) round(127 - (127 - $alfa) * OPACIDAD);

        imagesetpixel($salida, $x, $y, imagecolorallocatealpha(
            $salida,
            ($color >> 16) & 0xFF,
            ($color >> 8) & 0xFF,
            $color & 0xFF,
            $nuevoAlfa
        ));
    }
}

imagepng($salida, $destino, 9);
imagedestroy($imagen);
imagedestroy($salida);

printf(
    "%s · %d × %d px · opacidad %d%% · %d KB\n",
    basename($destino),
    $ancho,
    $alto,
    (int) round(OPACIDAD * 100),
    (int) round(filesize($destino) / 1024)
);
