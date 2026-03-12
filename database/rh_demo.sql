DROP DATABASE IF EXISTS botlocal_rh_demo;
CREATE DATABASE botlocal_rh_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE botlocal_rh_demo;

CREATE TABLE departamentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    responsable VARCHAR(120) NOT NULL,
    presupuesto_anual DECIMAL(12,2) NOT NULL
);

CREATE TABLE puestos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    nivel VARCHAR(50) NOT NULL,
    salario_min DECIMAL(10,2) NOT NULL,
    salario_max DECIMAL(10,2) NOT NULL
);

CREATE TABLE empleados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(80) NOT NULL,
    apellido VARCHAR(80) NOT NULL,
    email VARCHAR(150) NOT NULL,
    ciudad VARCHAR(80) NOT NULL,
    fecha_ingreso DATE NOT NULL,
    salario_mensual DECIMAL(10,2) NOT NULL,
    estatus ENUM('Activo', 'Vacaciones', 'Baja') NOT NULL DEFAULT 'Activo',
    departamento_id INT NOT NULL,
    puesto_id INT NOT NULL,
    manager_id INT NULL,
    KEY idx_empleados_departamento (departamento_id),
    KEY idx_empleados_puesto (puesto_id),
    KEY idx_empleados_manager (manager_id),
    KEY idx_empleados_estatus (estatus),
    FOREIGN KEY (departamento_id) REFERENCES departamentos(id),
    FOREIGN KEY (puesto_id) REFERENCES puestos(id),
    FOREIGN KEY (manager_id) REFERENCES empleados(id)
);

CREATE TABLE vacaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT NOT NULL,
    periodo_inicio DATE NOT NULL,
    periodo_fin DATE NOT NULL,
    dias_disponibles INT NOT NULL,
    dias_usados INT NOT NULL,
    KEY idx_vacaciones_empleado (empleado_id),
    KEY idx_vacaciones_periodo (periodo_inicio, periodo_fin),
    FOREIGN KEY (empleado_id) REFERENCES empleados(id)
);

CREATE TABLE asistencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empleado_id INT NOT NULL,
    fecha DATE NOT NULL,
    estado ENUM('Presente', 'Retardo', 'Falta', 'Home Office') NOT NULL,
    horas_trabajadas DECIMAL(4,2) NOT NULL,
    KEY idx_asistencias_empleado_fecha (empleado_id, fecha),
    KEY idx_asistencias_estado_fecha (estado, fecha),
    FOREIGN KEY (empleado_id) REFERENCES empleados(id)
);

INSERT INTO departamentos (nombre, responsable, presupuesto_anual) VALUES
('Recursos Humanos', 'Paola Mendoza', 850000.00),
('Tecnologia', 'Diego Arriaga', 2400000.00),
('Finanzas', 'Lucia Zamora', 1300000.00),
('Operaciones', 'Mario Cortes', 1750000.00),
('Ventas', 'Andrea Salinas', 2100000.00);

INSERT INTO puestos (nombre, nivel, salario_min, salario_max) VALUES
('HR Manager', 'Senior', 42000.00, 65000.00),
('Recruiter', 'Mid', 18000.00, 28000.00),
('HR Analyst', 'Mid', 20000.00, 32000.00),
('Software Engineer', 'Mid', 28000.00, 45000.00),
('Finance Analyst', 'Mid', 22000.00, 35000.00),
('Operations Coordinator', 'Mid', 19000.00, 30000.00),
('Sales Executive', 'Mid', 16000.00, 26000.00);

