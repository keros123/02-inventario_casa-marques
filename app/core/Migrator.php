<?php

require_once __DIR__ . '/Database.php';

class Migrator
{
    public static function run(): void
    {
        try {
            $db = Database::getConnection();
        } catch (Throwable $e) {
            return;
        }

        try {
            self::ensureCategoriaIndicador($db);
        } catch (Throwable $e) {
            error_log('Migrator::ensureCategoriaIndicador — ' . $e->getMessage());
        }

        if (Database::isPostgres()) {
            return;
        }

        $steps = [
            'ensureCedulaCuentadante',
            'ensureMovimientoRef',
            'ensureConsecutivo',
            'ensureCategorias',
            'ensureInventarioCategoria',
            'ensureMovimientoFotos',
            'normalizeDarBajaTipos',
            'removeDeprecatedColumns',
            'unifyCuentadantesUsuarios',
            'ensureSolicitudesPrestamo',
        ];

        foreach ($steps as $step) {
            try {
                self::$step($db);
            } catch (Throwable $e) {
                error_log('Migrator::' . $step . ' — ' . $e->getMessage());
            }
        }
    }

    private static function t(string $table): string
    {
        return Database::table($table);
    }

    private static function n(string $table): string
    {
        return Database::tableName($table);
    }

    private static function fk(string $short): string
    {
        $prefix = rtrim(Database::prefix(), '_');
        return 'fk_' . ($prefix !== '' ? $prefix . '_' : '') . $short;
    }

    private static function charset(): string
    {
        $charset = preg_replace('/[^a-z0-9]/i', '', (string) (Database::getConfig()['charset'] ?? 'utf8mb4'));
        return $charset !== '' ? $charset : 'utf8mb4';
    }

    private static function collation(): string
    {
        $collation = preg_replace('/[^a-z0-9_]/i', '', (string) (Database::getConfig()['collation'] ?? 'utf8mb4_unicode_ci'));
        return $collation !== '' ? $collation : 'utf8mb4_unicode_ci';
    }

    private static function ensureConsecutivo(PDO $db): void
    {
        if (!self::tableExists($db, 'Movimientos')) {
            return;
        }

        $columnExists = self::columnExists($db, 'Movimientos', 'Consecutivo');

        if (!$columnExists) {
            $db->exec('ALTER TABLE ' . self::t('Movimientos') . ' ADD COLUMN Consecutivo INT NULL AFTER id_movimiento');
        }

        $db->exec(
            'UPDATE ' . self::t('Movimientos') . " SET Tipo = 'Dar_Baja'
             WHERE (Tipo IS NULL OR Tipo = '') AND id_movimiento_ref IS NOT NULL"
        );

        $types = ['Ingreso', 'Prestamo', 'Devolucion', 'Dar_Baja'];
        foreach ($types as $tipo) {
            $stmt = $db->prepare(
                'SELECT id_movimiento FROM ' . self::t('Movimientos') . ' WHERE Tipo = ? AND Consecutivo IS NULL ORDER BY id_movimiento'
            );
            $stmt->execute([$tipo]);
            $movimientos = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($movimientos)) {
                continue;
            }

            $stmtMax = $db->prepare('SELECT COALESCE(MAX(Consecutivo), 0) FROM ' . self::t('Movimientos') . ' WHERE Tipo = ?');
            $stmtMax->execute([$tipo]);
            $consecutivo = (int) $stmtMax->fetchColumn() + 1;

            foreach ($movimientos as $id) {
                $update = $db->prepare('UPDATE ' . self::t('Movimientos') . ' SET Consecutivo = ? WHERE id_movimiento = ?');
                $update->execute([$consecutivo, $id]);
                $consecutivo++;
            }
        }

