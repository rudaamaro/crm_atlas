<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    flash('error', 'Identificador invalido.');
    redirect('atendimentos.php');
}

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT id, representante_id, vendedor_id FROM atendimentos WHERE id = :id');
$stmt->execute([':id' => $id]);
$registro = $stmt->fetch();

$user = current_user();
$isAdmin = is_admin($user);

if (!$registro) {
    flash('error', 'Atendimento nao encontrado.');
    redirect('atendimentos.php');
}

if (!$isAdmin && (int)$registro['representante_id'] !== (int)$user['id'] && (int)$registro['vendedor_id'] !== (int)$user['id']) {
    flash('error', 'Voce nao tem permissao para excluir este atendimento.');
    redirect('atendimentos.php');
}

$del = $pdo->prepare('DELETE FROM atendimentos WHERE id = :id');
$del->execute([':id' => $id]);

log_activity((int)$user['id'], 'atendimento_delete', 'Removeu atendimento #' . $id);
flash('status', 'Atendimento removido com sucesso.');

redirect('atendimentos.php');