INSERT INTO empleados (nombre, apellido, email, ciudad, fecha_ingreso, salario_mensual, estatus, departamento_id, puesto_id, manager_id) VALUES
('Paola', 'Mendoza', 'paola.mendoza@empresa.local', 'Monterrey', '2021-02-15', 62000.00, 'Activo', 1, 1, NULL),
('Jorge', 'Luna', 'jorge.luna@empresa.local', 'Monterrey', '2022-07-01', 25500.00, 'Activo', 1, 2, 1),
('Sofia', 'Reyes', 'sofia.reyes@empresa.local', 'Guadalajara', '2023-01-10', 27800.00, 'Vacaciones', 1, 3, 1),
('Diego', 'Arriaga', 'diego.arriaga@empresa.local', 'Ciudad de Mexico', '2020-11-03', 64000.00, 'Activo', 2, 4, NULL),
('Elena', 'Torres', 'elena.torres@empresa.local', 'Ciudad de Mexico', '2023-06-19', 39000.00, 'Activo', 2, 4, 4),
('Lucia', 'Zamora', 'lucia.zamora@empresa.local', 'Puebla', '2021-08-09', 47000.00, 'Activo', 3, 5, NULL),
('Raul', 'Pineda', 'raul.pineda@empresa.local', 'Puebla', '2024-02-12', 24800.00, 'Activo', 3, 5, 6),
('Mario', 'Cortes', 'mario.cortes@empresa.local', 'Queretaro', '2019-04-22', 44000.00, 'Activo', 4, 6, NULL),
('Valeria', 'Nuñez', 'valeria.nunez@empresa.local', 'Queretaro', '2024-09-02', 21400.00, 'Activo', 4, 6, 8),
('Andrea', 'Salinas', 'andrea.salinas@empresa.local', 'Merida', '2020-05-17', 43000.00, 'Activo', 5, 7, NULL),
('Bruno', 'Castro', 'bruno.castro@empresa.local', 'Merida', '2023-03-27', 22500.00, 'Activo', 5, 7, 10);

INSERT INTO vacaciones (empleado_id, periodo_inicio, periodo_fin, dias_disponibles, dias_usados) VALUES
(1, '2026-01-01', '2026-12-31', 18, 4),
(2, '2026-01-01', '2026-12-31', 14, 2),
(3, '2026-01-01', '2026-12-31', 14, 9),
(4, '2026-01-01', '2026-12-31', 20, 3),
(5, '2026-01-01', '2026-12-31', 12, 1),
(6, '2026-01-01', '2026-12-31', 16, 6),
(7, '2026-01-01', '2026-12-31', 12, 0),
(8, '2026-01-01', '2026-12-31', 20, 5),
(9, '2026-01-01', '2026-12-31', 12, 0),
(10, '2026-01-01', '2026-12-31', 18, 7),
(11, '2026-01-01', '2026-12-31', 12, 1);

INSERT INTO asistencias (empleado_id, fecha, estado, horas_trabajadas) VALUES
(1, '2026-03-09', 'Presente', 8.00),
(2, '2026-03-09', 'Presente', 8.00),
(3, '2026-03-09', 'Home Office', 7.50),
(4, '2026-03-09', 'Presente', 9.00),
(5, '2026-03-09', 'Retardo', 7.00),
(6, '2026-03-09', 'Presente', 8.00),
(7, '2026-03-09', 'Presente', 8.00),
(8, '2026-03-09', 'Presente', 8.00),
(9, '2026-03-09', 'Retardo', 7.25),
(10, '2026-03-09', 'Presente', 8.00),
(11, '2026-03-09', 'Home Office', 7.75),
(1, '2026-03-10', 'Presente', 8.00),
(2, '2026-03-10', 'Presente', 8.00),
(3, '2026-03-10', 'Falta', 0.00),
(4, '2026-03-10', 'Presente', 8.50),
(5, '2026-03-10', 'Presente', 8.00),
(6, '2026-03-10', 'Presente', 8.00),
(7, '2026-03-10', 'Falta', 0.00),
(8, '2026-03-10', 'Presente', 8.00),
(9, '2026-03-10', 'Presente', 8.00),
(10, '2026-03-10', 'Presente', 8.00),
(11, '2026-03-10', 'Presente', 8.00);
