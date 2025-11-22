<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$user    = current_user();
$pdo     = get_pdo();
$isAdmin = is_admin($user);

// Carrega a lista para o filtro (apenas admin)
$representantesFiltro = [];
$repSelecionado = isset($_GET['representante_id']) ? (int)$_GET['representante_id'] : 0;
if ($isAdmin) {
    $representantesFiltro = $pdo->query("SELECT id, name FROM users WHERE role LIKE '%REPRESENTANTE%' AND active = 1 ORDER BY name")->fetchAll();
}

/**
 * Listagem de duplicidades
 */
$sql = <<<SQL
SELECT
    m.id   AS municipio_id,
    m.nome AS municipio_nome,
    COUNT(*) AS total,
    MAX(a.updated_at) AS ultima_atualizacao
FROM atendimentos a
INNER JOIN municipios m ON m.id = a.municipio_id
WHERE a.status_geral <> 'ARQUIVADO'
SQL;

$params = [];
if (!$isAdmin) {
    $sql .= " AND a.representante_id = :rep_self";
    $params[':rep_self'] = (int)$user['id'];
}

$sql .= " GROUP BY m.id, m.nome HAVING COUNT(*) >= 2";

if ($isAdmin && $repSelecionado > 0) {
    // município entra na lista apenas se tiver pelo menos 1 atendimento do rep selecionado
    $sql .= " AND SUM(CASE WHEN a.representante_id = :rep_sel THEN 1 ELSE 0 END) >= 1";
    $params[':rep_sel'] = $repSelecionado;
}

// ordem estável ao voltar da comparação
$sql .= " ORDER BY m.nome ASC, m.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$duplicidades = $stmt->fetchAll();

$pageTitle = 'Duplicidades';
require __DIR__ . '/partials/header.php';
?>
<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-slate-800">Duplicidades de atendimentos</h1>
            <p class="mt-1 text-sm text-slate-600">Municipios com mais de um representante ativo.</p>
        </div>

        <?php if ($isAdmin): ?>
            <form method="get" class="flex items-center gap-2 text-sm text-slate-600">
                <label for="representante_id" class="font-semibold">Representante:</label>
                <select name="representante_id" id="representante_id" class="rounded border border-slate-300 px-3 py-2 text-sm">
                    <option value="0">Todos</option>
                    <?php foreach ($representantesFiltro as $rep): ?>
                        <option value="<?= (int)$rep['id'] ?>" <?= $repSelecionado === (int)$rep['id'] ? 'selected' : '' ?>>
                            <?= esc($rep['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="rounded border border-slate-300 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">Aplicar</button>
                <?php if ($repSelecionado > 0): ?>
                    <a href="duplicidades.php" class="text-xs font-semibold text-slate-500 hover:text-slate-700">Limpar</a>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 bg-white text-sm shadow">
            <thead class="bg-slate-50">
                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                    <th class="px-4 py-3">Municipio</th>
                    <th class="px-4 py-3">Qtd. atendimentos</th>
                    <th class="px-4 py-3">Ultima atualizacao</th>
                    <th class="px-4 py-3 text-center">Acoes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php $i = 1; foreach ($duplicidades as $row): ?>
                    <tr>
                        <td class="px-4 py-3 font-semibold text-slate-800">
                            <span class="tabular-nums"><?= $i++ ?></span> - <?= esc($row['municipio_nome']) ?>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            <?= (int)$row['total'] ?>
                        </td>
                        <td class="px-4 py-3 text-slate-500">
                            <?= esc(format_date($row['ultima_atualizacao'], 'd/m/Y H:i')) ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a
                              href="duplicidade_comparar.php?municipio_id=<?= (int)$row['municipio_id'] ?><?= ($isAdmin && $repSelecionado > 0) ? '&representante_id='.$repSelecionado : '' ?>"
                              class="text-sm font-semibold text-slate-700 hover:text-slate-900"
                            >Comparar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($duplicidades)): ?>
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500">
                            Nenhuma duplicidade encontrada com o filtro atual.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
require __DIR__ . '/partials/footer.php';
