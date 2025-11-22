<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
require_admin();

$pdo = get_pdo();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

if (is_post()) {
    try {
        $id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $isEdit = $id > 0;

        $nome   = trim($_POST['name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $role   = $_POST['role'] ?? 'REPRESENTANTE';
        $estado = trim($_POST['estado'] ?? '');
        $cidade = trim($_POST['cidade'] ?? '');
        $senha  = $_POST['password'] ?? '';
        $senhaConfirmacao = $_POST['password_confirmation'] ?? '';

        // estados adicionais (checkboxes) — somente admin usa essa tela (require_admin)
        $estadosExtra = isset($_POST['estados_extra']) && is_array($_POST['estados_extra'])
            ? array_values(array_unique(array_map('trim', $_POST['estados_extra'])))
            : [];

        remember_old($_POST);

        // validações
        $erros = [];
        if ($nome === '')  $erros[] = 'Informe o nome.';
        if ($email === '') $erros[] = 'Informe o email.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros[] = 'Email invalido.';
        if (!in_array($role, ['ADMIN','REPRESENTANTE','ADMIN/REPRESENTANTE'], true)) $erros[] = 'Perfil invalido.';
        if ($estado === '') $erros[] = 'Informe o estado.';

        if ($isEdit) {
            if ($senha !== '' && strlen($senha) < 6) $erros[] = 'A senha deve ter pelo menos 6 caracteres.';
            if ($senha !== $senhaConfirmacao)        $erros[] = 'As senhas nao conferem.';
        } else {
            if ($senha === '' || strlen($senha) < 6) $erros[] = 'Defina uma senha com pelo menos 6 caracteres.';
            if ($senha !== $senhaConfirmacao)        $erros[] = 'As senhas nao conferem.';
        }

        // email único
        $stmtEmail = $pdo->prepare(
            'SELECT id FROM users
             WHERE email = :email
               AND (:id0 = 0 OR id <> :id1)
             LIMIT 1'
        );
        $stmtEmail->execute([
            ':email' => $email,
            ':id0'   => $id,
            ':id1'   => $id,
        ]);
        if ($stmtEmail->fetch()) {
            $erros[] = 'Ja existe um usuario com este email.';
        }

        if (!empty($erros)) {
            flash('error', implode(' ', $erros));
            redirect($isEdit ? 'usuario_form.php?id='.$id : 'usuario_form.php');
        }

        // INSERT / UPDATE
        if ($isEdit) {
            $params = [
                ':name'   => $nome,
                ':email'  => $email,
                ':role'   => $role,
                ':estado' => $estado,
                ':cidade' => $cidade,
                ':id'     => $id,
            ];

            $sql = 'UPDATE users
                    SET name = :name, email = :email, role = :role, estado = :estado, cidade = :cidade';

            if ($senha !== '') {
                $sql .= ', password_hash = :password_hash';
                $params[':password_hash'] = password_hash($senha, PASSWORD_DEFAULT);
            }

            $sql .= ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);

            // Salva estados adicionais (substitui tudo)
            $pdo->prepare('DELETE FROM user_estados WHERE user_id = :id')->execute([':id' => $id]);
            if (!empty($estadosExtra)) {
                $ins = $pdo->prepare('INSERT INTO user_estados (user_id, estado) VALUES (:id, :estado)');
                foreach ($estadosExtra as $uf) {
                    if ($uf !== '') {
                        $ins->execute([':id' => $id, ':estado' => $uf]);
                    }
                }
            }

            @log_activity((int)$_SESSION['user_id'], 'usuario_update', 'Atualizou usuario #'.$id);
            flash('status', 'Usuario atualizado com sucesso.');
        } else {
            // cria usuário
            $pdo->prepare('
                INSERT INTO users (name, email, role, estado, cidade, password_hash, active)
                VALUES (:name, :email, :role, :estado, :cidade, :password_hash, 1)
            ')->execute([
                ':name'          => $nome,
                ':email'         => $email,
                ':role'          => $role,
                ':estado'        => $estado,
                ':cidade'        => $cidade,
                ':password_hash' => password_hash($senha, PASSWORD_DEFAULT),
            ]);

            $novoId = (int)$pdo->lastInsertId();

            // salva estados adicionais (se houver)
            if (!empty($estadosExtra)) {
                $ins = $pdo->prepare('INSERT INTO user_estados (user_id, estado) VALUES (:id, :estado)');
                foreach ($estadosExtra as $uf) {
                    if ($uf !== '') {
                        $ins->execute([':id' => $novoId, ':estado' => $uf]);
                    }
                }
            }

            @log_activity((int)$_SESSION['user_id'], 'usuario_create', 'Criou usuario #'.$novoId);
            flash('status', 'Usuario criado com sucesso.');
        }

        clear_old();
        redirect('usuarios.php');

    } catch (Throwable $e) {
        error_log('[usuario_form] '.$e->getMessage());
        flash('error', 'Nao foi possivel salvar o usuario.');
        redirect($isEdit ? 'usuario_form.php?id='.$id : 'usuario_form.php');
    }
}

