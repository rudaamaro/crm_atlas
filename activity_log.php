<?php
require_once __DIR__ . '/db.php';

function log_activity(int $userId, string $action, string $details = ''): void
{
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare('INSERT INTO activities (user_id, action, details) VALUES (:u, :a, :d)');
        $stmt->execute([':u' => $userId, ':a' => $action, ':d' => $details]);
    } catch (Throwable $e) {
        error_log('[activity_log] ' . $e->getMessage());
        // nunca re-lança
    }
}
