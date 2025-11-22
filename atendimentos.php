<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$pdo = get_pdo();
$user = current_user();
$isAdmin = is_admin($user);
$isRep = is_representante($user);
$isVendor = is_vendedor($user);
$vendedoresDisponiveis = [];

if ($isAdmin) {
    $vendedoresDisponiveis = $pdo->query("SELECT id, name, representante_id FROM users WHERE active = 1 AND role LIKE '%VENDEDOR%' ORDER BY name")->fetchAll();
} elseif ($isRep) {
    $stmtVend = $pdo->prepare("SELECT id, name, representante_id FROM users WHERE active = 1 AND role LIKE '%VENDEDOR%' AND representante_id = :rep ORDER BY name");
    $stmtVend->execute([':rep' => $user['id']]);
    $vendedoresDisponiveis = $stmtVend->fetchAll();
}

$vendedorIdsPermitidos = array_map(static function ($vend) {
    return (int)$vend['id'];
}, $vendedoresDisponiveis);

$statusLabels = [
    'ATIVO' => 'Ativo',
    'CONCLUIDO' => 'Concluido',
    'ARQUIVADO' => 'Arquivado',
];

$statusOptions = [
    '' => 'Ativos e concluidos',
    'ATIVO' => 'Somente ativos',
    'CONCLUIDO' => 'Somente concluidos',
    'ARQUIVADO' => 'Somente arquivados',
    'TODOS' => 'Todos',
];

$filters = [
    'municipio_id' => isset($_GET['municipio_id']) && $_GET['municipio_id'] !== '' ? (int)$_GET['municipio_id'] : null,
    'representante_id' => $isAdmin && isset($_GET['representante_id']) && $_GET['representante_id'] !== '' ? (int)$_GET['representante_id'] : null,
    'vendedor_id' => isset($_GET['vendedor_id']) && $_GET['vendedor_id'] !== '' ? (int)$_GET['vendedor_id'] : null,
    'situacao_atual' => trim($_GET['situacao_atual'] ?? ''),
    'status_proposta' => trim($_GET['status_proposta'] ?? ''),
    'data_inicio' => trim($_GET['data_inicio'] ?? ''),
    'data_fim' => trim($_GET['data_fim'] ?? ''),
    'com_valor' => isset($_GET['com_valor']) && $_GET['com_valor'] === '1',
    'com_previsao' => isset($_GET['com_previsao']) && $_GET['com_previsao'] === '1',
    'status_geral' => strtoupper(trim($_GET['status_geral'] ?? '')),
];

if (!$isAdmin) {
    if ($isVendor) {
        $filters['vendedor_id'] = (int)$user['id'];
        $filters['representante_id'] = null;
    } else {
        $filters['representante_id'] = (int)$user['id'];
        if ($filters['vendedor_id'] && !in_array($filters['vendedor_id'], $vendedorIdsPermitidos, true)) {
            $filters['vendedor_id'] = null;
        }
    }
}

list($whereSql, $params) = build_where_clause($filters);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    export_csv($pdo, $whereSql, $params);
    exit;
}

// Exportar PDF com os MESMOS filtros da tela
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    // Gera o mesmo WHERE que jÃ¡ estÃ¡ em $whereSql/$params e passa para a rota dedicada
    // Aqui eu redireciono para o endpoint limpo, que reconstrÃ³i o WHERE (admin + externos)
    $qs = $_GET;
    // abre em nova guia/aba
    header('Location: export/pdf_atendimentos.php?' . http_build_query($qs));
    exit;
}


$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = ITEMS_PER_PAGE;
$offset = ($page - 1) * $perPage;

$countSql = "SELECT COUNT(*) FROM atendimentos a INNER JOIN municipios m ON m.id = a.municipio_id LEFT JOIN users u ON u.id = a.representante_id LEFT JOIN users v ON v.id = a.vendedor_id {$whereSql}";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$dataSql = <<<SQL
SELECT a.*, m.nome AS municipio_nome, u.name AS representante_usuario_nome, v.name AS vendedor_nome
FROM atendimentos a
INNER JOIN municipios m ON m.id = a.municipio_id
LEFT JOIN users u ON u.id = a.representante_id
LEFT JOIN users v ON v.id = a.vendedor_id
{$whereSql}
ORDER BY a.updated_at DESC
LIMIT :limit OFFSET :offset
SQL;

