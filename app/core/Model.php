<?php

require_once __DIR__ . '/Database.php';

class Model
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    protected function t(string $table): string
    {
        return Database::table($table);
    }

    protected function insertId(string $sql, array $params, string $idColumn): int
    {
        return Database::insertReturningId($this->db, $sql, $params, $idColumn);
    }
}
