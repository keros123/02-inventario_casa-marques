<?php

require_once __DIR__ . '/../core/Model.php';

class UsuarioModel extends Model
{
    public function findByCedula(string $cedula): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . $this->t('Usuarios') . ' WHERE Cedula = :cedula LIMIT 1'
        );
        $stmt->execute(['cedula' => $cedula]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function authenticate(string $cedula, string $password): ?array
    {
        $user = $this->findByCedula($cedula);
        if (!$user) {
            return null;
        }
        if ($user['Estado'] !== 'Activo') {
            return null;
        }
        if (!password_verify($password, $user['Password'])) {
            return null;
        }
        return $user;
    }

    public function getAll(): array
    {
        $stmt = $this->db->query(
            'SELECT Cedula, Nombres, Tipo, Estado FROM ' . $this->t('Usuarios') . ' ORDER BY Nombres'
        );
        return $stmt->fetchAll();
    }

    public function getActivos(): array
    {
        $stmt = $this->db->query(
            'SELECT Cedula, Nombres, Tipo, Estado FROM ' . $this->t('Usuarios') . '
             WHERE Estado = \'Activo\'
             ORDER BY Nombres ASC'
        );
        return $stmt->fetchAll();
    }

    public function getActivosParaPrestamo(): array
    {
        $stmt = $this->db->query(
            'SELECT Cedula, Nombres FROM ' . $this->t('Usuarios') . '
             WHERE Estado = \'Activo\'
             ORDER BY Nombres ASC'
        );
        return $stmt->fetchAll();
    }

    public function create(array $data): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ' . $this->t('Usuarios') . ' (Cedula, Nombres, Password, Tipo, Estado)
             VALUES (:cedula, :nombres, :password, :tipo, :estado)'
        );
        return $stmt->execute([
            'cedula'   => $data['cedula'],
            'nombres'  => $data['nombres'],
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'tipo'     => $data['tipo'],
            'estado'   => $data['estado'],
        ]);
    }

    public function update(string $cedula, array $data): bool
    {
        $sql = 'UPDATE ' . $this->t('Usuarios') . ' SET Nombres = :nombres, Tipo = :tipo, Estado = :estado';
        $params = [
            'nombres' => $data['nombres'],
            'tipo'    => $data['tipo'],
            'estado'  => $data['estado'],
            'cedula'  => $cedula,
        ];

        if (!empty($data['password'])) {
            $sql .= ', Password = :password';
            $params['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        }

        $sql .= ' WHERE Cedula = :cedula';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(string $cedula): bool
    {
        $stmt = $this->db->prepare('DELETE FROM ' . $this->t('Usuarios') . ' WHERE Cedula = :cedula');
        return $stmt->execute(['cedula' => $cedula]);
    }
}
