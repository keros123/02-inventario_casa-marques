<?php

/**
 * Crea el esquema vacío en Supabase (PostgreSQL).
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

$source = __DIR__ . DIRECTORY_SEPARATOR . 'install_postgres.sql';
$raw = file_get_contents($source);
if ($raw === false) {
    fwrite(STDERR, "No se pudo leer {$source}\n");
    exit(1);
}

$sql = '';
foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
    $trim = ltrim($line);
    if ($trim === '' || str_starts_with($trim, '--')) {
        continue;
    }
    if (str_starts_with($trim, '\\')) {
        continue;
    }
    if (str_contains($trim, 'CREATE DATABASE') || str_contains($trim, '\\gexec')) {
        continue;
    }
    $sql .= $line . "\n";
}

$pdo = Database::getConnection();
$pdo->exec('SET search_path TO public');

$statements = [];
$buffer = '';
$inString = false;
$length = strlen($sql);
for ($i = 0; $i < $length; $i++) {
    $ch = $sql[$i];
    $buffer .= $ch;
    if ($ch === "'" && ($i === 0 || $sql[$i - 1] !== '\\')) {
        $inString = !$inString;
    }
    if ($ch === ';' && !$inString) {
        $stmt = trim($buffer);
        if ($stmt !== '' && $stmt !== ';') {
            $statements[] = $stmt;
        }
        $buffer = '';
    }
}

echo "Aplicando " . count($statements) . " sentencias en Supabase...\n";

foreach ($statements as $index => $statement) {
    try {
        $pdo->exec($statement);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'already exists')) {
            echo "  (" . ($index + 1) . ") ya existía, se omite\n";
            continue;
        }
        fwrite(STDERR, "Error en sentencia " . ($index + 1) . ": " . $e->getMessage() . "\n");
        exit(1);
    }
}

$prefix = Database::prefix();
echo "Esquema listo. Tablas {$prefix}* en " . Env::get('SUPABASE_DB_HOST') . "\n";
echo "Usuario: admin / 123456\n";
