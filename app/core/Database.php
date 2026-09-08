<?php

require_once __DIR__ . '/Env.php';

class DatabasePDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(Database::adaptSql($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec(Database::adaptSql($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $adapted = Database::adaptSql($query);
        if ($fetchMode === null) {
            return parent::query($adapted);
        }
        return parent::query($adapted, $fetchMode, ...$fetchModeArgs);
    }
}

class Database
{
    private static ?PDO $instance = null;
    private static ?array $config = null;

    public static function getConfig(): array
    {
        if (self::$config === null) {
            Env::load();
            self::$config = require dirname(__DIR__, 2) . '/config/database.php';
        }

        return self::$config;
    }

    public static function driver(): string
    {
        return strtolower((string) (self::getConfig()['driver'] ?? 'mysql'));
    }

    public static function isPostgres(): bool
    {
        return self::driver() === 'pgsql';
    }

    public static function prefix(): string
    {
        $prefix = trim((string) (self::getConfig()['prefix'] ?? ''));
        if ($prefix !== '' && !str_ends_with($prefix, '_')) {
            $prefix .= '_';
        }

        return $prefix;
    }

    public static function quoteIdent(string $name): string
    {
        if (self::isPostgres()) {
            return '"' . str_replace('"', '""', $name) . '"';
        }

        return '`' . str_replace('`', '``', $name) . '`';
    }

    public static function table(string $name): string
    {
        return self::quoteIdent(self::prefix() . $name);
    }

    public static function tableName(string $name): string
    {
        return self::prefix() . $name;
    }

    public static function adaptSql(string $sql): string
    {
        if (!self::isPostgres()) {
            return $sql;
        }

        $placeholders = [];
        $mask = static function (string $sql, string $pattern) use (&$placeholders): string {
            return preg_replace_callback($pattern, static function (array $m) use (&$placeholders): string {
                $key = "\x01PH" . count($placeholders) . "\x01";
                $placeholders[$key] = $m[0];
                return $key;
            }, $sql);
        };

        $masked = $mask($sql, "/'(?:\\\\'|[^'])*'/");
        $masked = $mask($masked, '/"(?:[^"]|"")*"/');

        $masked = preg_replace_callback('/\.([A-Za-z_][A-Za-z0-9_]*)/', static function (array $m): string {
            return self::shouldQuoteIdent($m[1]) ? '."' . str_replace('"', '""', $m[1]) . '"' : '.' . $m[1];
        }, $masked);

        $masked = $mask($masked, '/"(?:[^"]|"")*"/');

        $masked = preg_replace_callback('/(?<!")\b([A-Za-z_][A-Za-z0-9_]*)\b(?!")/', static function (array $m): string {
            return self::shouldQuoteIdent($m[1]) ? '"' . str_replace('"', '""', $m[1]) . '"' : $m[1];
        }, $masked);

        return strtr($masked, $placeholders);
    }

    public static function insertReturningId(PDO $pdo, string $sql, array $params, string $idColumn): int
    {
        if (self::isPostgres()) {
            $sql = rtrim($sql, "; \t\n\r") . ' RETURNING ' . $idColumn;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if (self::isPostgres()) {
            return (int) $stmt->fetchColumn();
        }

        return (int) $pdo->lastInsertId();
    }

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = self::getConfig();
            $driver = self::driver();
            $timezone = (string) ($config['timezone'] ?? 'America/Bogota');

            if ($driver === 'pgsql') {
                $dsn = sprintf(
                    "pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;options='-c timezone=%s -c client_encoding=UTF8'",
                    $config['host'],
                    $config['port'] ?? 5432,
                    $config['dbname'],
                    $config['sslmode'] ?? 'require',
                    str_replace(["'", ' '], '', $timezone)
                );
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    $config['host'],
                    $config['port'] ?? 3306,
                    $config['dbname'],
                    $config['charset'] ?? 'utf8mb4'
                );
            }

            self::$instance = new DatabasePDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => true,
            ]);

            if ($driver === 'pgsql') {
                self::$instance->exec("SET TIME ZONE " . self::$instance->quote($timezone));
                self::$instance->exec("SET client_encoding TO 'UTF8'");
            } else {
                $charset = preg_replace('/[^a-z0-9]/i', '', (string) ($config['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
                $collation = preg_replace('/[^a-z0-9_]/i', '', (string) ($config['collation'] ?? 'utf8mb4_unicode_ci')) ?: 'utf8mb4_unicode_ci';
                $tzOffset = self::mysqlTimezoneOffset($timezone);
                self::$instance->exec(sprintf('SET NAMES %s COLLATE %s', $charset, $collation));
                self::$instance->exec(sprintf("SET time_zone = '%s'", $tzOffset));
            }
        }

        return self::$instance;
    }

    public static function mysqlTimezoneOffset(string $timezone): string
    {
        try {
            $dt = new DateTime('now', new DateTimeZone($timezone !== '' ? $timezone : 'America/Bogota'));
            return $dt->format('P');
        } catch (Throwable $e) {
            return '-05:00';
        }
    }

    private static function shouldQuoteIdent(string $ident): bool
    {
        if ($ident === strtoupper($ident)) {
            return false;
        }

        return (bool) preg_match('/[A-Z]/', $ident);
    }
}
