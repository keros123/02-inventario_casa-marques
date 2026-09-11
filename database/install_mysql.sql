-- Inventario Casa del Marqués — instalación MySQL (vacía)
-- Prefijo: casa_marques_
-- Charset / cotejamiento: utf8mb4 / utf8mb4_unicode_ci (tildes y diéresis)
-- Zona horaria: America/Bogota (UTC-05:00)
-- Datos iniciales: categoría General + usuario admin (clave 123456)
--
-- Uso:
--   mysql -u admin -p < database/install_mysql.sql

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET time_zone = '-05:00';

CREATE DATABASE IF NOT EXISTS Inventario_cm
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

ALTER DATABASE Inventario_cm
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE Inventario_cm;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS casa_marques_Usuarios (
    Cedula VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
    Nombres VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    Password VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    Tipo ENUM('Admin', 'Usuario') NOT NULL DEFAULT 'Usuario',
    Estado ENUM('Activo', 'Inactivo') NOT NULL DEFAULT 'Activo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS casa_marques_Categorias (
    id_categoria INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Nombre VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    Indicador VARCHAR(20) DEFAULT NULL,
    Estado ENUM('Activo', 'Inactivo', 'Eliminado') NOT NULL DEFAULT 'Activo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS casa_marques_Inventario (
    Codigo VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL PRIMARY KEY,
    Elemento VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    id_categoria INT DEFAULT NULL,
    Descripcion TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    Cantidad INT NOT NULL DEFAULT 0,
    Fotografia VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    Estado ENUM('Activo', 'Inactivo', 'Eliminado') NOT NULL DEFAULT 'Activo',
    CONSTRAINT fk_casa_marques_inv_categoria FOREIGN KEY (id_categoria)
        REFERENCES casa_marques_Categorias(id_categoria) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS casa_marques_Movimientos (
    id_movimiento INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Tipo ENUM('Ingreso', 'Prestamo', 'Devolucion', 'Dar_Baja') NOT NULL,
    Consecutivo INT NOT NULL,
    Fecha DATE NOT NULL,
    Cedula_cuentadante VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    Descripcion TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    Estado ENUM('Activo', 'Inactivo', 'Cerrado') NOT NULL DEFAULT 'Activo',
    id_movimiento_ref INT DEFAULT NULL,
    CONSTRAINT fk_casa_marques_mov_cuentadante FOREIGN KEY (Cedula_cuentadante)
        REFERENCES casa_marques_Usuarios(Cedula) ON UPDATE CASCADE,
    CONSTRAINT fk_casa_marques_mov_ref FOREIGN KEY (id_movimiento_ref)
        REFERENCES casa_marques_Movimientos(id_movimiento) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS casa_marques_Det_Movimientos (
    Consecutivo INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_movimiento INT NOT NULL,
    id_Detalle INT NOT NULL DEFAULT 1,
    Codigo_elemento VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    Cantidad INT NOT NULL DEFAULT 1,
    Estado ENUM('Activo', 'Inactivo') NOT NULL DEFAULT 'Activo',
    CONSTRAINT fk_casa_marques_det_movimiento FOREIGN KEY (id_movimiento)
        REFERENCES casa_marques_Movimientos(id_movimiento) ON DELETE CASCADE,
    CONSTRAINT fk_casa_marques_det_inventario FOREIGN KEY (Codigo_elemento)
        REFERENCES casa_marques_Inventario(Codigo) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS casa_marques_Movimiento_Fotos (
    id_foto INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_movimiento INT NOT NULL,
    Ruta VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    Orden TINYINT NOT NULL DEFAULT 1,
    CONSTRAINT fk_casa_marques_foto_movimiento FOREIGN KEY (id_movimiento)
        REFERENCES casa_marques_Movimientos(id_movimiento) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS casa_marques_Solicitudes_Prestamo (
    id_solicitud INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    Cedula_solicitante VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    Fecha_solicitud DATE NOT NULL,
    Descripcion TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    Estado ENUM('Pendiente', 'Aprobada', 'Rechazada', 'Cancelada') NOT NULL DEFAULT 'Pendiente',
    id_movimiento INT DEFAULT NULL,
    Cedula_aprobador VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    Fecha_resolucion DATETIME DEFAULT NULL,
    Motivo_rechazo TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    CONSTRAINT fk_casa_marques_sol_solicitante FOREIGN KEY (Cedula_solicitante)
        REFERENCES casa_marques_Usuarios(Cedula) ON UPDATE CASCADE,
    CONSTRAINT fk_casa_marques_sol_movimiento FOREIGN KEY (id_movimiento)
        REFERENCES casa_marques_Movimientos(id_movimiento) ON DELETE SET NULL,
    CONSTRAINT fk_casa_marques_sol_aprobador FOREIGN KEY (Cedula_aprobador)
        REFERENCES casa_marques_Usuarios(Cedula) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS casa_marques_Det_Solicitudes (
    id_detalle INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_solicitud INT NOT NULL,
    id_linea INT NOT NULL DEFAULT 1,
    Codigo_elemento VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    Cantidad INT NOT NULL DEFAULT 1,
    CONSTRAINT fk_casa_marques_detsol_solicitud FOREIGN KEY (id_solicitud)
        REFERENCES casa_marques_Solicitudes_Prestamo(id_solicitud) ON DELETE CASCADE,
    CONSTRAINT fk_casa_marques_detsol_inventario FOREIGN KEY (Codigo_elemento)
        REFERENCES casa_marques_Inventario(Codigo) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO casa_marques_Categorias (Nombre, Estado)
SELECT 'General', 'Activo'
WHERE NOT EXISTS (
    SELECT 1 FROM casa_marques_Categorias WHERE Nombre = 'General' LIMIT 1
);

INSERT INTO casa_marques_Usuarios (Cedula, Nombres, Password, Tipo, Estado)
SELECT 'admin', 'Administrador Sistema',
       '$2y$10$zIA6XnjeLRv5noLtmWP4zuOIrUSpbB27eVRGVcMhyrGaBnT6iH8nO',
       'Admin', 'Activo'
WHERE NOT EXISTS (
    SELECT 1 FROM casa_marques_Usuarios WHERE Cedula = 'admin' LIMIT 1
);
-- Contraseña del admin: 123456
