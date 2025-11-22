<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
require_admin();

$pdo = get_pdo();
$usuarios = $pdo->query('SELECT id, name, email, role, estado, cidade, active, last_login_at, created_at FROM users ORDER BY role DESC, name')->fetchAll();

$pageTitle = 'Usuarios';
require __DIR__ . '/partials/header.php';
?>
<div class="flex flex-col gap-6">
    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-center">
        <div>
            <h1 class="text-2xl font-semibold text-slate-800">Usuarios</h1>
            <p class="mt-1 text-sm text-slate-600">Gerencie perfis e acessos ao sistema.</p>
        </div>
        <a href="usuario_form.php" class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Novo usuario</a>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 bg-white text-sm shadow">
            <thead class="bg-slate-50">
                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                    <th class="px-4 py-3">Nome</th>
                    <th class="px-4 py-3">Email</th>
                    <th class="px-4 py-3">Perfil</th>
                    <th class="px-4 py-3">Estado</th>
                    <th class="px-4 py-3">Ativo</th>
                    <th class="px-4 py-3">Ultimo login</th>
                    <th class="px-4 py-3 text-center">Acoes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php foreach ($usuarios as $linha): ?>
                    <tr>
                        <td class="px-4 py-3 font-semibold text-slate-800"><?= esc($linha['name']) ?></td>
                        <td class="px-4 py-3 text-slate-600"><?= esc($linha['email']) ?></td>
                        <td class="px-4 py-3 text-slate-600"><?= esc($linha['role']) ?></td>
                        <td class="px-4 py-3 text-slate-600"><?= esc($linha['estado']) ?></td>
                        <td class="px-4 py-3 text-slate-600">
                            <?php if ((int)$linha['active'] === 1): ?>
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-600">Ativo</span>
                            <?php else: ?>
                                <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold text-rose-600">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-slate-500"><?= esc(format_date($linha['last_login_at'], 'd/m/Y H:i')) ?></td>
                        <td class="px-4 py-3 text-center">
                            <a href="usuario_form.php?id=<?= $linha['id'] ?>" class="text-sm font-semibold text-slate-700 hover:text-slate-900">Editar</a>
                            <?php if ((int)$linha['id'] !== (int)$user['id']): ?>
                                <span class="mx-1 text-slate-300">|</span>
                                <?php if ((int)$linha['active'] === 1): ?>
                                    <a href="usuario_toggle.php?id=<?= $linha['id'] ?>&acao=desativar" class="text-sm font-semibold text-rose-600 hover:text-rose-700" onclick="return confirm('Desativar este usuario?');">Desativar</a>
                                <?php else: ?>
                                    <a href="usuario_toggle.php?id=<?= $linha['id'] ?>&acao=ativar" class="text-sm font-semibold text-emerald-600 hover:text-emerald-700" onclick="return confirm('Ativar este usuario?');">Ativar</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($usuarios)): ?>
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-slate-500">Nenhum usuario cadastrado ainda.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
require __DIR__ . '/partials/footer.php';






