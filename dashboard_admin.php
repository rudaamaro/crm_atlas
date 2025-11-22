<?php
require_once __DIR__ . '/bootstrap.php';
require_login();
$user = current_user();
if (!is_admin($user)) {
    redirect('dashboard_rep.php');
}

$pdo = get_pdo();

$representantesFiltro = $pdo->query("SELECT id, name FROM users WHERE role LIKE '%REPRESENTANTE%' AND active = 1 ORDER BY name")->fetchAll();
$repSelecionado = isset($_GET['representante_id']) ? (int)$_GET['representante_id'] : 0;

/**
 * Filtro simples (igual ao que funcionava):
 * quando há representante selecionado, filtra por a.representante_id = :rep.
 * (Depois podemos reintroduzir externos, mas primeiro garantimos estabilidade.)
 */
$wherePartes = ["status_geral <> 'ARQUIVADO'"];
$params = [];
if ($repSelecionado > 0) {
    // inclui atendimentos do representante e dos vendedores vinculados a ele
    $wherePartes[] = '(
        representante_id = :rep
        OR vendedor_id IN (SELECT id FROM users WHERE representante_id = :rep)
    )';
    $params[':rep'] = $repSelecionado;
}
$whereSQL = implode(' AND ', $wherePartes);

/** Cartões superiores **/
$totalAtendimentos   = (int)fetch_scalar($pdo, "SELECT COUNT(*) FROM atendimentos WHERE $whereSQL", $params);
$municipiosAtendidos = (int)fetch_scalar($pdo, "SELECT COUNT(DISTINCT municipio_id) FROM atendimentos WHERE $whereSQL", $params);
$totalUsuariosAtivos = (int)$pdo->query('SELECT COUNT(*) FROM users WHERE active = 1')->fetchColumn();
$valorTotalPropostas = (float)fetch_scalar($pdo, "SELECT COALESCE(SUM(valor_proposta), 0) FROM atendimentos WHERE $whereSQL AND valor_proposta IS NOT NULL", $params);

/** Situação atual **/
$situacaoStmt = $pdo->prepare("
    SELECT IFNULL(situacao_atual, 'Nao informado') AS situacao, COUNT(*) AS total
    FROM atendimentos
    WHERE $whereSQL
    GROUP BY situacao
    ORDER BY total DESC
");
$situacaoStmt->execute($params);
$situacaoPorContagem = $situacaoStmt->fetchAll();

/** Status da proposta **/
$statusStmt = $pdo->prepare("
    SELECT IFNULL(status_proposta, 'Nao informado') AS status_p, COUNT(*) AS total
    FROM atendimentos
    WHERE $whereSQL
    GROUP BY status_p
    ORDER BY total DESC
");
$statusStmt->execute($params);
$statusPorContagem = $statusStmt->fetchAll();

/** Top prefeituras **/
$topSql = "
    SELECT m.nome AS municipio, COUNT(*) AS total
    FROM atendimentos a
    INNER JOIN municipios m ON m.id = a.municipio_id
    WHERE $whereSQL
    GROUP BY a.municipio_id, m.nome
    ORDER BY total DESC
    LIMIT 5
";
$topStmt = $pdo->prepare($topSql);
$topStmt->execute($params);
$topPrefeituras = $topStmt->fetchAll();

/** Duplicidades (SQL que já funcionava) **/
if ($repSelecionado > 0) {
    $duplicidadesSql = <<<SQL
SELECT COUNT(*) FROM (
    SELECT a.municipio_id
    FROM atendimentos a
    INNER JOIN atendimentos outros
        ON outros.municipio_id = a.municipio_id
       AND outros.status_geral <> 'ARQUIVADO'
    WHERE a.status_geral <> 'ARQUIVADO'
      AND (
          a.representante_id = :rep_dup
          OR a.vendedor_id IN (SELECT id FROM users WHERE representante_id = :rep_dup)
      )
    GROUP BY a.municipio_id
    HAVING COUNT(DISTINCT outros.representante_id) >= 2
) dup
SQL;
    $duplicidades = (int)fetch_scalar($pdo, $duplicidadesSql, [':rep_dup' => $repSelecionado]);
} else {
    $duplicidadesSql = <<<SQL
SELECT COUNT(*) FROM (
    SELECT municipio_id
    FROM atendimentos
    WHERE status_geral <> 'ARQUIVADO'
    GROUP BY municipio_id
    HAVING COUNT(*) >= 2
) dup
SQL;
    $duplicidades = (int)fetch_scalar($pdo, $duplicidadesSql);
}

/**
 * Resumo por "dono" (internos + externos separados por nome),
 * mas respeitando o filtro simples quando houver representante selecionado.
 * Se houver filtro, limitamos por a.representante_id = :rep_r1 / :rep_r2.
 */
$repWhereA1 = null;
$repWhereA2 = null;
$paramsResumo = [];

if ($repSelecionado > 0) {
    $repWhereA1 = '(
        a.representante_id = :rep_r1
        OR a.vendedor_id IN (SELECT id FROM users WHERE representante_id = :rep_r1)
    )';
    $repWhereA2 = '(
        a.representante_id = :rep_r2
        OR a.vendedor_id IN (SELECT id FROM users WHERE representante_id = :rep_r2)
    )';
    $paramsResumo[':rep_r1'] = $repSelecionado;
    $paramsResumo[':rep_r2'] = $repSelecionado;
}

