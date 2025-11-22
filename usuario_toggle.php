<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$acao = $_GET['acao'] ?? '';

if ($id <= 0 || !in_array($acao, ['ativar', 'desativar'], true)) {
    flash('error', 'Requisicao invalida.');
    redirect('usuarios.php');
}

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT id, active FROM users WHERE id = :id');
$stmt->execute([':id' => $id]);
$usuario = $stmt->fetch();
if (!$usuario) {
    flash('error', 'Usuario nao encontrado.');
    redirect('usuarios.php');
}

$novoStatus = $acao === 'ativar' ? 1 : 0;
$pdo->prepare('UPDATE users SET active = :active WHERE id = :id')->execute([':active' => $novoStatus, ':id' => $id]);

log_activity((int)$_SESSION['user_id'], 'usuario_status', strtoupper($acao) . ' usuario #' . $id);
flash('status', 'Status atualizado com sucesso.');

redirect('usuarios.php');






