<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
$user = current_user();
if (is_admin($user)) {
    redirect('dashboard_admin.php');
}

$pdo = get_pdo();
$userId = (int)$user['id'];

$totalAtendimentos = (int)$pdo->query("SELECT COUNT(*) FROM atendimentos WHERE representante_id = {$userId} AND status_geral <> 'ARQUIVADO'")->fetchColumn();
$municipiosAtendidos = (int)$pdo->query("SELECT COUNT(DISTINCT municipio_id) FROM atendimentos WHERE representante_id = {$userId} AND status_geral <> 'ARQUIVADO'")->fetchColumn();
$valorTotal = (float)$pdo->query("SELECT COALESCE(SUM(valor_proposta), 0) FROM atendimentos WHERE representante_id = {$userId} AND status_geral <> 'ARQUIVADO' AND valor_proposta IS NOT NULL")->fetchColumn();
$municipiosCompartilhados = (int)$pdo->query(<<<SQL
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
    WHERE a.representante_id = {$userId} AND a.status_geral <> 'ARQUIVADO'
    GROUP BY a.municipio_id
) compartilhados
SQL)->fetchColumn();

$situacaoPorContagem = $pdo->query("SELECT IFNULL(situacao_atual, 'Nao informado') AS situacao, COUNT(*) AS total FROM atendimentos WHERE representante_id = {$userId} AND status_geral <> 'ARQUIVADO' GROUP BY situacao ORDER BY total DESC")->fetchAll();
$statusPorContagem = $pdo->query("SELECT IFNULL(status_proposta, 'Nao informado') AS status_p, COUNT(*) AS total FROM atendimentos WHERE representante_id = {$userId} AND status_geral <> 'ARQUIVADO' GROUP BY status_p ORDER BY total DESC")->fetchAll();
$topPrefeituras = $pdo->query("SELECT m.nome AS municipio, COUNT(*) AS total FROM atendimentos a INNER JOIN municipios m ON m.id = a.municipio_id WHERE a.representante_id = {$userId} AND a.status_geral <> 'ARQUIVADO' GROUP BY a.municipio_id, m.nome ORDER BY total DESC LIMIT 5")->fetchAll();

$pageTitle = 'Dashboard Pessoal';
require __DIR__ . '/partials/header.php';
?>
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