// SELECT-base 1
$baseSelect1 = "
    SELECT
        a.municipio_id,
        a.valor_proposta,
        a.representante_id,
        CASE
            WHEN a.representante_id IS NOT NULL THEN u.name
            WHEN LOCATE('|', a.representante_nome_externo) > 0
                THEN TRIM(SUBSTRING_INDEX(a.representante_nome_externo, '|', -1))
            WHEN LOCATE('(', a.representante_nome_externo) > 0
                THEN TRIM(BOTH ')' FROM SUBSTRING_INDEX(a.representante_nome_externo, '(', -1))
            ELSE a.representante_nome_externo
        END AS owner_name,
        CASE
            WHEN a.representante_id IS NOT NULL THEN CONCAT('U', a.representante_id)
            WHEN LOCATE('|', a.representante_nome_externo) > 0
                THEN CONCAT('E', TRIM(SUBSTRING_INDEX(a.representante_nome_externo, '|', -1)))
            WHEN LOCATE('(', a.representante_nome_externo) > 0
                THEN CONCAT('E', TRIM(BOTH ')' FROM SUBSTRING_INDEX(a.representante_nome_externo, '(', -1)))
            ELSE CONCAT('E', a.representante_nome_externo)
        END AS owner_key
    FROM atendimentos a
    LEFT JOIN users u ON u.id = a.representante_id
    WHERE a.status_geral <> 'ARQUIVADO'
    " . ($repWhereA1 ? " AND $repWhereA1" : "") . "
";

// SELECT-base 2 (para calcular municípios compartilhados por donos distintos)
$baseSelect2 = "
    SELECT
        a.municipio_id,
        a.valor_proposta,
        a.representante_id,
        CASE
            WHEN a.representante_id IS NOT NULL THEN u.name
            WHEN LOCATE('|', a.representante_nome_externo) > 0
                THEN TRIM(SUBSTRING_INDEX(a.representante_nome_externo, '|', -1))
            WHEN LOCATE('(', a.representante_nome_externo) > 0
                THEN TRIM(BOTH ')' FROM SUBSTRING_INDEX(a.representante_nome_externo, '(', -1))
            ELSE a.representante_nome_externo
        END AS owner_name,
        CASE
            WHEN a.representante_id IS NOT NULL THEN CONCAT('U', a.representante_id)
            WHEN LOCATE('|', a.representante_nome_externo) > 0
                THEN CONCAT('E', TRIM(SUBSTRING_INDEX(a.representante_nome_externo, '|', -1)))
            WHEN LOCATE('(', a.representante_nome_externo) > 0
                THEN CONCAT('E', TRIM(BOTH ')' FROM SUBSTRING_INDEX(a.representante_nome_externo, '(', -1)))
            ELSE CONCAT('E', a.representante_nome_externo)
        END AS owner_key
    FROM atendimentos a
    LEFT JOIN users u ON u.id = a.representante_id
    WHERE a.status_geral <> 'ARQUIVADO'
    " . ($repWhereA2 ? " AND $repWhereA2" : "") . "
";

// Agrega por owner_name e calcula compartilhados
$resumoSql = "
SELECT b.owner_name AS name,
       COUNT(*) AS total_atendimentos,
       COUNT(DISTINCT b.municipio_id) AS municipios,
       COUNT(DISTINCT CASE WHEN m.total_owners > 1 THEN b.municipio_id END) AS municipios_compartilhados,
       COALESCE(SUM(b.valor_proposta), 0) AS total_propostas
FROM ( $baseSelect1 ) AS b
LEFT JOIN (
    SELECT municipio_id, COUNT(DISTINCT owner_key) AS total_owners
    FROM ( $baseSelect2 ) AS bb
    GROUP BY municipio_id
) AS m ON m.municipio_id = b.municipio_id
GROUP BY b.owner_name
ORDER BY b.owner_name
";
$resumoStmt = $pdo->prepare($resumoSql);
$resumoStmt->execute($paramsResumo);
$resumoRepresentantes = $resumoStmt->fetchAll();

