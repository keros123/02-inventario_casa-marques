<?php

require_once __DIR__ . '/../core/Model.php';
require_once __DIR__ . '/MovimientoModel.php';
require_once __DIR__ . '/InventarioModel.php';

class SolicitudPrestamoModel extends Model
{
    public function create(string $cedulaSolicitante, string $fecha, ?string $descripcion, array $lineas): int
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
                    'Cantidad insuficiente para ' . $linea['codigo'] . '. Disponible: ' . (int) $item['Cantidad']
                );
            }
        }

        $this->db->beginTransaction();

        try {
            $idSolicitud = $this->insertId(
                'INSERT INTO ' . $this->t('Solicitudes_Prestamo') . ' (Cedula_solicitante, Fecha_solicitud, Descripcion, Estado)
                 VALUES (:cedula, :fecha, :descripcion, \'Pendiente\')',
                [
                    'cedula'      => $cedulaSolicitante,
                    'fecha'       => $fecha,
                    'descripcion' => $descripcion,
                ],
                'id_solicitud'
            );

            $det = $this->db->prepare(
                'INSERT INTO ' . $this->t('Det_Solicitudes') . ' (id_solicitud, id_linea, Codigo_elemento, Cantidad)
                 VALUES (:id_solicitud, :id_linea, :codigo, :cantidad)'
            );

            foreach ($lineas as $index => $linea) {
                $det->execute([
                    'id_solicitud' => $idSolicitud,
                    'id_linea'     => $index + 1,
                    'codigo'       => $linea['codigo'],
                    'cantidad'     => $linea['cantidad'],
                ]);
            }

            $this->db->commit();
            return $idSolicitud;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, u.Nombres AS Solicitante, a.Nombres AS Aprobador
             FROM ' . $this->t('Solicitudes_Prestamo') . ' s
             INNER JOIN ' . $this->t('Usuarios') . ' u ON u.Cedula = s.Cedula_solicitante
             LEFT JOIN ' . $this->t('Usuarios') . ' a ON a.Cedula = s.Cedula_aprobador
             WHERE s.id_solicitud = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getDetalle(int $idSolicitud): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.Codigo_elemento, d.Cantidad, COALESCE(i.Elemento, \'[Elemento eliminado]\') AS Elemento
             FROM ' . $this->t('Det_Solicitudes') . ' d
             LEFT JOIN ' . $this->t('Inventario') . ' i ON i.Codigo = d.Codigo_elemento
             WHERE d.id_solicitud = :id
             ORDER BY d.id_linea'
        );
        $stmt->execute(['id' => $idSolicitud]);
        return $stmt->fetchAll();
    }

    public function getForMonth(int $year, int $month, ?string $cedulaSolicitante = null): array
    {
        $inicio = sprintf('%04d-%02d-01', $year, $month);
        $fin = date('Y-m-t', strtotime($inicio));

        $sql = 'SELECT s.id_solicitud, s.Fecha_solicitud, s.Estado, s.Cedula_solicitante, u.Nombres AS Solicitante,
                       COUNT(d.id_detalle) AS lineas
                FROM ' . $this->t('Solicitudes_Prestamo') . ' s
                INNER JOIN ' . $this->t('Usuarios') . ' u ON u.Cedula = s.Cedula_solicitante
                LEFT JOIN ' . $this->t('Det_Solicitudes') . ' d ON d.id_solicitud = s.id_solicitud
                WHERE s.Fecha_solicitud BETWEEN :inicio AND :fin';
        $params = ['inicio' => $inicio, 'fin' => $fin];

        if ($cedulaSolicitante !== null && $cedulaSolicitante !== '') {
            $sql .= ' AND s.Cedula_solicitante = :cedula';
            $params['cedula'] = $cedulaSolicitante;
        }

        $sql .= ' GROUP BY s.id_solicitud, s.Fecha_solicitud, s.Estado, s.Cedula_solicitante, u.Nombres
                  ORDER BY s.Fecha_solicitud ASC, s.id_solicitud ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getBySolicitante(string $cedula): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, COUNT(d.id_detalle) AS lineas
             FROM ' . $this->t('Solicitudes_Prestamo') . ' s
             LEFT JOIN ' . $this->t('Det_Solicitudes') . ' d ON d.id_solicitud = s.id_solicitud
             WHERE s.Cedula_solicitante = :cedula
             GROUP BY s.id_solicitud, s.Cedula_solicitante, s.Fecha_solicitud, s.Descripcion,
                      s.Estado, s.id_movimiento, s.Cedula_aprobador, s.Fecha_resolucion, s.Motivo_rechazo
             ORDER BY s.Fecha_solicitud DESC, s.id_solicitud DESC'
        );
        $stmt->execute(['cedula' => $cedula]);
        return $stmt->fetchAll();
    }

    public function getPendientes(): array
    {
        $stmt = $this->db->query(
            'SELECT s.*, u.Nombres AS Solicitante, COUNT(d.id_detalle) AS lineas
             FROM ' . $this->t('Solicitudes_Prestamo') . ' s
             INNER JOIN ' . $this->t('Usuarios') . ' u ON u.Cedula = s.Cedula_solicitante
             LEFT JOIN ' . $this->t('Det_Solicitudes') . ' d ON d.id_solicitud = s.id_solicitud
             WHERE s.Estado = \'Pendiente\'
             GROUP BY s.id_solicitud, s.Cedula_solicitante, s.Fecha_solicitud, s.Descripcion,
                      s.Estado, s.id_movimiento, s.Cedula_aprobador, s.Fecha_resolucion, s.Motivo_rechazo,
                      u.Nombres
             ORDER BY s.Fecha_solicitud ASC, s.id_solicitud ASC'
        );
        return $stmt->fetchAll();
    }

    public function countPendientes(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) FROM ' . $this->t('Solicitudes_Prestamo') . ' WHERE Estado = \'Pendiente\''
        )->fetchColumn();
    }

    public function countBySolicitante(string $cedula, ?string $estado = null): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->t('Solicitudes_Prestamo') . ' WHERE Cedula_solicitante = :cedula';
        $params = ['cedula' => $cedula];

        if ($estado !== null && $estado !== '') {
            $sql .= ' AND Estado = :estado';
            $params['estado'] = $estado;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function cancelar(int $id, string $cedula): bool
    {
        $solicitud = $this->findById($id);
        if (!$solicitud || $solicitud['Estado'] !== 'Pendiente') {
            return false;
        }
        if ($solicitud['Cedula_solicitante'] !== $cedula) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE ' . $this->t('Solicitudes_Prestamo') . ' SET Estado = \'Cancelada\', Fecha_resolucion = NOW()
             WHERE id_solicitud = :id AND Estado = \'Pendiente\''
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function rechazar(int $id, string $cedulaAprobador, string $motivo): bool
    {
        $solicitud = $this->findById($id);
        if (!$solicitud || $solicitud['Estado'] !== 'Pendiente') {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE ' . $this->t('Solicitudes_Prestamo') . '
             SET Estado = \'Rechazada\',
                 Cedula_aprobador = :aprobador,
                 Fecha_resolucion = NOW(),
                 Motivo_rechazo = :motivo
             WHERE id_solicitud = :id AND Estado = \'Pendiente\''
        );
        $stmt->execute([
            'id'       => $id,
            'aprobador'=> $cedulaAprobador,
            'motivo'   => $motivo,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function aprobar(int $id, string $cedulaAprobador): int
    {
        $solicitud = $this->findById($id);
        if (!$solicitud || $solicitud['Estado'] !== 'Pendiente') {
            throw new RuntimeException('La solicitud no está pendiente de aprobación.');
        }

        $lineas = [];
        foreach ($this->getDetalle($id) as $det) {
            $lineas[] = [
                'codigo'   => $det['Codigo_elemento'],
                'cantidad' => (int) $det['Cantidad'],
            ];
        }

        $movimientoModel = new MovimientoModel();

        $idMovimiento = $movimientoModel->registrarPrestamo([
            'fecha'              => $solicitud['Fecha_solicitud'],
            'cedula_cuentadante' => $solicitud['Cedula_solicitante'],
            'descripcion'        => $solicitud['Descripcion']
                ?: ('Solicitud de salida #' . $id),
        ], $lineas);

        $stmt = $this->db->prepare(
            'UPDATE ' . $this->t('Solicitudes_Prestamo') . '
             SET Estado = \'Aprobada\',
                 id_movimiento = :id_movimiento,
                 Cedula_aprobador = :aprobador,
                 Fecha_resolucion = NOW()
             WHERE id_solicitud = :id AND Estado = \'Pendiente\''
        );
        $stmt->execute([
            'id'           => $id,
            'id_movimiento'=> $idMovimiento,
            'aprobador'    => $cedulaAprobador,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('No se pudo actualizar la solicitud.');
        }

        return $idMovimiento;
    }
}
