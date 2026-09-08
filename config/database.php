<?php

require_once __DIR__ . '/../app/core/Env.php';

Env::load();

$supabaseHost = Env::get('SUPABASE_DB_HOST');
$useSupabase = $supabaseHost !== '';

return [
    'driver'    => Env::get('DB_DRIVER', $useSupabase ? 'pgsql' : 'mysql'),
    'host'      => $useSupabase ? $supabaseHost : Env::get('DB_HOST', '127.0.0.1'),
    'port'      => (int) ($useSupabase ? Env::get('SUPABASE_DB_PORT', '6543') : Env::get('DB_PORT', '3306')),
    'dbname'    => $useSupabase ? Env::get('SUPABASE_DB_NAME', 'postgres') : Env::get('DB_NAME', 'Inventario_cm'),
    'username'  => $useSupabase ? Env::get('SUPABASE_DB_USER') : Env::get('DB_USER', 'admin'),
    'password'  => $useSupabase ? Env::get('SUPABASE_DB_PASSWORD') : Env::get('DB_PASSWORD', 'admin123'),
    'sslmode'   => Env::get('DB_SSLMODE', $useSupabase ? 'require' : 'disable'),
    'charset'   => Env::get('DB_CHARSET', 'utf8mb4'),
    'collation' => Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix'    => Env::get('DB_PREFIX', 'casa_marques'),
    'timezone'  => Env::get('DB_TIMEZONE', 'America/Bogota'),
];