$dataStmt = $pdo->prepare($dataSql);
foreach ($params as $key => $value) {
    $dataStmt->bindValue($key, $value);
}
$dataStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$dataStmt->execute();
$atendimentos = $dataStmt->fetchAll();
foreach ($atendimentos as &$registro) {
    $registro['representante_nome'] = format_representante_nome($registro['representante_nome_externo'] ?? null, $registro['representante_usuario_nome'] ?? null);
}
unset($registro);

$municipios = $pdo->query('SELECT id, nome FROM municipios ORDER BY nome')->fetchAll();
$representantes = $isAdmin ? $pdo->query("SELECT id, name FROM users WHERE role LIKE '%REPRESENTANTE%' AND active = 1 ORDER BY name")->fetchAll() : [];
$distinctWhereBase = trim($whereSql);
if ($distinctWhereBase === '') {
    $distinctWhereBase = 'WHERE 1=1';
}

$situacaoSql = "
    SELECT DISTINCT situacao_atual
    FROM atendimentos a
    {$distinctWhereBase}
      AND situacao_atual IS NOT NULL
      AND situacao_atual <> ''
    ORDER BY situacao_atual
";
$statusSql = "
    SELECT DISTINCT status_proposta
    FROM atendimentos a
    {$distinctWhereBase}
      AND status_proposta IS NOT NULL
      AND status_proposta <> ''
    ORDER BY status_proposta
";

$distinctSituacoesStmt = $pdo->prepare($situacaoSql);
$distinctSituacoesStmt->execute($params);
$distinctSituacoes = $distinctSituacoesStmt->fetchAll(PDO::FETCH_COLUMN);

$distinctStatusStmt = $pdo->prepare($statusSql);
$distinctStatusStmt->execute($params);
$distinctStatus = $distinctStatusStmt->fetchAll(PDO::FETCH_COLUMN);


$summaryWhereParts = ["status_geral <> 'ARQUIVADO'"];
$summaryParams = [];

if (!empty($filters['representante_id'])) {
    $summaryWhereParts[] = 'representante_id = :rep_filter';
    $summaryParams[':rep_filter'] = (int)$filters['representante_id'];
}

if (!empty($filters['vendedor_id'])) {
    $summaryWhereParts[] = 'vendedor_id = :vend_filter';
    $summaryParams[':vend_filter'] = (int)$filters['vendedor_id'];
}

$summaryWhere = implode(' AND ', $summaryWhereParts);

$dupStmt = $pdo->prepare("SELECT municipio_id FROM atendimentos WHERE $summaryWhere GROUP BY municipio_id HAVING COUNT(*) >= 2");
$dupStmt->execute($summaryParams);
$duplicadosMunicipio = $dupStmt->fetchAll(PDO::FETCH_COLUMN);
$duplicadosSet = array_flip($duplicadosMunicipio);

$atendimentosAtivos = (int)fetch_scalar($pdo, "SELECT COUNT(*) FROM atendimentos WHERE $summaryWhere", $summaryParams);
$municipiosAtendidos = (int)fetch_scalar($pdo, "SELECT COUNT(DISTINCT municipio_id) FROM atendimentos WHERE $summaryWhere", $summaryParams);
$valorTotalPropostas = (float)fetch_scalar($pdo, "SELECT COALESCE(SUM(valor_proposta), 0) FROM atendimentos WHERE $summaryWhere AND valor_proposta IS NOT NULL", $summaryParams);
$municipiosDuplicados = count($duplicadosMunicipio);

$resumoRepresentantes = [];

/** LABEL visÃ­vel entre os filtros e a tabela */
$filtroRepLabel = 'TODOS';
if ($isAdmin) {
    if (!empty($filters['representante_id'])) {
        foreach ($representantes as $repRow) {
            if ((int)$repRow['id'] === (int)$filters['representante_id']) {
                $filtroRepLabel = $repRow['name'];
                break;
            }
        }
    } else {
        $filtroRepLabel = 'TODOS';
    }
} else {
    $filtroRepLabel = $user['name'] ?? $user['email'];
}