// Carrega dados para edição
$usuario = null;
$estadosExtras = [];
if ($isEdit) {
    $stmt = $pdo->prepare('SELECT id, name, email, role, estado, cidade FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        flash('error', 'Usuario nao encontrado.');
        redirect('usuarios.php');
    }

    // estados adicionais do usuário
    $stmtExtra = $pdo->prepare('SELECT estado FROM user_estados WHERE user_id = :id');
    $stmtExtra->execute([':id' => $id]);
    $estadosExtras = $stmtExtra->fetchAll(PDO::FETCH_COLUMN);
}

$pageTitle = $isEdit ? 'Editar usuario' : 'Novo usuario';
require __DIR__ . '/partials/header.php';

// Lista de UFs (use input text se preferir; aqui deixo como <select>)
$todosEstados = ['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'];
$estadoAtual  = esc(old('estado', $usuario['estado'] ?? ''));
$cidadeAtual  = esc(old('cidade', $usuario['cidade'] ?? ''));
?>
<div class="max-w-xl">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-slate-800"><?= esc($pageTitle) ?></h1>
        <a href="usuarios.php" class="text-sm font-semibold text-slate-600 hover:text-slate-800">&larr; Voltar</a>
    </div>

    <form method="post" class="space-y-5 rounded-lg bg-white p-6 shadow">
        <input type="hidden" name="id" value="<?= $usuario['id'] ?? '' ?>">

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="name">Nome</label>
            <input type="text" name="name" id="name" required
                   value="<?= esc(old('name', $usuario['name'] ?? '')) ?>"
                   class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="email">Email</label>
            <input type="email" name="email" id="email" required
                   value="<?= esc(old('email', $usuario['email'] ?? '')) ?>"
                   class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="role">Perfil</label>
            <select name="role" id="role" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
                <?php $perfilAtual = old('role', $usuario['role'] ?? 'REPRESENTANTE'); ?>
                <option value="REPRESENTANTE" <?= $perfilAtual === 'REPRESENTANTE' ? 'selected' : '' ?>>Representante</option>
                <option value="ADMIN" <?= $perfilAtual === 'ADMIN' ? 'selected' : '' ?>>Admin</option>
                <option value="ADMIN/REPRESENTANTE" <?= $perfilAtual === 'ADMIN/REPRESENTANTE' ? 'selected' : '' ?>>Admin + Representante</option>
            </select>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="estado">Estado</label>
            <select name="estado" id="estado" required
                    class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
                <option value="">Selecione o estado (UF)</option>
                <?php foreach ($todosEstados as $uf): ?>
                    <option value="<?= $uf ?>" <?= $estadoAtual === $uf ? 'selected' : '' ?>><?= $uf ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="cidade">Cidade (opcional)</label>
            <input type="text" name="cidade" id="cidade"
                   value="<?= $cidadeAtual ?>"
                   class="w-full rounded border border-slate-200 px-3 py-2 text-sm"
                   placeholder="Ex: Barra do Garças">
        </div>

        <?php if ($isEdit): ?>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500">Estados adicionais (Admin)</label>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($todosEstados as $uf): ?>
                        <label class="flex items-center gap-1 text-sm">
                            <input type="checkbox" name="estados_extra[]" value="<?= $uf ?>"
                                   <?= in_array($uf, $estadosExtras ?? [], true) ? 'checked' : '' ?>>
                            <?= $uf ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="mt-1 text-xs text-slate-500">Use para conceder acesso a mais de um estado.</p>
            </div>
        <?php endif; ?>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="password">
                Senha <?= $isEdit ? '(preencha para alterar)' : '' ?>
            </label>
            <input type="password" name="password" id="password" <?= $isEdit ? '' : 'required' ?>
                   class="w-full rounded border border-slate-200 px-3 py-2 text-sm" minlength="6">
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="password_confirmation">Confirmar senha</label>
            <input type="password" name="password_confirmation" id="password_confirmation" <?= $isEdit ? '' : 'required' ?>
                   class="w-full rounded border border-slate-200 px-3 py-2 text-sm" minlength="6">
        </div>

        <div class="flex justify-between items-center mt-6">
            <div>
                <a href="usuarios.php"
                   class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">
                    &larr; Voltar
                </a>
            </div>

            <div class="flex gap-3">
                <?php if ($isEdit && (int)$usuario['id'] !== (int)$_SESSION['user_id']): ?>
                    <a href="usuario_delete.php?id=<?= $usuario['id'] ?>"
                       class="rounded border border-rose-400 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50"
                       onclick="return confirm('Excluir permanentemente este usuário? Esta ação não poderá ser desfeita.');">
                        Excluir
                    </a>
                <?php endif; ?>

                <button type="submit"
                        class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Salvar
                </button>
            </div>
        </div>
    </form>
</div>
<?php
clear_old();
require __DIR__ . '/partials/footer.php';
