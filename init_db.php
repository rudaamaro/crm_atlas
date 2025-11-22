<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

function run_initial_migrations(): void
{
    static $alreadyRan = false;
    if ($alreadyRan) {
        return;
    }
    $alreadyRan = true;

    $pdo = get_pdo();

    $pdo->exec(<<<SQL
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
SQL);

    // Ajustes incrementais para bases já existentes
    ensure_user_columns($pdo);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS user_estados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    estado VARCHAR(10) NOT NULL,
    CONSTRAINT fk_user_estados_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    $pdo->exec(<<<SQL
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
SQL);

    $pdo->exec(<<<SQL
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
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(100) NOT NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activities_user (user_id),
    CONSTRAINT fk_activities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);

    ensure_status_column($pdo);
    ensure_external_rep_column($pdo);
    ensure_atendimento_columns($pdo);
    ensure_user_columns($pdo);
    ensure_municipio_columns($pdo);

    // <<< ALTERADO: só semeia admin se as constantes existirem e não estiverem vazias
    if (
        defined('DEFAULT_ADMIN_EMAIL') && DEFAULT_ADMIN_EMAIL !== '' &&
        defined('DEFAULT_ADMIN_PASSWORD') && DEFAULT_ADMIN_PASSWORD !== '' &&
        defined('DEFAULT_ADMIN_NAME') && DEFAULT_ADMIN_NAME !== ''
    ) {
        seed_default_admin($pdo);
    }
    // >>> FIM ALTERAÇÃO

    seed_sample_municipios($pdo);
}

function ensure_status_column(PDO $pdo): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atendimentos' AND COLUMN_NAME = 'status_geral'");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE atendimentos ADD COLUMN status_geral ENUM('ATIVO','CONCLUIDO','ARQUIVADO') NOT NULL DEFAULT 'ATIVO' AFTER responsavel_principal");
    }
}

function ensure_external_rep_column(PDO $pdo): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atendimentos' AND COLUMN_NAME = 'representante_nome_externo'");
    $stmt->execute();
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE atendimentos ADD COLUMN representante_nome_externo VARCHAR(150) NULL AFTER representante_id");
    }
    $stmt2 = $pdo->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atendimentos' AND COLUMN_NAME = 'representante_id'");
    $stmt2->execute();
    $isNullable = strtoupper((string)$stmt2->fetchColumn());
    if ($isNullable !== 'YES') {
        $pdo->exec("ALTER TABLE atendimentos MODIFY representante_id INT NULL");
    }
}

function ensure_atendimento_columns(PDO $pdo): void
{
    $columns = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'atendimentos'")
        ->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('vendedor_id', $columns, true)) {
        $pdo->exec("ALTER TABLE atendimentos ADD COLUMN vendedor_id INT NULL AFTER representante_id, ADD CONSTRAINT fk_atendimentos_vendedor FOREIGN KEY (vendedor_id) REFERENCES users(id) ON DELETE SET NULL");
        $pdo->exec("CREATE INDEX idx_atendimentos_vendedor ON atendimentos (vendedor_id)");
    }
}

function ensure_user_columns(PDO $pdo): void
{
    $columns = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")
        ->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('estado', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN estado VARCHAR(10) NOT NULL DEFAULT '' AFTER role");
    }
    if (!in_array('cidade', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN cidade VARCHAR(150) NULL AFTER estado");
    }
    if (!in_array('representante_id', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN representante_id INT NULL AFTER cidade, ADD CONSTRAINT fk_users_representante FOREIGN KEY (representante_id) REFERENCES users(id) ON DELETE SET NULL");
    }

    // Ajusta o ENUM para incluir vendedor
    $stmtRole = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
    $roleRow = $stmtRole->fetch();
    if ($roleRow && isset($roleRow['Type']) && stripos($roleRow['Type'], 'VENDEDOR') === false) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('ADMIN','REPRESENTANTE','ADMIN/REPRESENTANTE','VENDEDOR') NOT NULL DEFAULT 'REPRESENTANTE'");
    }
}

function ensure_municipio_columns(PDO $pdo): void
{
    $columns = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'municipios'")
        ->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('estado', $columns, true)) {
        $pdo->exec("ALTER TABLE municipios ADD COLUMN estado VARCHAR(10) NOT NULL DEFAULT '' AFTER nome");
    }
    if (!in_array('representante_id', $columns, true)) {
        $pdo->exec("ALTER TABLE municipios ADD COLUMN representante_id INT NULL AFTER codigo_ibge, ADD CONSTRAINT fk_municipios_representante FOREIGN KEY (representante_id) REFERENCES users(id) ON DELETE SET NULL");
    }
    if (!in_array('vendedor_id', $columns, true)) {
        $pdo->exec("ALTER TABLE municipios ADD COLUMN vendedor_id INT NULL AFTER representante_id, ADD CONSTRAINT fk_municipios_vendedor FOREIGN KEY (vendedor_id) REFERENCES users(id) ON DELETE SET NULL");
    }
}

function seed_default_admin(PDO $pdo): void
{
    // <<< ALTERADO: dupla proteção aqui também
    if (
        !defined('DEFAULT_ADMIN_EMAIL') || DEFAULT_ADMIN_EMAIL === '' ||
        !defined('DEFAULT_ADMIN_PASSWORD') || DEFAULT_ADMIN_PASSWORD === '' ||
        !defined('DEFAULT_ADMIN_NAME') || DEFAULT_ADMIN_NAME === ''
    ) {
        return;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
    $stmt->execute([':email' => DEFAULT_ADMIN_EMAIL]);
    if ($stmt->fetchColumn() == 0) {
        $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)');
        $insert->execute([
            ':name' => DEFAULT_ADMIN_NAME,
            ':email' => DEFAULT_ADMIN_EMAIL,
            ':password_hash' => password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT),
            ':role' => 'ADMIN',
        ]);
    }
    // >>> FIM ALTERAÇÃO
}

function seed_sample_municipios(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT COUNT(*) FROM municipios');
    if ((int)$stmt->fetchColumn() === 0) {
        $municipios = [
            ['nome' => 'Cuiaba', 'codigo' => '5103403'],
            ['nome' => 'Varzea Grande', 'codigo' => '5108402'],
            ['nome' => 'Rondonopolis', 'codigo' => '5107602'],
            ['nome' => 'Sinop', 'codigo' => '5107909'],
            ['nome' => 'Tangara da Serra', 'codigo' => '5107958'],
        ];
        $insert = $pdo->prepare('INSERT INTO municipios (nome, codigo_ibge) VALUES (:nome, :codigo)');
        foreach ($municipios as $m) {
            $insert->execute([
                ':nome' => $m['nome'],
                ':codigo' => $m['codigo'],
            ]);
        }
    }
}

run_initial_migrations();
