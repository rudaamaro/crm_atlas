-- CRM Atlas - Esquema completo atualizado
-- Inclui todas as tabelas, colunas, chaves estrangeiras e dados mínimos
-- Gereciamento de usuarios, municipios, atendimentos e logs de atividades

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

START TRANSACTION;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('ADMIN','REPRESENTANTE','ADMIN/REPRESENTANTE','VENDEDOR') NOT NULL DEFAULT 'REPRESENTANTE',
    estado VARCHAR(10) NOT NULL DEFAULT '',
    cidade VARCHAR(150) NULL,
    representante_id INT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_representante FOREIGN KEY (representante_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_estados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    estado VARCHAR(10) NOT NULL,
    CONSTRAINT fk_user_estados_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS municipios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    estado VARCHAR(10) NOT NULL DEFAULT '',
    codigo_ibge VARCHAR(20) NULL,
    representante_id INT NULL,
    vendedor_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_nome (nome),
    CONSTRAINT fk_municipios_representante FOREIGN KEY (representante_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_municipios_vendedor FOREIGN KEY (vendedor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS atendimentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    municipio_id INT NOT NULL,
    representante_id INT NULL,
    vendedor_id INT NULL,
    representante_nome_externo VARCHAR(150) NULL,
    periodo_relatorio VARCHAR(100) NULL,
    secretaria_escola VARCHAR(150) NULL,
    contato_principal VARCHAR(150) NULL,
    status_visita VARCHAR(100) NULL,
    observacoes TEXT NULL,
    tipo_contato VARCHAR(100) NULL,
    data_contato DATE NULL,
    situacao_atual VARCHAR(120) NULL,
    valor_proposta DECIMAL(12,2) NULL,
    itens_projeto TEXT NULL,
    data_envio DATE NULL,
    status_proposta VARCHAR(120) NULL,
    previsao_fechamento DATE NULL,
    dificuldades TEXT NULL,
    acoes_futuras TEXT NULL,
    observacoes_gerais TEXT NULL,
    responsavel_principal TINYINT(1) NOT NULL DEFAULT 0,
    status_geral ENUM('ATIVO','CONCLUIDO','ARQUIVADO') NOT NULL DEFAULT 'ATIVO',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_atendimentos_municipio FOREIGN KEY (municipio_id) REFERENCES municipios(id) ON DELETE CASCADE,
    CONSTRAINT fk_atendimentos_representante FOREIGN KEY (representante_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_atendimentos_vendedor FOREIGN KEY (vendedor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_atendimentos_municipio (municipio_id),
    INDEX idx_atendimentos_representante (representante_id),
    INDEX idx_atendimentos_vendedor (vendedor_id),
    INDEX idx_atendimentos_data_contato (data_contato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activities_user (user_id),
    CONSTRAINT fk_activities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dados iniciais seguros
INSERT INTO users (name, email, password_hash, role, estado, cidade, active)
VALUES
('Administrador', 'admin@sistema.local', '$2y$10$grMkTFnvUbMwTAeYNAuhfaxR1fKrM4pGx3I2UqO2zzAQFgSsyoHM', 'ADMIN', 'MT', 'Cuiaba', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO municipios (nome, estado, codigo_ibge) VALUES
('Cuiaba', 'MT', '5103403'),
('Varzea Grande', 'MT', '5108402'),
('Rondonopolis', 'MT', '5107602'),
('Sinop', 'MT', '5107909'),
('Tangara da Serra', 'MT', '5107958')
ON DUPLICATE KEY UPDATE estado = VALUES(estado), codigo_ibge = VALUES(codigo_ibge);

COMMIT;
