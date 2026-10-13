-- =============================================================================
-- Inicialización de Base de Datos (MariaDB)
-- Dominio 6: Inventario de Laboratorio | Entidad Principal: Equipo
-- =============================================================================

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

CREATE DATABASE IF NOT EXISTS inventario_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE inventario_db;

CREATE TABLE IF NOT EXISTS equipos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    marca VARCHAR(80) NOT NULL,
    numero_serie VARCHAR(80) NOT NULL UNIQUE,
    ubicacion VARCHAR(100) NOT NULL DEFAULT 'Laboratorio General',
    estado ENUM('Disponible', 'En uso', 'En mantenimiento', 'Fuera de servicio') NOT NULL DEFAULT 'Disponible',
    fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserción de los 3 registros iniciales requeridos
INSERT INTO equipos (nombre, marca, numero_serie, ubicacion, estado) VALUES
    ('Microscopio Óptico Binocular CX23', 'Olympus', 'OLY-CX23-001', 'Laboratorio de Biología A1', 'Disponible'),
    ('Centrífuga Refrigerada de Mesa', 'Thermo Scientific', 'THS-CENT-042', 'Laboratorio de Química B2', 'En uso'),
    ('Osciloscopio Digital de 100 MHz', 'Tektronix', 'TEK-TBS1102-089', 'Laboratorio de Electrónica C3', 'En mantenimiento');
