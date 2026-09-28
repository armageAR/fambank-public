<?php

return [
    'name' => env('APP_NAME', 'Laravel'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'America/Argentina/Buenos_Aires',
    'locale' => env('APP_LOCALE', 'es'),
    // Sin directorio lang/ no hay traducciones es: el fallback debe ser 'en'
    // para que las claves sin traducir muestren el texto del framework en
    // inglés en vez de la clave cruda ("validation.required").
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'es_AR'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [...array_filter(explode(',', env('APP_PREVIOUS_KEYS', '')))],
    'maintenance'   => ['driver' => env('APP_MAINTENANCE_DRIVER', 'file')],
    'vapid_public_key'  => env('VAPID_PUBLIC_KEY'),
    'vapid_private_key' => env('VAPID_PRIVATE_KEY'),
    'vapid_subject'     => env('VAPID_SUBJECT', 'mailto:admin@fambank.ar'),
    'frontend_url'      => env('FRONTEND_URL', 'http://localhost:5173'),
];
