<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
$user = current_user();
if (is_admin($user)) {
    redirect('dashboard_admin.php');
}

$pdo = get_pdo();
$userId = (int)$user['id'];

// Vendedores do representante (ativos)
$sqlVend = <<<SQL
SELECT id, name
FROM users
WHERE representante_id = :rep
  AND role LIKE '%VENDEDOR%'
  AND active = 1
ORDER BY name
SQL;
$stmtVend = $pdo->prepare($sqlVend);
$stmtVend->execute([':rep' => $userId]);
$vendedores = $stmtVend->fetchAll();

$vendedorSelecionado = isset($_GET['vendedor_id']) ? (int)$_GET['vendedor_id'] : 0;
$idsPermitidos = array_merge([$userId], array_map(static fn($v) => (int)$v['id'], $vendedores));

if ($vendedorSelecionado > 0 && in_array($vendedorSelecionado, $idsPermitidos, true)) {
    $alvoIds = [$vendedorSelecionado];
} else {
    $alvoIds = $idsPermitidos;
    $vendedorSelecionado = 0; // garante consistência ao renderizar o filtro
}

$wherePartes = ["status_geral <> 'ARQUIVADO'"];
$params = [];

if (count($alvoIds) === 1) {
    $wherePartes[] = '(representante_id = :uid OR vendedor_id = :uid)';
    $params[':uid'] = $alvoIds[0];
} else {
    $ph = [];
    foreach ($alvoIds as $idx => $id) {
        $ph[] = ':uid' . $idx;
        $params[':uid' . $idx] = $id;
    }
    $inList = implode(',', $ph);
    $wherePartes[] = "(representante_id IN ($inList) OR vendedor_id IN ($inList))";
}

$whereSQL = implode(' AND ', $wherePartes);

$totalAtendimentos = (int)fetch_scalar($pdo, "SELECT COUNT(*) FROM atendimentos WHERE $whereSQL", $params);
$municipiosAtendidos = (int)fetch_scalar($pdo, "SELECT COUNT(DISTINCT municipio_id) FROM atendimentos WHERE $whereSQL", $params);
$valorTotal = (float)fetch_scalar($pdo, "SELECT COALESCE(SUM(valor_proposta), 0) FROM atendimentos WHERE $whereSQL AND valor_proposta IS NOT NULL", $params);

$municipiosCompartilhadosSql = <<<SQL
SELECT COUNT(*) FROM (
    SELECT a.municipio_id
    FROM atendimentos a
    INNER JOIN (
        SELECT municipio_id
        FROM atendimentos
        WHERE status_geral <> 'ARQUIVADO'
        GROUP BY municipio_id
        HAVING COUNT(*) >= 2
    ) dup ON dup.municipio_id = a.municipio_id
    WHERE $whereSQL
    GROUP BY a.municipio_id
) compartilhados
SQL;
$municipiosCompartilhados = (int)fetch_scalar($pdo, $municipiosCompartilhadosSql, $params);

$situacaoStmt = $pdo->prepare("SELECT IFNULL(situacao_atual, 'Nao informado') AS situacao, COUNT(*) AS total FROM atendimentos WHERE $whereSQL GROUP BY situacao ORDER BY total DESC");
$situacaoStmt->execute($params);
$situacaoPorContagem = $situacaoStmt->fetchAll();

$statusStmt = $pdo->prepare("SELECT IFNULL(status_proposta, 'Nao informado') AS status_p, COUNT(*) AS total FROM atendimentos WHERE $whereSQL GROUP BY status_p ORDER BY total DESC");
$statusStmt->execute($params);
$statusPorContagem = $statusStmt->fetchAll();

$topStmt = $pdo->prepare("SELECT m.nome AS municipio, COUNT(*) AS total FROM atendimentos a INNER JOIN municipios m ON m.id = a.municipio_id WHERE $whereSQL GROUP BY a.municipio_id, m.nome ORDER BY total DESC LIMIT 5");
$topStmt->execute($params);
$topPrefeituras = $topStmt->fetchAll();

