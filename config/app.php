<?php

require_once __DIR__ . '/../app/core/App.php';
require_once __DIR__ . '/../app/core/Env.php';

Env::load();
date_default_timezone_set(Env::get('DB_TIMEZONE', 'America/Bogota'));

return [
    'name'              => 'Casa del Marques',
    'base_url'          => App::baseUrl(),
    'timezone'          => Env::get('DB_TIMEZONE', 'America/Bogota'),
    'upload_dir'        => __DIR__ . '/../public/uploads/inventario/',
    'upload_dar_baja'   => __DIR__ . '/../public/uploads/dar-baja/',
];
