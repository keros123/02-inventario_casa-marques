<?php

require_once __DIR__ . '/../core/Model.php';

class InventarioModel extends Model
{
    public function getAll(?string $busqueda = null, ?int $categoriaId = null): array
    {
        $sql = 'SELECT i.*, c.Nombre AS Categoria
                FROM ' . $this->t('Inventario') . ' i
                LEFT JOIN ' . $this->t('Categorias') . ' c ON c.id_categoria = i.id_categoria
                WHERE i.Estado != \'Eliminado\'';
        $params = [];

        if ($busqueda !== null && $busqueda !== '') {
            $sql .= ' AND (i.Codigo LIKE :busqueda
                        OR i.Elemento LIKE :busqueda
                        OR i.Descripcion LIKE :busqueda
                        OR c.Nombre LIKE :busqueda)';
            $params['busqueda'] = '%' . $busqueda . '%';
        }

        if ($categoriaId !== null && $categoriaId > 0) {
            $sql .= ' AND i.id_categoria = :categoria';
            $params['categoria'] = $categoriaId;
        }

        $sql .= ' ORDER BY c.Nombre ASC, i.Elemento ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getGroupedByCategoria(?string $busqueda = null, ?int $categoriaId = null): array
    {
        $items = $this->getAll($busqueda, $categoriaId);
        $grouped = [];

        foreach ($items as $item) {
            $cat = $item['Categoria'] ?? 'Sin categoría';
            $grouped[$cat][] = $item;
        }

        return $grouped;
    }