$pageTitle = 'Dashboard Pessoal';
require __DIR__ . '/partials/header.php';
?>
<div class="mb-4 flex flex-col gap-2 text-sm text-slate-600 md:flex-row md:items-center md:gap-3">
    <form method="get" class="flex flex-wrap items-center gap-2">
        <label for="vendedor_id" class="font-semibold">Filtrar por vendedor:</label>
        <select name="vendedor_id" id="vendedor_id" class="rounded border border-slate-300 px-3 py-2 text-sm">
            <option value="0">Todos (você e sua equipe)</option>
            <?php foreach ($vendedores as $vend): ?>
                <option value="<?= (int)$vend['id'] ?>" <?= $vendedorSelecionado === (int)$vend['id'] ? 'selected' : '' ?>><?= esc($vend['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="rounded border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Aplicar</button>
        <?php if ($vendedorSelecionado > 0): ?>
            <a href="dashboard_rep.php" class="text-xs font-semibold text-slate-500 hover:text-slate-700">Limpar filtro</a>
        <?php endif; ?>
    </form>
    <?php if (empty($vendedores)): ?>
        <p class="text-xs text-slate-500">Nenhum vendedor vinculado. Você está vendo apenas seus dados.</p>
    <?php endif; ?>
</div>
<section class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
    <article class="rounded-lg bg-white p-4 shadow">
        <div class="text-sm text-slate-500">Atendimentos ativos</div>
        <div class="text-2xl font-semibold text-slate-800"><?= $totalAtendimentos ?></div>
    </article>
    <article class="rounded-lg bg-white p-4 shadow">
        <div class="text-sm text-slate-500">Municipios atendidos</div>
        <div class="text-2xl font-semibold text-slate-800"><?= $municipiosAtendidos ?></div>
    </article>
    <article class="rounded-lg bg-white p-4 shadow">
        <div class="text-sm text-slate-500">Municipios compartilhados</div>
        <div class="text-2xl font-semibold text-rose-600"><?= $municipiosCompartilhados ?></div>
    </article>
    <article class="rounded-lg bg-white p-4 shadow">
        <div class="text-sm text-slate-500">Valor total das propostas</div>
        <div class="text-2xl font-semibold text-slate-800"><?= esc(format_currency($valorTotal)) ?></div>
    </article>
</section>

<section class="mt-8 grid gap-6 lg:grid-cols-2">
    <div class="rounded-lg bg-white p-6 shadow">
        <h2 class="mb-4 text-lg font-semibold text-slate-800">Situacao atual</h2>
        <ul class="space-y-2">
            <?php foreach ($situacaoPorContagem as $row): ?>
                <li class="flex justify-between text-sm">
                    <span><?= esc($row['situacao']) ?></span>
                    <span class="font-semibold text-slate-700"><?= $row['total'] ?></span>
                </li>
            <?php endforeach; ?>
            <?php if (empty($situacaoPorContagem)): ?>
                <li class="text-sm text-slate-500">Nenhum atendimento ativo.</li>
            <?php endif; ?>
        </ul>
    </div>
    <div class="rounded-lg bg-white p-6 shadow">
        <h2 class="mb-4 text-lg font-semibold text-slate-800">Status da proposta</h2>
        <ul class="space-y-2">
            <?php foreach ($statusPorContagem as $row): ?>
                <li class="flex justify-between text-sm">
                    <span><?= esc($row['status_p']) ?></span>
                    <span class="font-semibold text-slate-700"><?= $row['total'] ?></span>
                </li>
            <?php endforeach; ?>
            <?php if (empty($statusPorContagem)): ?>
                <li class="text-sm text-slate-500">Nenhum atendimento ativo.</li>
            <?php endif; ?>
        </ul>
    </div>
</section>

<section class="mt-8">
    <div class="rounded-lg bg-white p-6 shadow">
        <h2 class="mb-4 text-lg font-semibold text-slate-800">Municipios com mais interacoes</h2>
        <ol class="space-y-2 text-sm">
            <?php foreach ($topPrefeituras as $idx => $row): ?>
                <li class="flex justify-between">
                    <span><?= ($idx + 1) ?>. <?= esc($row['municipio']) ?></span>
                    <span class="font-semibold text-slate-700"><?= $row['total'] ?></span>
                </li>
            <?php endforeach; ?>
            <?php if (empty($topPrefeituras)): ?>
                <li class="text-sm text-slate-500">Cadastre um atendimento para ver este painel.</li>
            <?php endif; ?>
        </ol>
    </div>
</section>
<?php
require __DIR__ . '/partials/footer.php';

function fetch_scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value === false ? 0 : $value;
}

