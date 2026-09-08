<?php

require_once __DIR__ . '/../core/Model.php';
require_once __DIR__ . '/InventarioModel.php';

class MovimientoModel extends Model
{
    public static function esDarDeBaja(?string $tipo): bool
    {
        if ($tipo === null || trim($tipo) === '') {
            return false;
        }

        $normalizado = strtolower(preg_replace('/[\s\-.]+/', '_', trim($tipo)));

        return in_array($normalizado, [
            'dar_baja',
            'dardebaja',
            'darde_baja',
            'dar_de_baja',
        ], true);
    }

    public static function etiquetaTipo(string $tipo): string
    {
        if (self::esDarDeBaja($tipo)) {
            return 'Dar de baja';
        }

        return match ($tipo) {
            'Ingreso'    => 'Ingreso',
            'Prestamo'   => 'Salida',
            'Devolucion' => 'Devolución',
            default      => $tipo,
        };
    }

    public function findDarDeBajaById(int $id): ?array
    {
        $movimiento = $this->findMovimientoById($id);
        if (!$movimiento || !self::esDarDeBaja($movimiento['Tipo'] ?? '')) {
            return null;
        }

        $movimiento['Tipo'] = 'Dar_Baja';
        return $movimiento;
    }

    public function registrarIngreso(array $movimiento, array $lineas): int
    {
        if (empty($lineas)) {
            throw new RuntimeException('Debe registrar al menos un elemento.');
        }

        $inventario = new InventarioModel();
        $this->db->beginTransaction();

        try {
            foreach ($lineas as $index => &$linea) {
                if (!empty($linea['nuevo'])) {
                    $nuevo = $linea['nuevo'];
                    if ($inventario->findByCodigo($nuevo['codigo'])) {
                        throw new RuntimeException('El código ' . $nuevo['codigo'] . ' ya existe.');
                    }
                    $inventario->create([
                        'codigo'       => $nuevo['codigo'],
                        'elemento'     => $nuevo['elemento'],
                        'id_categoria' => $nuevo['id_categoria'],
                        'descripcion'  => $nuevo['descripcion'],
                        'cantidad'     => 0,
                        'fotografia'   => null,
                        'estado'       => 'Activo',
                    ]);
                    $linea['codigo'] = $nuevo['codigo'];
                }

                $item = $inventario->findByCodigo($linea['codigo']);
                if (!$item) {
                    throw new RuntimeException('El elemento ' . $linea['codigo'] . ' no existe.');
                }
                if ($item['Estado'] !== 'Activo') {
                    throw new RuntimeException('El elemento ' . $linea['codigo'] . ' no está activo.');
                }
                if ($linea['cantidad'] <= 0) {
                    throw new RuntimeException('La cantidad debe ser mayor a cero en la línea ' . ($index + 1) . '.');
                }
            }
            unset($linea);

            $idMovimiento = $this->crearMovimiento('Ingreso', $movimiento);

            foreach ($lineas as $index => $linea) {
                $this->crearDetalle($idMovimiento, $index + 1, $linea['codigo'], $linea['cantidad']);
                if (!$inventario->ajustarCantidad($linea['codigo'], $linea['cantidad'])) {
                    throw new RuntimeException('No se pudo actualizar la cantidad del inventario.');
                }
            }

            $this->db->commit();
            return $idMovimiento;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function registrarPrestamo(array $movimiento, array $lineas): int
    {
        if (empty($lineas)) {
            throw new RuntimeException('Debe registrar al menos un elemento.');
        }

        $inventario = new InventarioModel();

        foreach ($lineas as $index => $linea) {
            $item = $inventario->findByCodigo($linea['codigo']);
            if (!$item) {
                throw new RuntimeException('El elemento ' . $linea['codigo'] . ' no existe.');
            }
            if ($item['Estado'] !== 'Activo') {
                throw new RuntimeException('El elemento ' . $linea['codigo'] . ' no está activo.');
            }
            if ($linea['cantidad'] <= 0) {
                throw new RuntimeException('La cantidad debe ser mayor a cero en la línea ' . ($index + 1) . '.');
            }
            if ((int) $item['Cantidad'] < $linea['cantidad']) {
                throw new RuntimeException(
                    'Stock insuficiente para ' . $linea['codigo'] . '. Disponible: ' . (int) $item['Cantidad']
                );
            }
        }

        $this->db->beginTransaction();

        try {
            $idMovimiento = $this->crearMovimiento('Prestamo', $movimiento);

            foreach ($lineas as $index => $linea) {
                $this->crearDetalle($idMovimiento, $index + 1, $linea['codigo'], $linea['cantidad']);
                if (!$inventario->ajustarCantidad($linea['codigo'], -$linea['cantidad'])) {
                    throw new RuntimeException('No se pudo actualizar la cantidad del inventario.');
                }
            }

            $this->db->commit();
            return $idMovimiento;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getPrestamos(array $filters = []): array
    {
        $filters['tipo'] = 'Prestamo';
        $rows = $this->getMovimientos($filters);

        foreach ($rows as &$row) {
            $pendientes = $this->getLineasPendientes((int) $row['id_movimiento']);
            $row['Pendiente_total'] = array_sum(array_column($pendientes, 'pendiente'));
            if ($row['Pendiente_total'] === 0 && $row['Estado'] !== 'Cerrado') {
                $this->sincronizarEstadoPrestamo((int) $row['id_movimiento']);
                $row['Estado'] = 'Cerrado';
            }
        }
        unset($row);

        return $rows;
    }

    public function getMovimientos(array $filters = []): array
    {
        $elementosAgg = Database::isPostgres()
            ? "STRING_AGG(CONCAT(d.Codigo_elemento, ' - ', COALESCE(i.Elemento, '[Elemento eliminado]'), ' (', d.Cantidad::text, ')'), '; ' ORDER BY d.id_Detalle)"
            : "GROUP_CONCAT(CONCAT(d.Codigo_elemento, ' - ', COALESCE(i.Elemento, '[Elemento eliminado]'), ' (', d.Cantidad, ')') ORDER BY d.id_Detalle SEPARATOR '; ')";

        $sql = 'SELECT m.id_movimiento, m.Tipo, m.Consecutivo, m.Fecha, m.Descripcion, m.Estado,
                    m.Cedula_cuentadante, m.id_movimiento_ref,
                    c.Nombres AS Cuentadante,
                    ref.Consecutivo AS Consecutivo_ref,
                    ' . $elementosAgg . ' AS Elementos,
                    SUM(d.Cantidad) AS Total_unidades
             FROM ' . $this->t('Movimientos') . ' m
             LEFT JOIN ' . $this->t('Usuarios') . ' c ON c.Cedula = m.Cedula_cuentadante
             LEFT JOIN ' . $this->t('Movimientos') . ' ref ON ref.id_movimiento = m.id_movimiento_ref
             LEFT JOIN ' . $this->t('Det_Movimientos') . ' d ON d.id_movimiento = m.id_movimiento AND d.Estado = \'Activo\'
             LEFT JOIN ' . $this->t('Inventario') . ' i ON i.Codigo = d.Codigo_elemento
             WHERE 1=1';

        $params = $this->applyMovimientoFilters($sql, $filters);

        $sql .= ' GROUP BY m.id_movimiento, m.Tipo, m.Consecutivo, m.Fecha, m.Descripcion, m.Estado,
                      m.Cedula_cuentadante, m.id_movimiento_ref, c.Nombres, ref.Consecutivo
             ORDER BY m.Fecha DESC, m.id_movimiento DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getReporteMovimientos(array $filters = []): array
    {
        $sql = 'SELECT m.id_movimiento, m.Tipo, m.Consecutivo, m.Fecha, m.Descripcion, m.Estado,
                    m.Cedula_cuentadante, m.id_movimiento_ref,
                    c.Nombres AS Cuentadante,
                    ref.Consecutivo AS Consecutivo_ref,
                    d.Codigo_elemento, COALESCE(i.Elemento, \'[Elemento eliminado]\') AS Elemento,
                    cat.Nombre AS Categoria, d.Cantidad
             FROM ' . $this->t('Movimientos') . ' m
             LEFT JOIN ' . $this->t('Usuarios') . ' c ON c.Cedula = m.Cedula_cuentadante
             LEFT JOIN ' . $this->t('Movimientos') . ' ref ON ref.id_movimiento = m.id_movimiento_ref
             INNER JOIN ' . $this->t('Det_Movimientos') . ' d ON d.id_movimiento = m.id_movimiento AND d.Estado = \'Activo\'
             LEFT JOIN ' . $this->t('Inventario') . ' i ON i.Codigo = d.Codigo_elemento
             LEFT JOIN ' . $this->t('Categorias') . ' cat ON cat.id_categoria = i.id_categoria
             WHERE 1=1';

        $params = [];

        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND m.Fecha >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }

        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND m.Fecha <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }

        if (!empty($filters['tipo'])) {
            $this->appendTipoFilter($sql, $params, $filters['tipo']);
        }

        if (!empty($filters['cedula_cuentadante'])) {
            $sql .= ' AND m.Cedula_cuentadante = :cedula_cuentadante';
            $params['cedula_cuentadante'] = $filters['cedula_cuentadante'];
        }

        if (!empty($filters['estado'])) {
            $sql .= ' AND m.Estado = :estado';
            $params['estado'] = $filters['estado'];
        }

        if (!empty($filters['id_categoria'])) {
            $sql .= ' AND i.id_categoria = :id_categoria';
            $params['id_categoria'] = $filters['id_categoria'];
        }

        if (!empty($filters['elemento'])) {
            $sql .= ' AND (d.Codigo_elemento LIKE :elemento OR i.Elemento LIKE :elemento)';
            $params['elemento'] = '%' . $filters['elemento'] . '%';
        }

        $sql .= ' ORDER BY m.Fecha DESC, m.Consecutivo DESC, d.id_Detalle ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    private function applyMovimientoFilters(string &$sql, array $filters): array
    {
        $params = [];

        if (!empty($filters['tipo'])) {
            $this->appendTipoFilter($sql, $params, $filters['tipo']);
        }

        if (!empty($filters['cedula_cuentadante'])) {
            $sql .= ' AND m.Cedula_cuentadante = :cedula_cuentadante';
            $params['cedula_cuentadante'] = $filters['cedula_cuentadante'];
        }

        if (!empty($filters['estado'])) {
            $sql .= ' AND m.Estado = :estado';
            $params['estado'] = $filters['estado'];
        }

        if (!empty($filters['fecha_desde'])) {
            $sql .= ' AND m.Fecha >= :fecha_desde';
            $params['fecha_desde'] = $filters['fecha_desde'];
        }

        if (!empty($filters['fecha_hasta'])) {
            $sql .= ' AND m.Fecha <= :fecha_hasta';
            $params['fecha_hasta'] = $filters['fecha_hasta'];
        }

        return $params;
    }

    private function appendTipoFilter(string &$sql, array &$params, string $tipo): void
    {
        if ($tipo === 'Dar_Baja' || $tipo === 'DarDeBaja') {
            $sql .= ' AND (
                m.Tipo IN (\'Dar_Baja\', \'DarDeBaja\')
                OR LOWER(REPLACE(REPLACE(REPLACE(m.Tipo, \' \', \'_\'), \'-\', \'_\'), \'.\', \'\'))
                   IN (\'dar_baja\', \'dardebaja\', \'darde_baja\', \'dar_de_baja\')
            )';
            return;
        }

        $sql .= ' AND m.Tipo = :tipo';
        $params['tipo'] = $tipo;
    }

    public function countPrestamosActivos(): int
    {
        $stmt = $this->db->query(
            'SELECT COUNT(*) FROM ' . $this->t('Movimientos') . ' WHERE Tipo = \'Prestamo\' AND Estado = \'Activo\''
        );
        return (int) $stmt->fetchColumn();
    }

    public function countPrestamosActivosPorCedula(string $cedula): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM ' . $this->t('Movimientos') . '
             WHERE Tipo = \'Prestamo\' AND Estado = \'Activo\' AND Cedula_cuentadante = :cedula'
        );
        $stmt->execute(['cedula' => $cedula]);
        return (int) $stmt->fetchColumn();
    }

    public function findPrestamoById(int $id): ?array
    {
        return $this->findMovimientoById($id, 'Prestamo');
    }

    public function findIngresoById(int $id): ?array
    {
        return $this->findMovimientoById($id, 'Ingreso');
    }

    public function findMovimientoById(int $id, ?string $tipo = null): ?array
    {
        $sql = 'SELECT m.*, c.Nombres AS Cuentadante, ref.Consecutivo AS Consecutivo_ref
                 FROM ' . $this->t('Movimientos') . ' m
                 LEFT JOIN ' . $this->t('Usuarios') . ' c ON c.Cedula = m.Cedula_cuentadante
                 LEFT JOIN ' . $this->t('Movimientos') . ' ref ON ref.id_movimiento = m.id_movimiento_ref
                 WHERE m.id_movimiento = :id';
        $params = ['id' => $id];
        if ($tipo) {
            $sql .= ' AND m.Tipo = :tipo';
            $params['tipo'] = $tipo;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getDetalleByMovimientoId(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.*, i.Elemento, cat.Nombre AS Categoria
             FROM ' . $this->t('Det_Movimientos') . ' d
             LEFT JOIN ' . $this->t('Inventario') . ' i ON i.Codigo = d.Codigo_elemento
             LEFT JOIN ' . $this->t('Categorias') . ' cat ON cat.id_categoria = i.id_categoria
             WHERE d.id_movimiento = :id
             ORDER BY d.id_Detalle'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetchAll();
    }

    public function getFotosByMovimientoId(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . $this->t('Movimiento_Fotos') . ' WHERE id_movimiento = :id ORDER BY Orden ASC'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetchAll();
    }

    public function guardarFotos(int $idMovimiento, array $rutas): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $this->t('Movimiento_Fotos') . ' (id_movimiento, Ruta, Orden) VALUES (:id_movimiento, :ruta, :orden)'
        );

        foreach ($rutas as $index => $ruta) {
            $stmt->execute([
                'id_movimiento' => $idMovimiento,
                'ruta'          => $ruta,
                'orden'         => $index + 1,
            ]);
        }
    }

    public function getLineasPendientes(int $idPrestamo): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.Codigo_elemento, COALESCE(i.Elemento, \'[Elemento eliminado]\') AS Elemento, d.Cantidad AS prestado
             FROM ' . $this->t('Det_Movimientos') . ' d
             LEFT JOIN ' . $this->t('Inventario') . ' i ON i.Codigo = d.Codigo_elemento
             WHERE d.id_movimiento = :id AND d.Estado = \'Activo\'
             ORDER BY d.id_Detalle'
        );
        $stmt->execute(['id' => $idPrestamo]);
        $prestadas = $stmt->fetchAll();

        $devueltas = $this->getCantidadesDevueltas($idPrestamo);
        $lineas = [];

        foreach ($prestadas as $linea) {
            $codigo = $linea['Codigo_elemento'];
            $prestado = (int) $linea['prestado'];
            $devuelto = (int) ($devueltas[$codigo] ?? 0);
            $pendiente = max(0, $prestado - $devuelto);

            $lineas[] = [
                'codigo'    => $codigo,
                'elemento'  => $linea['Elemento'],
                'prestado'  => $prestado,
                'devuelto'  => $devuelto,
                'pendiente' => $pendiente,
            ];
        }

        return $lineas;
    }

    public function tienePendiente(int $idPrestamo): bool
    {
        foreach ($this->getLineasPendientes($idPrestamo) as $linea) {
            if ($linea['pendiente'] > 0) {
                return true;
            }
        }
        return false;
    }

    public function updateEstadoPrestamo(int $id, string $estado): bool
    {
        if (!in_array($estado, ['Activo', 'Inactivo', 'Cerrado'], true)) {
            throw new RuntimeException('Estado no válido.');
        }

        $prestamo = $this->findPrestamoById($id);
        if (!$prestamo) {
            return false;
        }

        if (!$this->tienePendiente($id)) {
            $estado = 'Cerrado';
        }

        if ($prestamo['Estado'] === $estado) {
            return true;
        }

        $stmt = $this->db->prepare(
            'UPDATE ' . $this->t('Movimientos') . ' SET Estado = :estado
             WHERE id_movimiento = :id AND Tipo = \'Prestamo\''
        );
        $stmt->execute(['estado' => $estado, 'id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function sincronizarEstadoPrestamo(int $idPrestamo): void
    {
        $prestamo = $this->findPrestamoById($idPrestamo);
        if (!$prestamo) {
            return;
        }

        if (!$this->tienePendiente($idPrestamo) && $prestamo['Estado'] !== 'Cerrado') {
            $this->updateEstadoPrestamo($idPrestamo, 'Cerrado');
        }
    }

    public function registrarDevolucion(int $idPrestamo, array $lineas, ?string $descripcion = null): void
    {
        $prestamo = $this->findPrestamoById($idPrestamo);
        if (!$prestamo) {
            throw new RuntimeException('Salida no encontrada.');
        }

        $pendientes = $this->getLineasPendientes($idPrestamo);
        $pendientePorCodigo = [];
        foreach ($pendientes as $p) {
            $pendientePorCodigo[$p['codigo']] = $p['pendiente'];
        }

        $lineasValidas = [];
        foreach ($lineas as $linea) {
            $codigo = $linea['codigo'] ?? '';
            $cantidad = (int) ($linea['cantidad'] ?? 0);
            $maxPendiente = $pendientePorCodigo[$codigo] ?? 0;

            if ($cantidad <= 0) {
                continue;
            }
            if ($maxPendiente <= 0) {
                throw new RuntimeException('No hay pendiente para el elemento ' . $codigo . '.');
            }
            if ($cantidad > $maxPendiente) {
                throw new RuntimeException(
                    'Cantidad a devolver excede lo pendiente para ' . $codigo
                    . '. Pendiente: ' . $maxPendiente
                );
            }
            $lineasValidas[] = ['codigo' => $codigo, 'cantidad' => $cantidad];
        }

        if (empty($lineasValidas)) {
            throw new RuntimeException('Debe indicar al menos una cantidad a devolver.');
        }

        $inventario = new InventarioModel();
        $this->db->beginTransaction();

        try {
            $idMovimiento = $this->crearMovimiento('Devolucion', [
                'fecha'              => date('Y-m-d'),
                'cedula_cuentadante' => $prestamo['Cedula_cuentadante'],
                'descripcion'        => $descripcion,
                'id_movimiento_ref'  => $idPrestamo,
            ]);

            foreach ($lineasValidas as $index => $linea) {
                $this->crearDetalle($idMovimiento, $index + 1, $linea['codigo'], $linea['cantidad']);
                if (!$inventario->ajustarCantidad($linea['codigo'], $linea['cantidad'])) {
                    throw new RuntimeException('No se pudo actualizar la cantidad del inventario.');
                }
            }

            $this->sincronizarEstadoPrestamo($idPrestamo);

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function registrarDevolucionTotal(int $idPrestamo, ?string $descripcion = null): void
    {
        $lineas = [];
        foreach ($this->getLineasPendientes($idPrestamo) as $p) {
            if ($p['pendiente'] > 0) {
                $lineas[] = ['codigo' => $p['codigo'], 'cantidad' => $p['pendiente']];
            }
        }

        if (empty($lineas)) {
            throw new RuntimeException('No hay elementos pendientes por devolver.');
        }

        $this->registrarDevolucion(
            $idPrestamo,
            $lineas,
            $descripcion ?: 'Devolución total de la salida #' . $idPrestamo
        );
    }

    public function registrarDarDeBaja(int $idPrestamo, array $lineas, string $descripcion, array $fotos = []): int
    {
        $prestamo = $this->findPrestamoById($idPrestamo);
        if (!$prestamo) {
            throw new RuntimeException('Salida no encontrada.');
        }

        if (empty($fotos)) {
            throw new RuntimeException('Debe adjuntar al menos una fotografía del elemento.');
        }

        if (count($fotos) > 3) {
            throw new RuntimeException('Máximo 3 fotografías permitidas.');
        }

        $pendientes = $this->getLineasPendientes($idPrestamo);
        $pendientePorCodigo = [];
        foreach ($pendientes as $p) {
            $pendientePorCodigo[$p['codigo']] = $p['pendiente'];
        }

        $lineasValidas = [];
        foreach ($lineas as $linea) {
            $codigo = $linea['codigo'] ?? '';
            $cantidad = (int) ($linea['cantidad'] ?? 0);
            $maxPendiente = $pendientePorCodigo[$codigo] ?? 0;

            if ($cantidad <= 0) {
                continue;
            }
            if ($maxPendiente <= 0) {
                throw new RuntimeException('No hay pendiente para el elemento ' . $codigo . '.');
            }
            if ($cantidad > $maxPendiente) {
                throw new RuntimeException(
                    'Cantidad a dar de baja excede lo pendiente para ' . $codigo
                    . '. Pendiente: ' . $maxPendiente
                );
            }
            $lineasValidas[] = ['codigo' => $codigo, 'cantidad' => $cantidad];
        }

        if (empty($lineasValidas)) {
            throw new RuntimeException('Debe indicar al menos una cantidad a dar de baja.');
        }

        $this->db->beginTransaction();

        try {
            $idMovimiento = $this->crearMovimiento('Dar_Baja', [
                'fecha'              => date('Y-m-d'),
                'cedula_cuentadante' => $prestamo['Cedula_cuentadante'],
                'descripcion'        => $descripcion,
                'id_movimiento_ref'  => $idPrestamo,
            ]);

            foreach ($lineasValidas as $index => $linea) {
                $this->crearDetalle($idMovimiento, $index + 1, $linea['codigo'], $linea['cantidad']);
            }

            $this->guardarFotos($idMovimiento, $fotos);
            $this->sincronizarEstadoPrestamo($idPrestamo);

            $this->db->commit();
            return $idMovimiento;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function getCantidadesDevueltas(int $idPrestamo): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.Codigo_elemento, SUM(d.Cantidad) AS devuelto
             FROM ' . $this->t('Movimientos') . ' m
             JOIN ' . $this->t('Det_Movimientos') . ' d ON d.id_movimiento = m.id_movimiento AND d.Estado = \'Activo\'
             WHERE (
                 m.Tipo IN (\'Devolucion\', \'Dar_Baja\')
                 OR LOWER(REPLACE(REPLACE(REPLACE(m.Tipo, \' \', \'_\'), \'-\', \'_\'), \'.\', \'\'))
                    IN (\'dar_baja\', \'dardebaja\', \'darde_baja\', \'dar_de_baja\')
               )
               AND m.id_movimiento_ref = :id
               AND m.Estado = \'Activo\'
             GROUP BY d.Codigo_elemento'
        );
        $stmt->execute(['id' => $idPrestamo]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['Codigo_elemento']] = (int) $row['devuelto'];
        }
        return $map;
    }

    private function crearMovimiento(string $tipo, array $data): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(Consecutivo), 0) + 1 FROM ' . $this->t('Movimientos') . ' WHERE Tipo = :tipo'
        );
        $stmt->execute(['tipo' => $tipo]);
        $consecutivo = (int) $stmt->fetchColumn();

        return $this->insertId(
            'INSERT INTO ' . $this->t('Movimientos') . ' (Tipo, Consecutivo, Fecha, Cedula_cuentadante, Descripcion, Estado, id_movimiento_ref)
             VALUES (:tipo, :consecutivo, :fecha, :cedula_cuentadante, :descripcion, \'Activo\', :id_movimiento_ref)',
            [
                'tipo'               => $tipo,
                'consecutivo'        => $consecutivo,
                'fecha'              => $data['fecha'],
                'cedula_cuentadante' => $data['cedula_cuentadante'] ?? null,
                'descripcion'        => $data['descripcion'] ?? null,
                'id_movimiento_ref'  => $data['id_movimiento_ref'] ?? null,
            ],
            'id_movimiento'
        );
    }

    private function crearDetalle(int $idMovimiento, int $idDetalle, string $codigoElemento, int $cantidad): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $this->t('Det_Movimientos') . ' (id_movimiento, id_Detalle, Codigo_elemento, Cantidad, Estado)
             VALUES (:id_movimiento, :id_detalle, :codigo_elemento, :cantidad, \'Activo\')'
        );
        $stmt->execute([
            'id_movimiento'   => $idMovimiento,
            'id_detalle'      => $idDetalle,
            'codigo_elemento' => $codigoElemento,
            'cantidad'        => $cantidad,
        ]);
    }
}
