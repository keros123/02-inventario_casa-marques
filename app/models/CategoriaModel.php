<?php

require_once __DIR__ . '/../core/Model.php';

class CategoriaModel extends Model
{
    public const DEFAULT_NOMBRE = 'General';
    public const INDICADORES = ['Consumible', 'No Consumible'];

    public static function isDefaultNombre(string $nombre): bool
    {
        return strcasecmp(trim($nombre), self::DEFAULT_NOMBRE) === 0;
    }

    public static function isDefault(array $cat): bool
    {
        return self::isDefaultNombre((string) ($cat['Nombre'] ?? $cat['nombre'] ?? ''));
    }

    public static function formatLabel(?string $nombre, ?string $indicador = null): string
    {
        $nombre = trim((string) $nombre);
        if ($nombre === '') {
            return 'Sin categoría';
        }
        $indicador = trim((string) $indicador);
        if ($indicador === '' || self::isDefaultNombre($nombre)) {
            return $nombre;
        }
        return $nombre . ' · ' . $indicador;
    }

    public static function normalizeIndicador(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $norm = mb_strtolower($raw, 'UTF-8');
        $norm = strtr($norm, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
        ]);
        $norm = preg_replace('/[\s_\-]+/', '', $norm) ?? $norm;
        if ($norm === 'consumible') {
            return 'Consumible';
        }
        if (in_array($norm, ['noconsumible', 'noesconsumible'], true)) {
            return 'No Consumible';
        }
        return in_array($raw, self::INDICADORES, true) ? $raw : null;
    }

    public function getAll(bool $includeDeleted = false): array
    {
        $sql = 'SELECT * FROM ' . $this->t('Categorias');
        if (!$includeDeleted) {
            $sql .= ' WHERE Estado != \'Eliminado\'';
        }
        $sql .= ' ORDER BY Nombre ASC';
        return $this->db->query($sql)->fetchAll();
    }

    public function getActivas(): array
    {
        $stmt = $this->db->query(
            'SELECT id_categoria, Nombre, Indicador FROM ' . $this->t('Categorias') . ' WHERE Estado = \'Activo\' ORDER BY Nombre ASC'
        );
        return $stmt->fetchAll();
    }

    public function findByNombre(string $nombre): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . $this->t('Categorias') . ' WHERE LOWER(Nombre) = LOWER(:nombre) LIMIT 1'
        );
        $stmt->execute(['nombre' => $nombre]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function ensureByNombre(string $nombre, ?string $indicador = null): ?array
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            $nombre = self::DEFAULT_NOMBRE;
        }

        $row = $this->findByNombre($nombre);
        if ($row) {
            if (($row['Estado'] ?? '') === 'Eliminado') {
                $this->update((int) $row['id_categoria'], [
                    'nombre'     => $row['Nombre'],
                    'estado'     => 'Activo',
                    'indicador'  => self::isDefault($row) ? null : ($row['Indicador'] ?? $indicador),
                ]);
                $row = $this->findByNombre($nombre);
            }
            return $row ?: null;
        }

        $this->create([
            'nombre'    => $nombre,
            'estado'    => 'Activo',
            'indicador' => self::isDefaultNombre($nombre) ? null : $indicador,
        ]);
        return $this->findByNombre($nombre);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . $this->t('Categorias') . ' WHERE id_categoria = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $this->t('Categorias') . ' (Nombre, Indicador, Estado) VALUES (:nombre, :indicador, :estado)'
        );
        return $stmt->execute([
            'nombre'    => $data['nombre'],
            'indicador' => self::isDefaultNombre((string) $data['nombre']) ? null : ($data['indicador'] ?? null),
            'estado'    => $data['estado'] ?? 'Activo',
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE ' . $this->t('Categorias')
            . ' SET Nombre = :nombre, Indicador = :indicador, Estado = :estado WHERE id_categoria = :id'
        );
        $nombre = (string) $data['nombre'];
        return $stmt->execute([
            'nombre'    => $nombre,
            'indicador' => self::isDefaultNombre($nombre) ? null : ($data['indicador'] ?? null),
            'estado'    => $data['estado'],
            'id'        => $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE ' . $this->t('Categorias') . ' SET Estado = \'Eliminado\' WHERE id_categoria = :id'
        );
        return $stmt->execute(['id' => $id]);
    }

    public function countItems(int $id): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->t('Inventario') . ' WHERE id_categoria = :id AND Estado != \'Eliminado\''
        );
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    public function getDefaultId(): int
    {
        $stmt = $this->db->prepare(
            'SELECT id_categoria FROM ' . $this->t('Categorias')
            . ' WHERE Estado = \'Activo\' AND LOWER(Nombre) = LOWER(:nombre) ORDER BY id_categoria LIMIT 1'
        );
        $stmt->execute(['nombre' => self::DEFAULT_NOMBRE]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }

        $fallback = $this->db->query(
            'SELECT id_categoria FROM ' . $this->t('Categorias') . ' WHERE Estado = \'Activo\' ORDER BY id_categoria LIMIT 1'
        )->fetchColumn();
        return $fallback ? (int) $fallback : 1;
    }
}
