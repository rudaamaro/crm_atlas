<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    flash('error', 'Requisição inválida.');
    redirect('usuarios.php');
}

$pdo = get_pdo();

// Impede excluir o próprio usuário logado
if ($id === (int)$_SESSION['user_id']) {
    flash('error', 'Você não pode excluir a si mesmo.');
    redirect('usuarios.php');
}

// Verifica se o usuário existe
$stmt = $pdo->prepare('SELECT id, name FROM users WHERE id = :id');
$stmt->execute([':id' => $id]);
$usuario = $stmt->fetch();

if (!$usuario) {
    flash('error', 'Usuário não encontrado.');
    redirect('usuarios.php');
}

try {
    $pdo->beginTransaction();

// Exclua aqui outras tabelas relacionadas, se existirem
// Exemplo: $pdo->prepare('DELETE FROM propostas WHERE user_id = :id')->execute([':id' => $id]);

    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);

    $pdo->commit();

    @log_activity((int)$_SESSION['user_id'], 'usuario_delete', 'Excluiu usuário #' . $id . ' (' . $usuario['name'] . ')');
    flash('status', 'Usuário excluído com sucesso.');

} catch (Throwable $e) {
    $pdo->rollBack();
    flash('error', 'Erro ao excluir: ' . $e->getMessage());
}


redirect('usuarios.php');
