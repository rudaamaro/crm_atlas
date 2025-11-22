<?php
$mysqli = new mysqli('186.209.113.107','crmf5894_crm','520741/8a','crmf5894_crm');
if ($mysqli->connect_errno) {
    fwrite(STDERR, 'Connect error: ' . $mysqli->connect_error . PHP_EOL);
    exit(1);
}
$sql = <<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('ADMIN','REPRESENTANTE','ADMIN/REPRESENTANTE') NOT NULL DEFAULT 'REPRESENTANTE',
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS municipios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    codigo_ibge VARCHAR(20) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS atendimentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    municipio_id INT NOT NULL,
    representante_id INT NOT NULL,
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
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_atendimentos_municipio FOREIGN KEY (municipio_id) REFERENCES municipios(id) ON DELETE CASCADE,
    CONSTRAINT fk_atendimentos_representante FOREIGN KEY (representante_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_atendimentos_municipio (municipio_id),
    INDEX idx_atendimentos_representante (representante_id),
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

INSERT IGNORE INTO users (name, email, password_hash, role)
VALUES ('Administrador', 'admin@sistema.local', '$2y$10$grMkTFnvUbMwTAeYNAuhfaxR1fKrM4pGx3I2UqO2zzAQFgSsyoHM', 'ADMIN');

INSERT IGNORE INTO municipios (nome, codigo_ibge) VALUES
('Cuiaba', '5103403'),
('Varzea Grande', '5108402'),
('Rondonopolis', '5107602'),
('Sinop', '5107909'),
('Tangara da Serra', '5107958');
SQL;

if (!$mysqli->multi_query($sql)) {
    fwrite(STDERR, 'Exec error: ' . $mysqli->error . PHP_EOL);
    exit(1);
}

do {
    if ($result = $mysqli->store_result()) {
        $result->free();
    }
} while ($mysqli->more_results() && $mysqli->next_result());

echo "OK\n";
$mysqli->close();
?>