        if (!$columnExists) {
            $db->exec('ALTER TABLE ' . self::t('Movimientos') . ' MODIFY COLUMN Consecutivo INT NOT NULL');
        }
    }

    private static function ensureCedulaCuentadante(PDO $db): void
    {
        if (!self::tableExists($db, 'Movimientos') || self::columnExists($db, 'Movimientos', 'Cedula_cuentadante')) {
            return;
        }

        $db->exec(
            'ALTER TABLE ' . self::t('Movimientos') . '
             ADD COLUMN Cedula_cuentadante VARCHAR(20) DEFAULT NULL AFTER Fecha'
        );

        if (!self::constraintExists($db, 'Movimientos', self::fk('mov_cuentadante')) && self::tableExists($db, 'Usuarios')) {
            $db->exec(
                'ALTER TABLE ' . self::t('Movimientos') . '
                 ADD CONSTRAINT `' . self::fk('mov_cuentadante') . '` FOREIGN KEY (Cedula_cuentadante)
                     REFERENCES ' . self::t('Usuarios') . '(Cedula) ON UPDATE CASCADE'
            );
        }
    }

    private static function ensureMovimientoRef(PDO $db): void
    {
        if (!self::tableExists($db, 'Movimientos') || self::columnExists($db, 'Movimientos', 'id_movimiento_ref')) {
            return;
        }

        $db->exec(
            'ALTER TABLE ' . self::t('Movimientos') . '
             ADD COLUMN id_movimiento_ref INT DEFAULT NULL AFTER Estado'
        );

        if (!self::constraintExists($db, 'Movimientos', self::fk('mov_ref'))) {
            $db->exec(
                'ALTER TABLE ' . self::t('Movimientos') . '
                 ADD CONSTRAINT `' . self::fk('mov_ref') . '` FOREIGN KEY (id_movimiento_ref)
                     REFERENCES ' . self::t('Movimientos') . '(id_movimiento) ON DELETE SET NULL'
            );
        }
    }

    private static function ensureCategorias(PDO $db): void
    {
        $charset = self::charset();
        $collation = self::collation();

        $db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::t('Categorias') . " (
                id_categoria INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                Nombre VARCHAR(100) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
                Indicador VARCHAR(20) DEFAULT NULL,
                Estado ENUM('Activo', 'Inactivo', 'Eliminado') NOT NULL DEFAULT 'Activo'
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}"
        );

        $count = (int) $db->query('SELECT COUNT(*) FROM ' . self::t('Categorias'))->fetchColumn();
        if ($count === 0) {
            $db->exec('INSERT INTO ' . self::t('Categorias') . " (Nombre, Estado) VALUES ('General', 'Activo')");
        }
    }

    private static function ensureInventarioCategoria(PDO $db): void
    {
        if (!self::tableExists($db, 'Inventario')) {
            return;
        }

        if (!self::columnExists($db, 'Inventario', 'id_categoria')) {
            $db->exec('ALTER TABLE ' . self::t('Inventario') . ' ADD COLUMN id_categoria INT DEFAULT NULL AFTER Elemento');
        }

        $defaultId = (int) $db->query(
            'SELECT id_categoria FROM ' . self::t('Categorias') . ' WHERE Estado = \'Activo\' ORDER BY id_categoria LIMIT 1'
        )->fetchColumn();

        if ($defaultId > 0) {
            $update = $db->prepare('UPDATE ' . self::t('Inventario') . ' SET id_categoria = ? WHERE id_categoria IS NULL');
            $update->execute([$defaultId]);
        }

        if (
            !self::constraintExists($db, 'Inventario', self::fk('inv_categoria'))
            && !self::columnHasFk($db, 'Inventario', 'id_categoria')
        ) {
            $db->exec(
                'ALTER TABLE ' . self::t('Inventario') . '
                 ADD CONSTRAINT `' . self::fk('inv_categoria') . '` FOREIGN KEY (id_categoria)
                     REFERENCES ' . self::t('Categorias') . '(id_categoria) ON UPDATE CASCADE'
            );
        }
    }

    private static function ensureMovimientoFotos(PDO $db): void
    {
        if (!self::tableExists($db, 'Movimientos')) {
            return;
        }

        $charset = self::charset();
        $collation = self::collation();
        $fk = self::fk('foto_movimiento');

        $db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::t('Movimiento_Fotos') . " (
                id_foto INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                id_movimiento INT NOT NULL,
                Ruta VARCHAR(255) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
                Orden TINYINT NOT NULL DEFAULT 1,
                CONSTRAINT `{$fk}` FOREIGN KEY (id_movimiento)
                    REFERENCES " . self::t('Movimientos') . '(id_movimiento) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=' . $charset . ' COLLATE=' . $collation
        );
    }

    private static function normalizeDarBajaTipos(PDO $db): void
    {
        if (!self::tableExists($db, 'Movimientos')) {
            return;
        }

        $db->exec(
            'ALTER TABLE ' . self::t('Movimientos') . " MODIFY COLUMN Tipo
             ENUM('Ingreso', 'Prestamo', 'Devolucion', 'Dar_Baja') NOT NULL"
        );

        $db->exec(
            'UPDATE ' . self::t('Movimientos') . " SET Tipo = 'Dar_Baja'
             WHERE LOWER(REPLACE(REPLACE(REPLACE(Tipo, ' ', '_'), '-', '_'), '.', ''))
                   IN ('dar_baja', 'dardebaja', 'darde_baja', 'dar_de_baja')"
        );
    }

    private static function removeDeprecatedColumns(PDO $db): void
    {
        if (self::columnExists($db, 'Inventario', 'Consumible')) {
            $db->exec('ALTER TABLE ' . self::t('Inventario') . ' DROP COLUMN Consumible');
        }

        if (self::columnExists($db, 'Movimientos', 'Uso')) {
            $db->exec('ALTER TABLE ' . self::t('Movimientos') . ' DROP COLUMN Uso');
        }

        if (self::columnExists($db, 'Movimientos', 'Ficha')) {
            $db->exec('ALTER TABLE ' . self::t('Movimientos') . ' DROP COLUMN Ficha');
        }
    }

    private static function ensureCategoriaIndicador(PDO $db): void
    {
        if (!self::tableExists($db, 'Categorias')) {
            return;
        }

        if (self::columnExists($db, 'Categorias', 'Indicador')) {
            return;
        }

        if (Database::isPostgres()) {
            $db->exec(
                'ALTER TABLE ' . self::t('Categorias')
                . ' ADD COLUMN IF NOT EXISTS "Indicador" VARCHAR(20)'
            );
            return;
        }

        $db->exec(
            'ALTER TABLE ' . self::t('Categorias')
            . ' ADD COLUMN Indicador VARCHAR(20) NULL DEFAULT NULL AFTER Nombre'
        );
    }

    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        if (Database::isPostgres()) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = ?
                   AND column_name = ?'
            );
            $stmt->execute([self::n($table), $column]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([self::n($table), $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function tableExists(PDO $db, string $table): bool
    {
        if (Database::isPostgres()) {
            $stmt = $db->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = current_schema()
                   AND table_name = ?'
            );
            $stmt->execute([self::n($table)]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?)'
        );
        $stmt->execute([self::n($table)]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function constraintExists(PDO $db, string $table, string $constraint): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?'
        );
        $stmt->execute([self::n($table), $constraint]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnHasFk(PDO $db, string $table, string $column): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL'
        );
        $stmt->execute([self::n($table), $column]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function fkReferencesTable(PDO $db, string $table, string $constraint, string $referencedTable): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND REFERENCED_TABLE_NAME = ?'
        );
        $stmt->execute([self::n($table), $constraint, self::n($referencedTable)]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function unifyCuentadantesUsuarios(PDO $db): void
    {
        if (!self::tableExists($db, 'Usuarios')) {
            return;
        }

        if (self::tableExists($db, 'Cuentadantes')) {
            $cuentadantes = $db->query(
                'SELECT Cedula, Nombres, Estado FROM ' . self::t('Cuentadantes')
            )->fetchAll(PDO::FETCH_ASSOC);
            $insert = $db->prepare(
                'INSERT INTO ' . self::t('Usuarios') . ' (Cedula, Nombres, Password, Tipo, Estado)
                 VALUES (?, ?, ?, \'Usuario\', ?)'
            );

            foreach ($cuentadantes as $c) {
                $check = $db->prepare('SELECT 1 FROM ' . self::t('Usuarios') . ' WHERE Cedula = ? LIMIT 1');
                $check->execute([$c['Cedula']]);
                if ($check->fetch()) {
                    continue;
                }

                $insert->execute([
                    $c['Cedula'],
                    $c['Nombres'],
                    password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                    $c['Estado'],
                ]);
            }
        }

        if (!self::tableExists($db, 'Movimientos')) {
            return;
        }

        $fkName = self::fk('mov_cuentadante');
        if (self::fkReferencesTable($db, 'Movimientos', $fkName, 'Cuentadantes')
            || self::fkReferencesTable($db, 'Movimientos', 'fk_mov_cuentadante', 'Cuentadantes')
        ) {
            self::ensureMovimientosCedulasEnUsuarios($db);
            $oldFk = self::fkReferencesTable($db, 'Movimientos', $fkName, 'Cuentadantes') ? $fkName : 'fk_mov_cuentadante';
            $db->exec('ALTER TABLE ' . self::t('Movimientos') . ' DROP FOREIGN KEY `' . $oldFk . '`');
            $db->exec(
                'ALTER TABLE ' . self::t('Movimientos') . '
                 ADD CONSTRAINT `' . $fkName . '` FOREIGN KEY (Cedula_cuentadante)
                     REFERENCES ' . self::t('Usuarios') . '(Cedula) ON UPDATE CASCADE'
            );
        } elseif (
            !self::fkReferencesTable($db, 'Movimientos', $fkName, 'Usuarios')
            && !self::columnHasFk($db, 'Movimientos', 'Cedula_cuentadante')
            && self::columnExists($db, 'Movimientos', 'Cedula_cuentadante')
        ) {
            self::ensureMovimientosCedulasEnUsuarios($db);
            $db->exec(
                'ALTER TABLE ' . self::t('Movimientos') . '
                 ADD CONSTRAINT `' . $fkName . '` FOREIGN KEY (Cedula_cuentadante)
                     REFERENCES ' . self::t('Usuarios') . '(Cedula) ON UPDATE CASCADE'
            );
        }

        if (self::tableExists($db, 'Cuentadantes')) {
            try {
                $db->exec('DROP TABLE ' . self::t('Cuentadantes'));
            } catch (Throwable $e) {
                error_log('Migrator::unifyCuentadantesUsuarios DROP Cuentadantes — ' . $e->getMessage());
            }
        }
    }

    private static function ensureMovimientosCedulasEnUsuarios(PDO $db): void
    {
        $cedulas = $db->query(
            'SELECT DISTINCT Cedula_cuentadante FROM ' . self::t('Movimientos') . '
             WHERE Cedula_cuentadante IS NOT NULL AND Cedula_cuentadante != \'\''
        )->fetchAll(PDO::FETCH_COLUMN);

        $insert = $db->prepare(
            'INSERT INTO ' . self::t('Usuarios') . ' (Cedula, Nombres, Password, Tipo, Estado)
             VALUES (?, ?, ?, \'Usuario\', \'Activo\')'
        );

        foreach ($cedulas as $cedula) {
            $check = $db->prepare('SELECT 1 FROM ' . self::t('Usuarios') . ' WHERE Cedula = ? LIMIT 1');
            $check->execute([$cedula]);
            if ($check->fetch()) {
                continue;
            }

            $insert->execute([
                $cedula,
                'Responsable ' . $cedula,
                password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            ]);
        }
    }

    private static function ensureSolicitudesPrestamo(PDO $db): void
    {
        if (!self::tableExists($db, 'Usuarios') || !self::tableExists($db, 'Movimientos') || !self::tableExists($db, 'Inventario')) {
            return;
        }

        $charset = self::charset();
        $collation = self::collation();

        if (!self::tableExists($db, 'Solicitudes_Prestamo')) {
            $db->exec(
                'CREATE TABLE ' . self::t('Solicitudes_Prestamo') . " (
                    id_solicitud INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    Cedula_solicitante VARCHAR(20) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
                    Fecha_solicitud DATE NOT NULL,
                    Descripcion TEXT CHARACTER SET {$charset} COLLATE {$collation},
                    Estado ENUM('Pendiente', 'Aprobada', 'Rechazada', 'Cancelada')
                        NOT NULL DEFAULT 'Pendiente',
                    id_movimiento INT DEFAULT NULL,
                    Cedula_aprobador VARCHAR(20) CHARACTER SET {$charset} COLLATE {$collation} DEFAULT NULL,
                    Fecha_resolucion DATETIME DEFAULT NULL,
                    Motivo_rechazo TEXT CHARACTER SET {$charset} COLLATE {$collation} DEFAULT NULL,
                    CONSTRAINT `" . self::fk('sol_solicitante') . '` FOREIGN KEY (Cedula_solicitante)
                        REFERENCES ' . self::t('Usuarios') . '(Cedula) ON UPDATE CASCADE,
                    CONSTRAINT `' . self::fk('sol_movimiento') . '` FOREIGN KEY (id_movimiento)
                        REFERENCES ' . self::t('Movimientos') . '(id_movimiento) ON DELETE SET NULL,
                    CONSTRAINT `' . self::fk('sol_aprobador') . '` FOREIGN KEY (Cedula_aprobador)
                        REFERENCES ' . self::t('Usuarios') . "(Cedula) ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}"
            );
        }

        if (!self::tableExists($db, 'Det_Solicitudes')) {
            $db->exec(
                'CREATE TABLE ' . self::t('Det_Solicitudes') . " (
                    id_detalle INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    id_solicitud INT NOT NULL,
                    id_linea INT NOT NULL DEFAULT 1,
                    Codigo_elemento VARCHAR(50) CHARACTER SET {$charset} COLLATE {$collation} NOT NULL,
                    Cantidad INT NOT NULL DEFAULT 1,
                    CONSTRAINT `" . self::fk('detsol_solicitud') . '` FOREIGN KEY (id_solicitud)
                        REFERENCES ' . self::t('Solicitudes_Prestamo') . '(id_solicitud) ON DELETE CASCADE,
                    CONSTRAINT `' . self::fk('detsol_inventario') . '` FOREIGN KEY (Codigo_elemento)
                        REFERENCES ' . self::t('Inventario') . "(Codigo) ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}"
            );
        }
    }
}
