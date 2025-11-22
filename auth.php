<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/activity_log.php';

function current_user(): ?array
{
    static $cachedUser = null;

    if (isset($_SESSION['user_id'])) {
        if ($cachedUser !== null) {
            return $cachedUser;
        }
        try {
            $pdo = get_pdo();
            $stmt = $pdo->prepare('SELECT id, name, email, role, estado, cidade, representante_id, active, last_login_at FROM users WHERE id = :id');
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $user = $stmt->fetch();
            if ($user && (int)$user['active'] === 1) {
                $cachedUser = $user;
                return $cachedUser;
            }
        } catch (Throwable $e) {
            return null;
        }
    }
    return null;
}

function login(string $email, string $password): bool
{
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['active'] !== 1) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['user_id'] = (int)$user['id'];
    clear_old();

    $update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
    $update->execute([':id' => $user['id']]);

    log_activity((int)$user['id'], 'login', 'Login realizado');

    return true;
}

function logout(): void
{
    $user = current_user();
    if ($user) {
        log_activity((int)$user['id'], 'logout', 'Logout realizado');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_login(): void
{
    if (!current_user()) {
        flash('auth', 'Por favor, faca login para continuar.', 'warning');
        redirect('index.php');
    }
}

function require_admin(): void
{
    $user = current_user();
    if (!$user || !is_admin($user)) {
        flash('auth', 'Acesso restrito aos administradores.', 'danger');
        if ($user) {
            redirect_dashboard($user);
        }
        redirect('index.php');
    }
}

function ensure_logged_redirect(): void
{
    $user = current_user();
    if ($user) {
        redirect_dashboard($user);
    }
}

function redirect_dashboard(array $user): void
{
    if (is_admin($user)) {
        redirect('dashboard_admin.php');
    }
    if (is_vendedor($user)) {
        redirect('dashboard_vendedor.php');
    }
    redirect('dashboard_rep.php');
}