$pageTitle = 'Dashboard Admin';
require __DIR__ . '/partials/header.php';
?>
<div class="flex flex-col gap-6">
    <form method="get" class="flex items-center gap-2 text-sm text-slate-600">
        <label for="representante_id" class="font-semibold">Filtrar por representante:</label>
        <select name="representante_id" id="representante_id" class="rounded border border-slate-300 px-3 py-2 text-sm">
            <option value="0">Todos</option>
            <?php foreach ($representantesFiltro as $rep): ?>
                <option value="<?= $rep['id'] ?>" <?= $repSelecionado === (int)$rep['id'] ? 'selected' : '' ?>><?= esc($rep['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="rounded border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">Aplicar</button>
        <?php if ($repSelecionado > 0): ?>
            <a href="dashboard_admin.php" class="text-xs font-semibold text-slate-500 hover:text-slate-700">Limpar filtro</a>
        <?php endif; ?>
    </form>

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
            <div class="text-sm text-slate-500">Usuarios ativos</div>
            <div class="text-2xl font-semibold text-slate-800"><?= $totalUsuariosAtivos ?></div>
        </article>
        <article class="rounded-lg bg-white p-4 shadow">
            <div class="text-sm text-slate-500">Valor total das propostas</div>
            <div class="text-2xl font-semibold text-slate-800"><?= esc(format_currency($valorTotalPropostas)) ?></div>
        </article>
    </section>

    <section class="mt-4 grid gap-6 lg:grid-cols-2">
        <div class="rounded-lg bg-white p-6 shadow">
            <h2 class="mb-4 text-lg font-semibold text-slate-800">Situacao atual (ativos)</h2>
            <ul class="space-y-2">
                <?php foreach ($situacaoPorContagem as $row): ?>
                    <li class="flex justify-between text-sm">
                        <span><?= esc($row['situacao']) ?></span>
                        <span class="font-semibold text-slate-700"><?= $row['total'] ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($situacaoPorContagem)): ?>
                    <li class="text-sm text-slate-500">Nenhum dado disponivel.</li>
                <?php endif; ?>
            </ul>
        </div>
        <div class="rounded-lg bg-white p-6 shadow">
            <h2 class="mb-4 text-lg font-semibold text-slate-800">Status da proposta (ativos)</h2>
            <ul class="space-y-2">
                <?php foreach ($statusPorContagem as $row): ?>
                    <li class="flex justify-between text-sm">
                        <span><?= esc($row['status_p']) ?></span>
                        <span class="font-semibold text-slate-700"><?= $row['total'] ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($statusPorContagem)): ?>
                    <li class="text-sm text-slate-500">Nenhum dado disponivel.</li>
                <?php endif; ?>
            </ul>
        </div>
    </section>

    <section class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-lg bg-white p-6 shadow">
            <h2 class="mb-4 text-lg font-semibold text-slate-800">Top prefeituras por interacoes</h2>
            <ol class="space-y-2 text-sm">
                <?php foreach ($topPrefeituras as $idx => $row): ?>
                    <li class="flex justify-between">
                        <span><?= ($idx + 1) ?>. <?= esc($row['municipio']) ?></span>
                        <span class="font-semibold text-slate-700"><?= $row['total'] ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (empty($topPrefeituras)): ?>
                    <li class="text-slate-500">Nenhum atendimento registrado ainda.</li>
                <?php endif; ?>
            </ol>
        </div>
        <div class="rounded-lg bg-white p-6 shadow">
            <h2 class="mb-4 text-lg font-semibold text-slate-800">Duplicidades</h2>
            <p class="text-sm text-slate-500">Municipios com mais de um representante ativo.</p>
            <div class="mt-4 flex items-end justify-between">
                <div>
                    <div class="text-3xl font-semibold text-rose-600"><?= $duplicidades ?></div>
                    <div class="text-xs uppercase tracking-wide text-slate-500">municipios</div>
                </div>
                <!-- ALTERAÇÃO AQUI: mantém o filtro do representante ao ir para duplicidades -->
                <a href="duplicidades.php<?= $repSelecionado > 0 ? '?representante_id=' . (int)$repSelecionado : '' ?>" class="rounded bg-rose-100 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-200">Ver detalhes</a>
            </div>
        </div>
    </section>

    <section class="mt-8">
        <div class="rounded-lg bg-white p-6 shadow">
            <h2 class="mb-4 text-lg font-semibold text-slate-800">Resumo por representante (ativos)</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th class="px-3 py-2">Representante</th>
                            <th class="px-3 py-2">Atendimentos</th>
                            <th class="px-3 py-2">Municipios</th>
                            <th class="px-3 py-2">Compartilhados</th>
                            <th class="px-3 py-2 text-right">Propostas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        <?php foreach ($resumoRepresentantes as $resumo): ?>
                            <tr>
                                <td class="px-3 py-2 font-medium text-slate-700"><?= esc($resumo['name']) ?></td>
                                <td class="px-3 py-2 text-slate-600"><?= (int)$resumo['total_atendimentos'] ?></td>
                                <td class="px-3 py-2 text-slate-600"><?= (int)$resumo['municipios'] ?></td>
                                <td class="px-3 py-2 text-slate-600"><?= (int)$resumo['municipios_compartilhados'] ?></td>
                                <td class="px-3 py-2 text-right text-slate-600"><?= esc(format_currency($resumo['total_propostas'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($resumoRepresentantes)): ?>
                            <tr>
                                <td colspan="5" class="px-3 py-4 text-center text-sm text-slate-500">Nenhum representante com atendimentos ativos no filtro atual.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
<?php
require __DIR__ . '/partials/footer.php';

function fetch_scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value === false ? 0 : $value;
}
