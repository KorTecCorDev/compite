<?php

declare(strict_types=1);

namespace Core;

/**
 * Normalización de texto para PRESENTAR, nunca para guardar.
 *
 * Existe por una razón concreta (D-58): unos usuarios teclean «RODRIGUEZ
 * CAMILO» y otros «Rodriguez Camilo», y el mismo estudiante salía distinto en
 * el carné y en el acta —dos documentos oficiales del mismo concurso—.
 *
 * **Por qué al mostrar y no al guardar.** Se midió sobre los datos reales antes
 * de decidir: de 114 participantes, 112 estaban ya en «Tipo Título» y **uno
 * solo** en mayúsculas. Normalizar la base habría reescrito 112 filas correctas
 * para acomodar a una, y sobre todo habría sido **irreversible**: en los datos
 * hay apellidos como «De la Cruz», «De Moreno» y «De Loli», bien escritos, que
 * ninguna capitalización automática sabe reconstruir —`MB_CASE_TITLE` los
 * devuelve como «De La Cruz», y a `IE` lo convierte en `Ie`—.
 *
 * Al mostrar, en cambio, `mb_strtoupper()` **no puede equivocarse**: «DE LA
 * CRUZ» es correcto, y aplicarlo dos veces da lo mismo. Los originales siguen
 * en la base por si algún día se prefiere otra forma.
 *
 * Es también la convención de los documentos de identidad peruanos, que es
 * donde este texto acaba: un carné y un acta.
 */
final class Texto
{
    /**
     * Un nombre propio —persona o institución— tal como debe verse en un
     * documento del concurso.
     *
     * Además de las mayúsculas colapsa los espacios repetidos: «Juan  Pérez»
     * escrito con dos espacios sale igual que con uno. Eso no corrige el dato
     * guardado, solo evita que un descuido de tecleo se imprima.
     */
    public static function nombrePropio(?string $texto): string
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', (string) $texto));

        return mb_strtoupper($limpio, 'UTF-8');
    }
}
