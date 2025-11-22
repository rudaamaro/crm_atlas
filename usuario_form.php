<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$currentUser = current_user();
$isAdmin = is_admin($currentUser);
$isRep = is_representante($currentUser);
$isVendor = is_vendedor($currentUser);

if (!$isAdmin && !$isRep) {
    flash('auth', 'Acesso restrito a administradores ou representantes.');
    redirect_dashboard($currentUser);
}

$pdo = get_pdo();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

if (is_post()) {
    try {
        $id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $isEdit = $id > 0;

        $nome   = trim($_POST['name'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $role   = $_POST['role'] ?? ($isAdmin ? 'REPRESENTANTE' : 'VENDEDOR');
        $estado = trim($_POST['estado'] ?? '');
        $cidade = trim($_POST['cidade'] ?? '');
        $responsavelId = isset($_POST['representante_id']) ? (int)$_POST['representante_id'] : null;
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
        if (!in_array($role, ['ADMIN','REPRESENTANTE','ADMIN/REPRESENTANTE','VENDEDOR'], true)) $erros[] = 'Perfil invalido.';
        if ($isRep) {
            // Representante só cria/edita vendedores vinculados a si
            $role = 'VENDEDOR';
            $responsavelId = (int)$currentUser['id'];
            $estado = $currentUser['estado'];
        }

        if ($role === 'VENDEDOR' && $isAdmin) {
            if (!$responsavelId) {
                $erros[] = 'Selecione o representante responsável.';
            } else {
                $stmtRep = $pdo->prepare("SELECT id, estado FROM users WHERE id = :id AND role LIKE '%REPRESENTANTE%'");
                $stmtRep->execute([':id' => $responsavelId]);
                $responsavel = $stmtRep->fetch();
                if ($responsavel) {
                    $estado = (string)$responsavel['estado'];
                    $responsavelId = (int)$responsavel['id'];
                } else {
                    $erros[] = 'Representante responsável inválido.';
                }
            }
        }

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
                ':representante_id' => $responsavelId,
                ':id'     => $id,
            ];

            $sql = 'UPDATE users
                    SET name = :name, email = :email, role = :role, estado = :estado, cidade = :cidade, representante_id = :representante_id';

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
                INSERT INTO users (name, email, role, estado, cidade, representante_id, password_hash, active)
                VALUES (:name, :email, :role, :estado, :cidade, :representante_id, :password_hash, 1)
            ')->execute([
                ':name'          => $nome,
                ':email'         => $email,
                ':role'          => $role,
                ':estado'        => $estado,
                ':cidade'        => $cidade,
                ':representante_id' => $responsavelId,
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
    $stmt = $pdo->prepare('SELECT id, name, email, role, estado, cidade, representante_id FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        flash('error', 'Usuario nao encontrado.');
        redirect('usuarios.php');
    }

    if (!$isAdmin) {
        if ($usuario['role'] !== 'VENDEDOR' || (int)$usuario['representante_id'] !== (int)$currentUser['id']) {
            flash('auth', 'Você só pode editar vendedores vinculados a você.');
            redirect('usuarios.php');
        }
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
$estadoAtual  = esc(old('estado', $usuario['estado'] ?? ($isRep ? $currentUser['estado'] : '')));
$cidadeAtual  = esc(old('cidade', $usuario['cidade'] ?? ''));
$responsavelAtual = (int)old('representante_id', $usuario['representante_id'] ?? ($isRep ? $currentUser['id'] : 0));
$representantesDisponiveis = $isAdmin
    ? $pdo->query("SELECT id, name, estado FROM users WHERE role LIKE '%REPRESENTANTE%' AND active = 1 ORDER BY name")->fetchAll()
    : [];
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
            <?php if ($isAdmin): ?>
                <select name="role" id="role" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
                    <?php $perfilAtual = old('role', $usuario['role'] ?? 'REPRESENTANTE'); ?>
                    <option value="REPRESENTANTE" <?= $perfilAtual === 'REPRESENTANTE' ? 'selected' : '' ?>>Representante</option>
                    <option value="VENDEDOR" <?= $perfilAtual === 'VENDEDOR' ? 'selected' : '' ?>>Vendedor</option>
                    <option value="ADMIN" <?= $perfilAtual === 'ADMIN' ? 'selected' : '' ?>>Admin</option>
                    <option value="ADMIN/REPRESENTANTE" <?= $perfilAtual === 'ADMIN/REPRESENTANTE' ? 'selected' : '' ?>>Admin + Representante</option>
                </select>
            <?php else: ?>
                <input type="hidden" name="role" value="VENDEDOR">
                <div class="w-full rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">Vendedor</div>
            <?php endif; ?>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="representante_id">Representante responsável</label>
            <?php if ($isAdmin): ?>
                <select name="representante_id" id="representante_id" class="w-full rounded border border-slate-200 px-3 py-2 text-sm" <?= (old('role', $usuario['role'] ?? '') === 'VENDEDOR') ? '' : 'disabled' ?>>
                    <option value="">Selecione</option>
                    <?php foreach ($representantesDisponiveis as $rep): ?>
                        <option value="<?= $rep['id'] ?>" data-estado="<?= esc($rep['estado']) ?>" <?= (int)$rep['id'] === (int)$responsavelAtual ? 'selected' : '' ?>><?= esc($rep['name']) ?> (<?= esc($rep['estado']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-xs text-slate-500">Obrigatório para vendedores.</p>
            <?php else: ?>
                <input type="hidden" name="representante_id" value="<?= (int)$currentUser['id'] ?>">
                <div class="w-full rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">Vinculado a <?= esc($currentUser['name']) ?></div>
            <?php endif; ?>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="estado">Estado</label>
            <?php if ($isRep): ?>
                <input type="hidden" name="estado" value="<?= $estadoAtual ?>">
                <div class="w-full rounded border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600"><?= $estadoAtual ?></div>
            <?php else: ?>
                <select name="estado" id="estado" required
                        class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Selecione o estado (UF)</option>
                    <?php foreach ($todosEstados as $uf): ?>
                        <option value="<?= $uf ?>" <?= $estadoAtual === $uf ? 'selected' : '' ?>><?= $uf ?></option>
                    <?php endforeach; ?>
                </select>
                <p id="estado_hint" class="mt-1 text-xs text-slate-500 hidden">Estado herdado do representante responsável.</p>
            <?php endif; ?>
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
if ($isAdmin): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var roleSelect = document.getElementById('role');
    var repSelect = document.getElementById('representante_id');
    var estadoSelect = document.getElementById('estado');
    var estadoHint = document.getElementById('estado_hint');

    function estadoDoRepresentante() {
        if (!repSelect || repSelect.selectedIndex < 0) {
            return '';
        }
        var opt = repSelect.options[repSelect.selectedIndex];
        return opt ? (opt.getAttribute('data-estado') || '') : '';
    }

    function travarEstadoSeVendedor() {
        if (!estadoSelect) return;
        var isVendedor = roleSelect && roleSelect.value === 'VENDEDOR';
        var estadoRep = estadoDoRepresentante();

        if (isVendedor) {
            if (estadoRep !== '') {
                estadoSelect.value = estadoRep;
            }
            estadoSelect.classList.add('bg-slate-50', 'text-slate-600', 'pointer-events-none');
            estadoSelect.setAttribute('tabindex', '-1');
            if (estadoHint) estadoHint.classList.remove('hidden');
        } else {
            estadoSelect.classList.remove('bg-slate-50', 'text-slate-600', 'pointer-events-none');
            estadoSelect.removeAttribute('tabindex');
            if (estadoHint) estadoHint.classList.add('hidden');
        }
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', travarEstadoSeVendedor);
    }
    if (repSelect) {
        repSelect.addEventListener('change', travarEstadoSeVendedor);
    }

    travarEstadoSeVendedor();
});
</script>
<?php
endif;
require __DIR__ . '/partials/footer.php';
