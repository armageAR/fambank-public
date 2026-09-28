<?php

namespace App\Support;

/**
 * Aritmética monetaria con precisión decimal exacta (bcmath).
 *
 * Todos los montos circulan como strings de 2 decimales ("1234.50"),
 * el mismo formato que devuelven las columnas decimal de la DB.
 * Evita la deriva de centavos de operar montos con floats y elimina
 * comparaciones con epsilon.
 */
final class Money
{
    /** Decimales intermedios antes de redondear el resultado de una división. */
    private const DIV_SCALE = 6;

    /**
     * Convierte pesos a dólares al TC dado, redondeado half-up a 2 decimales.
     * Único punto del sistema donde se calcula ARS → USD: alta y confirmación
     * deben redondear exactamente igual.
     */
    public static function arsToUsd(string|int|float $ars, string|int|float $rate): string
    {
        return self::round(bcdiv(self::str($ars), self::str($rate), self::DIV_SCALE));
    }

    /** Redondeo half-up a 2 decimales. Solo para montos no negativos. */
    public static function round(string $amount): string
    {
        return bcadd($amount, '0.005', 2);
    }

    /** $a - $b a 2 decimales (puede ser negativo). */
    public static function sub(string|int|float $a, string|int|float $b): string
    {
        return bcsub(self::str($a), self::str($b), 2);
    }

    /** true si $a > $b (comparando a 2 decimales). */
    public static function greaterThan(string|int|float $a, string|int|float $b): bool
    {
        return bccomp(self::str($a), self::str($b), 2) === 1;
    }

    /** true si el monto es cero a 2 decimales. */
    public static function isZero(string|int|float $amount): bool
    {
        return bccomp(self::str($amount), '0', 2) === 0;
    }

    /**
     * Normaliza a string decimal para bcmath. Los floats se formatean con
     * precisión fija porque (string) puede producir notación científica.
     */
    private static function str(string|int|float $value): string
    {
        if (is_float($value)) {
            return number_format($value, self::DIV_SCALE, '.', '');
        }

        return (string) $value;
    }
}
