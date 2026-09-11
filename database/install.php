<?php

/**
 * Crea el esquema de Inventario Casa del Marqués en MySQL.
 * Lee prefijo, cotejamiento y zona horaria desde .env
 *
 * Uso: php database/install.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/core/Env.php';
require_once dirname(__DIR__) . '/app/core/Database.php';

Env::load();
date_default_timezone_set(Env::get('DB_TIMEZONE', 'America/Bogota'));

$config = Database::getConfig();
$prefix = Database::prefix();
$charset = preg_replace('/[^a-z0-9]/i', '', (string) $config['charset']) ?: 'utf8mb4';
$collation = preg_replace('/[^a-z0-9_]/i', '', (string) $config['collation']) ?: 'utf8mb4_unicode_ci';
$dbname = $config['dbname'];
$tzOffset = Database::mysqlTimezoneOffset((string) $config['timezone']);
$fk = static function (string $short) use ($prefix): string {
    $p = rtrim($prefix, '_');
    return 'fk_' . ($p !== '' ? $p . '_' : '') . $short;
};
$t = static function (string $name) use ($prefix): string {
    return '`' . str_replace('`', '``', $prefix . $name) . '`';
};

$dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $config['host'], $config['port'], $charset);
$pdo = new PDO($dsn, $config['username'], $config['password'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "Zona horaria: {$config['timezone']} ({$tzOffset})\n";
echo "Cotejamiento: {$charset} / {$collation}\n";
echo "Prefijo: {$prefix}\n";
echo "Base de datos: {$dbname}\n\n";

$pdo->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
    str_replace('`', '``', $dbname),
    $charset,
    $collation
));
$pdo->exec(sprintf(
    'ALTER DATABASE `%s` CHARACTER SET %s COLLATE %s',
    str_replace('`', '``', $dbname),
    $charset,
    $collation
));
$pdo->exec('USE `' . str_replace('`', '``', $dbname) . '`');
$pdo->exec(sprintf('SET NAMES %s COLLATE %s', $charset, $collation));
$pdo->exec(sprintf("SET time_zone = '%s'", $tzOffset));
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

$engine = "ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}";

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS ' . $t('Usuarios') . " (
        Cedula VARCHAR(20) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL PRIMARY KEY,
        Nombres VARCHAR(150) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
        Password VARCHAR(255) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
        Tipo ENUM('Admin', 'Usuario') NOT NULL DEFAULT 'Usuario',
        Estado ENUM('Activo', 'Inactivo') NOT NULL DEFAULT 'Activo'
    ) {$engine}"
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS ' . $t('Categorias') . " (
        id_categoria INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        Nombre VARCHAR(100) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
        Indicador VARCHAR(20) DEFAULT NULL,
        Estado ENUM('Activo', 'Inactivo', 'Eliminado') NOT NULL DEFAULT 'Activo'
    ) {$engine}"
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS ' . $t('Inventario') . " (
        Codigo VARCHAR(50) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL PRIMARY KEY,
        Elemento VARCHAR(150) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
        id_categoria INT DEFAULT NULL,
        Descripcion TEXT CHARACTER SET {$charset} COLLATE {$collation},
        Cantidad INT NOT NULL DEFAULT 0,
        Fotografia VARCHAR(255) CHARACTER SET {$charset} COLLATE {$collation} DEFAULT NULL,
        Estado ENUM('Activo', 'Inactivo', 'Eliminado') NOT NULL DEFAULT 'Activo',
        CONSTRAINT `" . $fk('inv_categoria') . '` FOREIGN KEY (id_categoria)
            REFERENCES ' . $t('Categorias') . "(id_categoria) ON UPDATE CASCADE
    ) {$engine}"
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS ' . $t('Movimientos') . " (
        id_movimiento INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        Tipo ENUM('Ingreso', 'Prestamo', 'Devolucion', 'Dar_Baja', 'Consumo') NOT NULL,
        Consecutivo INT NOT NULL,
        Fecha DATE NOT NULL,
        Cedula_cuentadante VARCHAR(20) CHARACTER SET {$charset} COLLATE {$collation} DEFAULT NULL,
        Descripcion TEXT CHARACTER SET {$charset} COLLATE {$collation},
        Estado ENUM('Activo', 'Inactivo', 'Cerrado') NOT NULL DEFAULT 'Activo',
        id_movimiento_ref INT DEFAULT NULL,
        CONSTRAINT `" . $fk('mov_cuentadante') . '` FOREIGN KEY (Cedula_cuentadante)
            REFERENCES ' . $t('Usuarios') . '(Cedula) ON UPDATE CASCADE,
        CONSTRAINT `' . $fk('mov_ref') . '` FOREIGN KEY (id_movimiento_ref)
            REFERENCES ' . $t('Movimientos') . "(id_movimiento) ON DELETE SET NULL
    ) {$engine}"
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS ' . $t('Det_Movimientos') . " (
        Consecutivo INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_movimiento INT NOT NULL,
        id_Detalle INT NOT NULL DEFAULT 1,
        Codigo_elemento VARCHAR(50) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
        Cantidad INT NOT NULL DEFAULT 1,
        Estado ENUM('Activo', 'Inactivo') NOT NULL DEFAULT 'Activo',
        CONSTRAINT `" . $fk('det_movimiento') . '` FOREIGN KEY (id_movimiento)
            REFERENCES ' . $t('Movimientos') . '(id_movimiento) ON DELETE CASCADE,
        CONSTRAINT `' . $fk('det_inventario') . '` FOREIGN KEY (Codigo_elemento)
            REFERENCES ' . $t('Inventario') . "(Codigo) ON UPDATE CASCADE
    ) {$engine}"
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS ' . $t('Movimiento_Fotos') . " (
        id_foto INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_movimiento INT NOT NULL,
        Ruta VARCHAR(255) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
        Orden TINYINT NOT NULL DEFAULT 1,
        CONSTRAINT `" . $fk('foto_movimiento') . '` FOREIGN KEY (id_movimiento)
            REFERENCES ' . $t('Movimientos') . "(id_movimiento) ON DELETE CASCADE
    ) {$engine}"
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS ' . $t('Solicitudes_Prestamo') . " (
        id_solicitud INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        Cedula_solicitante VARCHAR(20) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
        Fecha_solicitud DATE NOT NULL,
        Descripcion TEXT CHARACTER SET {$charset} COLLATE {$collation},
        Estado ENUM('Pendiente', 'Aprobada', 'Rechazada', 'Cancelada') NOT NULL DEFAULT 'Pendiente',
        id_movimiento INT DEFAULT NULL,
        Cedula_aprobador VARCHAR(20) CHARACTER SET {$charset} COLLATE {$collation} DEFAULT NULL,
        Fecha_resolucion DATETIME DEFAULT NULL,
        Motivo_rechazo TEXT CHARACTER SET {$charset} COLLATE {$collation} DEFAULT NULL,
        CONSTRAINT `" . $fk('sol_solicitante') . '` FOREIGN KEY (Cedula_solicitante)
            REFERENCES ' . $t('Usuarios') . '(Cedula) ON UPDATE CASCADE,
        CONSTRAINT `' . $fk('sol_movimiento') . '` FOREIGN KEY (id_movimiento)
            REFERENCES ' . $t('Movimientos') . '(id_movimiento) ON DELETE SET NULL,
        CONSTRAINT `' . $fk('sol_aprobador') . '` FOREIGN KEY (Cedula_aprobador)
            REFERENCES ' . $t('Usuarios') . "(Cedula) ON UPDATE CASCADE
    ) {$engine}"
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS ' . $t('Det_Solicitudes') . " (
        id_detalle INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_solicitud INT NOT NULL,
        id_linea INT NOT NULL DEFAULT 1,
        Codigo_elemento VARCHAR(50) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
        Cantidad INT NOT NULL DEFAULT 1,
        CONSTRAINT `" . $fk('detsol_solicitud') . '` FOREIGN KEY (id_solicitud)
            REFERENCES ' . $t('Solicitudes_Prestamo') . '(id_solicitud) ON DELETE CASCADE,
        CONSTRAINT `' . $fk('detsol_inventario') . '` FOREIGN KEY (Codigo_elemento)
            REFERENCES ' . $t('Inventario') . "(Codigo) ON UPDATE CASCADE
    ) {$engine}"
);

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

$copied = copyUnprefixedIfNeeded($pdo, $prefix);
if (!$copied) {
    seedIfEmpty($pdo, $t);
}
dropUnprefixedTables($pdo, $prefix);

echo "Tablas creadas:\n";
$stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($prefix . '%'));
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
    echo "  - {$table}\n";
}

echo "\nInstalación completada.\n";
echo "Usuario admin / contraseña 123456 (si se insertaron datos iniciales).\n";

function tableExists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$name]);
    return (int) $stmt->fetchColumn() > 0;
}

function copyUnprefixedIfNeeded(PDO $pdo, string $prefix): bool
{
    if ($prefix === '' || !tableExists($pdo, 'Usuarios') || !tableExists($pdo, $prefix . 'Usuarios')) {
        return false;
    }

    $countPrefixed = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $prefix . 'Usuarios') . '`')->fetchColumn();
    $countOld = (int) $pdo->query('SELECT COUNT(*) FROM `Usuarios`')->fetchColumn();
    if ($countPrefixed > 0 || $countOld === 0) {
        return false;
    }

    echo "Copiando datos desde tablas sin prefijo...\n";
    $pairs = [
        'Usuarios' => 'Usuarios',
        'Categorias' => 'Categorias',
        'Inventario' => 'Inventario',
        'Movimientos' => 'Movimientos',
        'Det_Movimientos' => 'Det_Movimientos',
        'Movimiento_Fotos' => 'Movimiento_Fotos',
        'Solicitudes_Prestamo' => 'Solicitudes_Prestamo',
        'Det_Solicitudes' => 'Det_Solicitudes',
    ];

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pairs as $source => $dest) {
        if (!tableExists($pdo, $source) || !tableExists($pdo, $prefix . $dest)) {
            continue;
        }
        $src = '`' . str_replace('`', '``', $source) . '`';
        $dst = '`' . str_replace('`', '``', $prefix . $dest) . '`';
        $pdo->exec("INSERT INTO {$dst} SELECT * FROM {$src}");
        echo "  {$source} -> {$prefix}{$dest}\n";
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    return true;
}

function dropUnprefixedTables(PDO $pdo, string $prefix): void
{
    if ($prefix === '') {
        return;
    }

    $legacy = [
        'Det_Solicitudes',
        'Solicitudes_Prestamo',
        'Movimiento_Fotos',
        'Det_Movimientos',
        'Movimientos',
        'Inventario',
        'Categorias',
        'Usuarios',
        'Cuentadantes',
    ];

    if (!tableExists($pdo, $prefix . 'Usuarios')) {
        return;
    }

    $prefixedUsers = (int) $pdo->query(
        'SELECT COUNT(*) FROM `' . str_replace('`', '``', $prefix . 'Usuarios') . '`'
    )->fetchColumn();
    if ($prefixedUsers < 1) {
        return;
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($legacy as $table) {
        if (str_starts_with($table, $prefix) || !tableExists($pdo, $table)) {
            continue;
        }
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
        echo "Eliminada tabla sin prefijo: {$table}\n";
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function seedIfEmpty(PDO $pdo, callable $t): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM ' . $t('Usuarios'))->fetchColumn();
    if ($count > 0) {
        echo "Las tablas ya tienen datos; no se insertan semillas.\n";
        return;
    }

    echo "Insertando datos iniciales...\n";
    $hash = password_hash('123456', PASSWORD_DEFAULT);

    $pdo->exec('INSERT INTO ' . $t('Categorias') . " (Nombre, Indicador, Estado) VALUES ('General', 'No Consumible', 'Activo')");

    $stmt = $pdo->prepare(
        'INSERT INTO ' . $t('Usuarios') . ' (Cedula, Nombres, Password, Tipo, Estado) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute(['admin', 'Administrador Sistema', $hash, 'Admin', 'Activo']);
    $stmt->execute(['9876543210', 'Juan Pérez García', $hash, 'Usuario', 'Activo']);
    $stmt->execute(['1122334455', 'María López Ruiz', $hash, 'Usuario', 'Activo']);

    $pdo->exec(
        'INSERT INTO ' . $t('Inventario') . " (Codigo, Elemento, id_categoria, Descripcion, Cantidad, Estado) VALUES
        ('INV-001', 'Portátil Dell Latitude', 1, 'Equipo portátil para formación', 5, 'Activo'),
        ('INV-002', 'Cable HDMI 2m', 1, 'Cable HDMI estándar', 20, 'Activo'),
        ('INV-003', 'Proyector Epson', 1, 'Proyector sala B105', 2, 'Activo')"
    );
}
