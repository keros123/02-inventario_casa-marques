<?php

/**
 * Crea el esquema vacío en Supabase (PostgreSQL).
 * Lee docs/crear_db_supabase.sql e inyecta DB_PREFIX de .env.
 *
 * Uso: php database/install_supabase.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/core/Env.php';
require_once dirname(__DIR__) . '/app/core/Database.php';

Env::load();
date_default_timezone_set(Env::get('DB_TIMEZONE', 'America/Bogota'));

if (!Database::isPostgres()) {
    fwrite(STDERR, "DB_DRIVER no es pgsql. Revise SUPABASE_DB_* en .env\n");
    exit(1);
}

$source = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'crear_db_supabase.sql';
$sql = file_get_contents($source);
if ($sql === false) {
    fwrite(STDERR, "No se pudo leer {$source}\n");
    exit(1);
}

$prefix = Database::prefix();
$replaced = preg_replace(
    "/prefijo\\s+text\\s+:=\\s+'[^']*'/i",
    "prefijo text := '" . str_replace("'", "''", $prefix) . "'",
    $sql,
    1,
    $count
);
if ($replaced !== null && $count > 0) {
    $sql = $replaced;
}

$pdo = Database::getConnection();
$pdo->exec('SET search_path TO public');

try {
    $pdo->exec($sql);
} catch (PDOException $e) {
    fwrite(STDERR, "Error al aplicar el esquema: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Esquema listo. Prefijo: {$prefix}\n";
echo "Tablas en " . Env::get('SUPABASE_DB_HOST') . "\n";
echo "Usuario: admin / 123456\n";
echo "Categoría por defecto: General · No Consumible\n";
