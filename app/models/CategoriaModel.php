<?php

require_once __DIR__ . '/../core/Model.php';

class CategoriaModel extends Model
{
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
            'SELECT id_categoria, Nombre FROM ' . $this->t('Categorias') . ' WHERE Estado = \'Activo\' ORDER BY Nombre ASC'
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

    public function ensureByNombre(string $nombre): ?array
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            $nombre = 'General';
        }

        $row = $this->findByNombre($nombre);
        if ($row) {
            if (($row['Estado'] ?? '') === 'Eliminado') {
                $this->update((int) $row['id_categoria'], [
                    'nombre' => $row['Nombre'],
                    'estado' => 'Activo',
                ]);
                $row['Estado'] = 'Activo';
            }
            return $row;
        }

        $this->create(['nombre' => $nombre, 'estado' => 'Activo']);
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
            'INSERT INTO ' . $this->t('Categorias') . ' (Nombre, Estado) VALUES (:nombre, :estado)'
        );
        return $stmt->execute([
            'nombre' => $data['nombre'],
            'estado' => $data['estado'] ?? 'Activo',
        ]);
    }

    public function update(int $id, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE ' . $this->t('Categorias') . ' SET Nombre = :nombre, Estado = :estado WHERE id_categoria = :id'
        );
        return $stmt->execute([
            'nombre' => $data['nombre'],
            'estado' => $data['estado'],
            'id'     => $id,
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
        $stmt = $this->db->query(
            'SELECT id_categoria FROM ' . $this->t('Categorias') . ' WHERE Estado = \'Activo\' ORDER BY id_categoria LIMIT 1'
        );
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : 1;
    }
}
