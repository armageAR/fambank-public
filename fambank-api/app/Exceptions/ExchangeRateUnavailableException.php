<?php

namespace App\Exceptions;

use RuntimeException;

class ExchangeRateUnavailableException extends RuntimeException
{
    public function __construct(string $reason = '', string $type = 'blue')
    {
        parent::__construct(
            "No se pudo obtener el tipo de cambio {$type}."
            . ($reason !== '' ? " Detalle: {$reason}" : '')
        );
    }
}