    public function findByCodigo(string $codigo): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT i.*, c.Nombre AS Categoria
             FROM ' . $this->t('Inventario') . ' i
             LEFT JOIN ' . $this->t('Categorias') . ' c ON c.id_categoria = i.id_categoria
             WHERE i.Codigo = :codigo LIMIT 1'
        );
        $stmt->execute(['codigo' => $codigo]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByElemento(string $elemento, ?string $exceptoCodigo = null): ?array
    {
        $sql = 'SELECT i.*, c.Nombre AS Categoria
                FROM ' . $this->t('Inventario') . ' i
                LEFT JOIN ' . $this->t('Categorias') . ' c ON c.id_categoria = i.id_categoria
                WHERE LOWER(i.Elemento) = LOWER(:elemento) AND i.Estado != \'Eliminado\'';
        $params = ['elemento' => $elemento];
        if ($exceptoCodigo) {
            $sql .= ' AND i.Codigo != :excepto';
            $params['excepto'] = $exceptoCodigo;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getSaldosIniciales(): array
    {
        $sql = 'SELECT d.Codigo_elemento AS codigo, SUM(d.Cantidad) AS saldo
                FROM ' . $this->t('Det_Movimientos') . ' d
                INNER JOIN ' . $this->t('Movimientos') . ' m ON m.id_movimiento = d.id_movimiento
                WHERE m.Tipo = \'Ingreso\' AND m.Estado != \'Inactivo\'
                GROUP BY d.Codigo_elemento';
        $rows = $this->db->query($sql)->fetchAll();
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['codigo']] = (int) $row['saldo'];
        }
        return $map;
    }

    public function create(array $data): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $this->t('Inventario') . ' (Codigo, Elemento, id_categoria, Descripcion, Cantidad, Fotografia, Estado)
             VALUES (:codigo, :elemento, :id_categoria, :descripcion, :cantidad, :fotografia, :estado)'
        );
        return $stmt->execute([
            'codigo'       => $data['codigo'],
            'elemento'     => $data['elemento'],
            'id_categoria' => $data['id_categoria'],
            'descripcion'  => $data['descripcion'],
            'cantidad'     => $data['cantidad'],
            'fotografia'   => $data['fotografia'] ?? null,
            'estado'       => $data['estado'],
        ]);
    }

    public function update(string $codigo, array $data): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE ' . $this->t('Inventario') . ' SET
                Elemento = :elemento,
                id_categoria = :id_categoria,
                Descripcion = :descripcion,
                Cantidad = :cantidad,
                Fotografia = :fotografia,
                Estado = :estado
             WHERE Codigo = :codigo'
        );
        return $stmt->execute([
            'elemento'     => $data['elemento'],
            'id_categoria' => $data['id_categoria'],
            'descripcion'  => $data['descripcion'],
            'cantidad'     => $data['cantidad'],
            'fotografia'   => $data['fotografia'] ?? null,
            'estado'       => $data['estado'],
            'codigo'       => $codigo,
        ]);
    }

    public function delete(string $codigo): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE ' . $this->t('Inventario') . ' SET Estado = \'Eliminado\' WHERE Codigo = :codigo'
        );
        return $stmt->execute(['codigo' => $codigo]);
    }

    public function count(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM ' . $this->t('Inventario') . ' WHERE Estado != \'Eliminado\''
        )->fetchColumn();
    }

    public function getActivos(): array
    {
        $stmt = $this->db->query(
            'SELECT i.Codigo, i.Elemento, i.Cantidad, i.id_categoria, c.Nombre AS Categoria
             FROM ' . $this->t('Inventario') . ' i
             LEFT JOIN ' . $this->t('Categorias') . ' c ON c.id_categoria = i.id_categoria
             WHERE i.Estado = \'Activo\'
             ORDER BY c.Nombre ASC, i.Elemento ASC'
        );
        return $stmt->fetchAll();
    }

    public function getActivosConStock(): array
    {
        $stmt = $this->db->query(
            'SELECT i.Codigo, i.Elemento, i.Cantidad, i.id_categoria, c.Nombre AS Categoria
             FROM ' . $this->t('Inventario') . ' i
             LEFT JOIN ' . $this->t('Categorias') . ' c ON c.id_categoria = i.id_categoria
             WHERE i.Estado = \'Activo\' AND i.Cantidad > 0
             ORDER BY c.Nombre ASC, i.Elemento ASC'
        );
        return $stmt->fetchAll();
    }

    public function ajustarCantidad(string $codigo, int $delta): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE ' . $this->t('Inventario') . ' SET Cantidad = Cantidad + :delta WHERE Codigo = :codigo'
        );
        return $stmt->execute(['delta' => $delta, 'codigo' => $codigo]);
    }

    public function getForReport(array $filters = []): array
    {
        $sql = 'SELECT i.Codigo, i.Elemento, i.Cantidad, i.Estado, i.Descripcion,
                       c.Nombre AS Categoria, c.id_categoria
                FROM ' . $this->t('Inventario') . ' i
                LEFT JOIN ' . $this->t('Categorias') . ' c ON c.id_categoria = i.id_categoria
                WHERE i.Estado != \'Eliminado\'';
        $params = [];

        if (!empty($filters['id_categoria'])) {
            $sql .= ' AND i.id_categoria = :id_categoria';
            $params['id_categoria'] = $filters['id_categoria'];
        }

        if (!empty($filters['elemento'])) {
            $sql .= ' AND (i.Codigo LIKE :elemento OR i.Elemento LIKE :elemento)';
            $params['elemento'] = '%' . $filters['elemento'] . '%';
        }

        if (!empty($filters['estado'])) {
            $sql .= ' AND i.Estado = :estado';
            $params['estado'] = $filters['estado'];
        }

        if (!empty($filters['cantidad_operador']) && $filters['cantidad_valor'] !== null && $filters['cantidad_valor'] !== '') {
            $operador = match ($filters['cantidad_operador']) {
                'mayor' => '>',
                'menor' => '<',
                'igual' => '=',
                default  => null,
            };
            if ($operador) {
                $sql .= ' AND i.Cantidad ' . $operador . ' :cantidad_valor';
                $params['cantidad_valor'] = (int) $filters['cantidad_valor'];
            }
        }

        $sql .= ' ORDER BY c.Nombre ASC, i.Elemento ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