$pageTitle = 'Atendimentos';
require __DIR__ . '/partials/header.php';
?>
<div class="flex flex-col gap-6">
    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-center">
        <h1 class="text-2xl font-semibold text-slate-800">Atendimentos</h1>
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-xs md:text-sm text-slate-500">Logado como: <strong class="text-slate-800"><?= esc($user['name'] ?? $user['email']) ?></strong></span>
            <a href="atendimento_form.php" class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Novo atendimento</a>
        </div>
    </div>

    <section class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg bg-white p-4 shadow">
            <div class="text-xs font-semibold uppercase text-slate-500">Atendimentos ativos</div>
            <div class="text-2xl font-semibold text-slate-800"><?= $atendimentosAtivos ?></div>
        </div>
        <div class="rounded-lg bg-white p-4 shadow">
            <div class="text-xs font-semibold uppercase text-slate-500">Municipios atendidos</div>
            <div class="text-2xl font-semibold text-slate-800"><?= $municipiosAtendidos ?></div>
        </div>
        <div class="rounded-lg bg-white p-4 shadow">
            <div class="text-xs font-semibold uppercase text-slate-500">Municipios duplicados</div>
            <div class="text-2xl font-semibold text-rose-600"><?= $municipiosDuplicados ?></div>
        </div>
        <div class="rounded-lg bg-white p-4 shadow">
            <div class="text-xs font-semibold uppercase text-slate-500">Valor total das propostas</div>
            <div class="text-2xl font-semibold text-slate-800"><?= esc(format_currency($valorTotalPropostas)) ?></div>
        </div>
    </section>

    <?php if ($isAdmin && !empty($resumoRepresentantes)): ?>
        <section class="overflow-x-auto rounded-lg bg-white p-4 shadow">
            <h2 class="mb-3 text-sm font-semibold uppercase text-slate-500">Resumo por representante (ativos)</h2>
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                    <tr>
                        <th class="px-3 py-2">Representante</th>
                        <th class="px-3 py-2">Atendimentos</th>
                        <th class="px-3 py-2">Munic?pios</th>
                        <th class="px-3 py-2">Compartilhados</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php foreach ($resumoRepresentantes as $resumo): ?>
                        <tr>
                            <td class="px-3 py-2 font-medium text-slate-700"><?= esc($resumo['name']) ?></td>
                            <td class="px-3 py-2 text-slate-600"><?= (int)$resumo['total_atendimentos'] ?></td>
                            <td class="px-3 py-2 text-slate-600"><?= (int)$resumo['municipios'] ?></td>
                            <td class="px-3 py-2 text-slate-600"><?= (int)$resumo['municipios_compartilhados'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

    <form method="get" class="grid gap-4 rounded-lg bg-white p-4 shadow md:grid-cols-2 lg:grid-cols-4">
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="municipio_id">Municipio</label>
            <select class="w-full rounded border border-slate-200 px-3 py-2 text-sm" name="municipio_id" id="municipio_id">
                <option value="">Todos</option>
                <?php foreach ($municipios as $municipio): ?>
                    <option value="<?= $municipio['id'] ?>" <?= $filters['municipio_id'] === (int)$municipio['id'] ? 'selected' : '' ?>><?= esc($municipio['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($isAdmin): ?>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="representante_id">Representante</label>
                <select class="w-full rounded border border-slate-200 px-3 py-2 text-sm" name="representante_id" id="representante_id">
                    <option value="">Todos</option>
                    <?php foreach ($representantes as $rep): ?>
                        <option value="<?= $rep['id'] ?>" <?= $filters['representante_id'] === (int)$rep['id'] ? 'selected' : '' ?>><?= esc($rep['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if ($isAdmin || $isRep): ?>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="vendedor_id">Vendedor</label>
                <select class="w-full rounded border border-slate-200 px-3 py-2 text-sm" name="vendedor_id" id="vendedor_id">
                    <option value="">Todos</option>
                    <?php foreach ($vendedoresDisponiveis as $vend): ?>
                        <option value="<?= $vend['id'] ?>" <?= $filters['vendedor_id'] === (int)$vend['id'] ? 'selected' : '' ?>>
                            <?= esc($vend['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="situacao_atual">Situacao atual</label>
            <select class="w-full rounded border border-slate-200 px-3 py-2 text-sm" name="situacao_atual" id="situacao_atual">
                <option value="">Todas</option>
                <?php foreach ($distinctSituacoes as $situacao): ?>
                    <option value="<?= esc($situacao) ?>" <?= $filters['situacao_atual'] === $situacao ? 'selected' : '' ?>><?= esc($situacao) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="status_proposta">Status da proposta</label>
            <select class="w-full rounded border border-slate-200 px-3 py-2 text-sm" name="status_proposta" id="status_proposta">
                <option value="">Todos</option>
                <?php foreach ($distinctStatus as $status): ?>
                    <option value="<?= esc($status) ?>" <?= $filters['status_proposta'] === $status ? 'selected' : '' ?>><?= esc($status) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="status_geral">Status do registro</label>
            <select class="w-full rounded border border-slate-200 px-3 py-2 text-sm" name="status_geral" id="status_geral">
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $filters['status_geral'] === $value ? 'selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="data_inicio">Data inicial</label>
            <input type="date" class="w-full rounded border border-slate-200 px-3 py-2 text-sm" name="data_inicio" id="data_inicio" value="<?= esc($filters['data_inicio']) ?>">
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="data_fim">Data final</label>
            <input type="date" class="w-full rounded border border-slate-200 px-3 py-2 text-sm" name="data_fim" id="data_fim" value="<?= esc($filters['data_fim']) ?>">
        </div>
        <div class="flex items-center gap-2 pt-6">
            <input type="checkbox" id="com_valor" name="com_valor" value="1" <?= $filters['com_valor'] ? 'checked' : '' ?> class="h-4 w-4 text-slate-900">
            <label for="com_valor" class="text-sm text-slate-600">Somente com valor de proposta</label>
        </div>
        <div class="flex items-center gap-2 pt-6">
            <input type="checkbox" id="com_previsao" name="com_previsao" value="1" <?= $filters['com_previsao'] ? 'checked' : '' ?> class="h-4 w-4 text-slate-900">
            <label for="com_previsao" class="text-sm text-slate-600">Somente com previsao de fechamento</label>
        </div>
        <div class="md:col-span-2 lg:col-span-4 flex gap-2">
            <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Filtrar</button>
            <a href="atendimentos.php" class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-200">Limpar</a>
        
            <?php
            // Monta a URL do PDF reaproveitando os filtros atuais
            $pdfQuery = $_GET ?? [];
            $pdfQuery['export'] = 'pdf';
            $pdfUrl = 'atendimentos.php?' . http_build_query($pdfQuery);
            ?>
            <a href="<?= esc($pdfUrl) ?>" target="_blank"
               class="rounded border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-200"
               title="Exportar PDF com os filtros aplicados">ð Exportar PDF</a>
        </div>

    </form>

            <!-- Linha com o nome do filtro selecionado (mÃ©dio) -->
    <div class="my-2 text-center text-base md:text-lg font-semibold uppercase tracking-wide text-slate-700">
         <?= esc($filtroRepLabel) ?> 
    </div>


    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 bg-white text-sm shadow">
            <thead class="bg-slate-50">
                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                    <th class="px-4 py-3">Prefeitura</th>
                    <th class="px-4 py-3">Representante</th>
                    <th class="px-4 py-3">Vendedor</th>
                    <th class="px-4 py-3">Situacao</th>
                    <th class="px-4 py-3">Status da proposta</th>
                    <th class="px-4 py-3">Status registro</th>
                    <th class="px-4 py-3 text-right">Valor</th>
                    <th class="px-4 py-3">Ultima atualizacao</th>
                    <th class="px-4 py-3 text-center">Acoes</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php foreach ($atendimentos as $atendimento): ?>
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3">
                            <div class="font-semibold text-slate-800 flex items-center gap-2">
                                <?= esc($atendimento['municipio_nome']) ?>
                                <?php if (isset($duplicadosSet[$atendimento['municipio_id']])): ?>
                                    <span class="rounded-full bg-rose-100 px-2 py-0.5 text-xs font-semibold uppercase text-rose-600">Duplicado</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            <?= esc($atendimento['representante_nome']) ?>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            <?= esc($atendimento['vendedor_nome'] ?? '--') ?>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            <?= esc($atendimento['situacao_atual'] ?? '--') ?>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            <?= esc($atendimento['status_proposta'] ?? '--') ?>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            <?php $label = $statusLabels[$atendimento['status_geral']] ?? $atendimento['status_geral']; ?>
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700"><?= esc($label) ?></span>
                        </td>
                        <td class="px-4 py-3 text-right font-semibold text-slate-700">
                            <?= esc(format_currency($atendimento['valor_proposta'])) ?>
                        </td>
                        <td class="px-4 py-3 text-slate-500">
                            <?= esc(format_date($atendimento['updated_at'], 'd/m/Y H:i')) ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="atendimento_form.php?id=<?= $atendimento['id'] ?>" class="text-sm font-semibold text-slate-700 hover:text-slate-900">Editar</a>
                            <?php if ($isAdmin || (int)$atendimento['representante_id'] === (int)$user['id'] || ($isVendor && (int)$atendimento['vendedor_id'] === (int)$user['id'])): ?>
                                <span class="mx-1 text-slate-300">|</span>
                                <a href="atendimento_delete.php?id=<?= $atendimento['id'] ?>" class="text-sm font-semibold text-rose-600 hover:text-rose-700" onclick="return confirm('Excluir atendimento? Esta acao nao pode ser desfeita.');">Excluir</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($atendimentos)): ?>
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-sm text-slate-500">Nenhum atendimento encontrado para os filtros informados.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="flex items-center justify-between text-sm text-slate-600">
        <div>Pagina <?= $page ?> de <?= $totalPages ?> - <?= $total ?> registro(s) no total</div>
        <div class="flex gap-2">
            <?php if ($page > 1): ?>
                <a class="rounded border border-slate-300 px-3 py-1 hover:bg-slate-100" href="<?= pagination_url($page - 1) ?>">Anterior</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a class="rounded border border-slate-300 px-3 py-1 hover:bg-slate-100" href="<?= pagination_url($page + 1) ?>">Proxima</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
require __DIR__ . '/partials/footer.php';

function build_where_clause(array $filters): array
{
    $where = [];
    $params = [];

    if ($filters['municipio_id']) {
        $where[] = 'a.municipio_id = :municipio_id';
        $params[':municipio_id'] = $filters['municipio_id'];
    }

    if (!empty($filters['vendedor_id'])) {
        $where[] = 'a.vendedor_id = :vendedor_id';
        $params[':vendedor_id'] = $filters['vendedor_id'];
    }

    // --- PATCH: representante interno + externos criados por ele (nome completo OU primeiro nome)
    if (!empty($filters['representante_id'])) {
        $where[] =
            '('
          . ' a.representante_id = :representante_id'
          . ' OR ( a.representante_id IS NULL AND ('
          . '        a.representante_nome_externo LIKE CONCAT(('
          . '            SELECT REPLACE(REPLACE(ux.name, "%", "\\\\%"), "_", "\\\\_")'
          . '              FROM users ux WHERE ux.id = :representante_id2'
          . '        ), "|%") ESCAPE "\\\\"'
          . '      OR a.representante_nome_externo LIKE CONCAT(('
          . '            SELECT REPLACE(REPLACE(SUBSTRING_INDEX(ux.name, " ", 1), "%", "\\\\%"), "_", "\\\\_")'
          . '              FROM users ux WHERE ux.id = :representante_id3'
          . '        ), "|%") ESCAPE "\\\\"'
          . '      OR a.representante_nome_externo LIKE CONCAT(('
          . '            SELECT REPLACE(REPLACE(ux.name, "%", "\\\\%"), "_", "\\\\_")'
          . '              FROM users ux WHERE ux.id = :representante_id4'
          . '        ), " (%") ESCAPE "\\\\"'
          . '      OR a.representante_nome_externo LIKE CONCAT(('
          . '            SELECT REPLACE(REPLACE(SUBSTRING_INDEX(ux.name, " ", 1), "%", "\\\\%"), "_", "\\\\_")'
          . '              FROM users ux WHERE ux.id = :representante_id5'
          . '        ), " (%") ESCAPE "\\\\"'
          . ' ) )'
          . ')';

        $rid = (int)$filters['representante_id'];
        $params[':representante_id']  = $rid;
        $params[':representante_id2'] = $rid;
        $params[':representante_id3'] = $rid;
        $params[':representante_id4'] = $rid;
        $params[':representante_id5'] = $rid;
    }
    // --- FIM DO PATCH ---

    if ($filters['situacao_atual'] !== '') {
        $where[] = 'a.situacao_atual = :situacao_atual';
        $params[':situacao_atual'] = $filters['situacao_atual'];
    }
    if ($filters['status_proposta'] !== '') {
        $where[] = 'a.status_proposta = :status_proposta';
        $params[':status_proposta'] = $filters['status_proposta'];
    }
    if ($filters['data_inicio'] !== '') {
        $where[] = 'a.data_contato >= :data_inicio';
        $params[':data_inicio'] = $filters['data_inicio'];
    }
    if ($filters['data_fim'] !== '') {
        $where[] = 'a.data_contato <= :data_fim';
        $params[':data_fim'] = $filters['data_fim'];
    }
    if (!empty($filters['com_valor'])) {
        $where[] = 'a.valor_proposta IS NOT NULL AND a.valor_proposta > 0';
    }
    if (!empty($filters['com_previsao'])) {
        $where[] = 'a.previsao_fechamento IS NOT NULL';
    }

    $status = $filters['status_geral'];
    if (in_array($status, ['ATIVO', 'CONCLUIDO', 'ARQUIVADO'], true)) {
        $where[] = 'a.status_geral = :status_geral';
        $params[':status_geral'] = $status;
    } elseif ($status !== 'TODOS') {
        $where[] = "a.status_geral <> 'ARQUIVADO'";
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    return [$whereSql, $params];
}

function export_csv(PDO $pdo, string $whereSql, array $params): void
{
    $sql = <<<SQL
SELECT m.nome AS municipio,
       u.name AS representante_usuario,
       a.representante_nome_externo,
       a.situacao_atual,
       a.status_proposta,
       a.valor_proposta,
       a.data_contato,
       a.tipo_contato,
       a.observacoes,
       a.previsao_fechamento,
       a.status_geral,
       a.updated_at
FROM atendimentos a
INNER JOIN municipios m ON m.id = a.municipio_id
LEFT JOIN users u ON u.id = a.representante_id
{$whereSql}
ORDER BY a.updated_at DESC
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="atendimentos.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Prefeitura', 'Representante', 'Situacao', 'Status proposta', 'Valor', 'Data contato', 'Tipo contato', 'Observacoes', 'Previsao fechamento', 'Status registro', 'Ultima atualizacao']);

    foreach ($rows as $row) {
        $nomeRepresentante = format_representante_nome($row['representante_nome_externo'] ?? null, $row['representante_usuario'] ?? null);
        fputcsv($output, [
            $row['municipio'],
            $nomeRepresentante,
            $row['situacao_atual'],
            $row['status_proposta'],
            $row['valor_proposta'],
            $row['data_contato'],
            $row['tipo_contato'],
            $row['observacoes'],
            $row['previsao_fechamento'],
            $row['status_geral'],
            $row['updated_at'],
        ]);
    }

    fclose($output);
}
function build_export_url(): string
{
    $query = $_GET;
    $query['export'] = 'csv';
    return 'atendimentos.php?' . http_build_query($query);
}

function pagination_url(int $page): string
{
    $query = $_GET;
    $query['page'] = $page;
    return 'atendimentos.php?' . http_build_query($query);
}

function fetch_scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value === false ? 0 : $value;
}
