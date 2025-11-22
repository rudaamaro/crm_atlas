<?php
require_once __DIR__ . '/config.php';

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
    }
}

function flash(string $key, string $message, string $type = 'info'): void
{
    $_SESSION['flash'][$key] = ['message' => $message, 'type' => $type];
}

function get_flash(string $key): ?array
{
    if (!empty($_SESSION['flash'][$key])) {
        $message = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $message;
    }
    return null;
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function old(string $key, $default = '')
{
    return $_SESSION['old'][$key] ?? $default;
}

function remember_old(array $data): void
{
    $_SESSION['old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['old']);
}

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function format_currency($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

function format_date(?string $date, string $format = 'd/m/Y'): string
{
    if (!$date) {
        return '-';
    }
    try {
        $dt = new DateTime($date);
        return $dt->format($format);
    } catch (Throwable $e) {
        return $date;
    }
}

function query_string(array $params): string
{
    return http_build_query(array_filter($params, static function ($value) {
        return $value !== null && $value !== '';
    }));
}

function user_roles(array $user): array
{
    $raw = trim((string)($user['role'] ?? ''));
    if ($raw === '') {
        return [];
    }
    $parts = array_map('trim', explode('/', $raw));
    return array_values(array_filter($parts, static function ($role) {
        return $role !== '';
    }));
}

function user_has_role(array $user, string $role): bool
{
    $role = strtoupper($role);
    $normalized = array_map('strtoupper', user_roles($user));
    return in_array($role, $normalized, true);
}

function is_admin(array $user): bool
{
    return user_has_role($user, 'ADMIN');
}

function is_representante(array $user): bool
{
    return user_has_role($user, 'REPRESENTANTE');
}

function is_vendedor(array $user): bool
{
    return user_has_role($user, 'VENDEDOR');
}

function sanitize_decimal(?string $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    $normalized = str_replace(['R$', '.', ' '], '', $value);
    $normalized = str_replace(',', '.', $normalized);
    if ($normalized === '') {
        return null;
    }
    return (float)$normalized;
}

function parse_representante_externo(?string $raw): array
{
    $admin = '';
    $externo = '';
    if ($raw !== null && $raw !== '') {
        $parts = explode('|', (string)$raw, 2);
        if (count($parts) === 2) {
            $admin = trim($parts[0]);
            $externo = trim($parts[1]);
        } else {
            $externo = trim($parts[0]);
        }
    }
    return [$admin, $externo];
}

function format_representante_nome(?string $rawExternal, ?string $usuarioNome = null): string
{
    [$admin, $externo] = parse_representante_externo($rawExternal);
    if ($externo !== '') {
        $nomeAdmin = $admin !== '' ? $admin : trim((string)$usuarioNome);
        if ($nomeAdmin === '') {
            $nomeAdmin = 'Externo';
        }
        return $nomeAdmin . ' (' . $externo . ')';
    }
    if ($usuarioNome !== null && trim($usuarioNome) !== '') {
        return trim($usuarioNome);
    }
    if ($admin !== '') {
        return $admin;
    }
    return 'Sem representante';
}
start_session();








