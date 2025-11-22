<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$pdo = get_pdo();
$user = current_user();
$isAdmin = is_admin($user);

$representantes = $isAdmin ? $pdo->query("SELECT id, name, email FROM users WHERE role LIKE '%REPRESENTANTE%' AND active = 1 ORDER BY name")->fetchAll() : [];

if (is_post()) {
    if (!isset($_FILES['planilha']) || $_FILES['planilha']['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Selecione um arquivo CSV valido.');
        redirect('atendimentos_import.php');
    }

    $representantePadrao = $isAdmin ? (int)($_POST['representante_id'] ?? 0) : (int)$user['id'];
    if ($representantePadrao <= 0) {
        flash('error', 'Escolha um representante para associar os registros importados.');
        redirect('atendimentos_import.php');
    }

    $tmpPath = $_FILES['planilha']['tmp_name'];
    $handle = fopen($tmpPath, 'r');
    if (!$handle) {
        flash('error', 'Nao foi possivel ler o arquivo enviado.');
        redirect('atendimentos_import.php');
    }

    $delimiter = detect_delimiter($handle);
    if ($delimiter === null) {
        fclose($handle);
        flash('error', 'Nao foi possivel identificar o delimitador do arquivo. Use ponto e virgula ou virgula.');
        redirect('atendimentos_import.php');
    }

    $headers = fgetcsv($handle, 0, $delimiter);
    if (!$headers) {
        fclose($handle);
        flash('error', 'Arquivo sem cabecalho.');
        redirect('atendimentos_import.php');
    }

    $normalizedHeaders = array_map('normalize_header', $headers);
    $mapping = header_mapping();

    $insertSql = <<<SQL
INSERT INTO atendimentos (
    municipio_id,
    representante_id,
    periodo_relatorio,
    secretaria_escola,
    contato_principal,
    status_visita,
    observacoes,
    tipo_contato,
    data_contato,
    situacao_atual,
    valor_proposta,
    itens_projeto,
    data_envio,
    status_proposta,
    previsao_fechamento,
    dificuldades,
    acoes_futuras,
    observacoes_gerais,
    status_geral,
    responsavel_principal
) VALUES (
    :municipio_id,
    :representante_id,
    :periodo_relatorio,
    :secretaria_escola,
    :contato_principal,
    :status_visita,
    :observacoes,
    :tipo_contato,
    :data_contato,
    :situacao_atual,
    :valor_proposta,
    :itens_projeto,
    :data_envio,
    :status_proposta,
    :previsao_fechamento,
    :dificuldades,
    :acoes_futuras,
    :observacoes_gerais,
    'ATIVO',
    0
)
SQL;
    $insertStmt = $pdo->prepare($insertSql);

    $pdo->beginTransaction();

    $sucessos = 0;
    $erros = [];

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count(array_filter($row)) === 0) {
            continue;
        }

        $dados = [];
        foreach ($row as $index => $value) {
            $headerKey = $normalizedHeaders[$index] ?? null;
            if ($headerKey && isset($mapping[$headerKey])) {
                $dados[$mapping[$headerKey]] = trim((string)$value);
            }
        }

        $municipioNome = $dados['municipio'] ?? '';
        if ($municipioNome === '' && isset($dados['cidade_municipio'])) {
            $municipioNome = $dados['cidade_municipio'];
        }
        if ($municipioNome === '' && isset($dados['cidade'])) {
            $municipioNome = $dados['cidade'];
        }

        if ($municipioNome === '') {
            $erros[] = 'Linha ignorada: municipio vazio.';
            continue;
        }

        $municipioId = localizar_municipio($pdo, $municipioNome);
        if (!$municipioId) {
            $erros[] = 'Municipio nao encontrado: ' . $municipioNome;
            continue;
        }

        $representanteId = $representantePadrao;
        if ($isAdmin && !empty($dados['representante_nome'])) {
            $encontrado = localizar_representante($pdo, $dados['representante_nome']);
            if ($encontrado) {
                $representanteId = $encontrado;
            }
        }

        $params = [
            ':municipio_id' => $municipioId,
            ':representante_id' => $representanteId,
            ':periodo_relatorio' => to_nullable($dados['periodo_relatorio'] ?? null),
            ':secretaria_escola' => to_nullable($dados['secretaria_escola'] ?? null),
            ':contato_principal' => to_nullable($dados['contato_principal'] ?? null),
            ':status_visita' => to_nullable($dados['status_visita'] ?? null),
            ':observacoes' => to_nullable($dados['observacoes'] ?? null),
            ':tipo_contato' => to_nullable($dados['tipo_contato'] ?? null),
            ':data_contato' => parse_date($dados['data_contato'] ?? null),
            ':situacao_atual' => to_nullable($dados['situacao_atual'] ?? null),
            ':valor_proposta' => sanitize_decimal($dados['valor_proposta'] ?? null),
            ':itens_projeto' => to_nullable($dados['itens_projeto'] ?? null),
            ':data_envio' => parse_date($dados['data_envio'] ?? null),
            ':status_proposta' => to_nullable($dados['status_proposta'] ?? null),
            ':previsao_fechamento' => parse_date($dados['previsao_fechamento'] ?? null),
            ':dificuldades' => to_nullable($dados['dificuldades'] ?? null),
            ':acoes_futuras' => to_nullable($dados['acoes_futuras'] ?? null),
            ':observacoes_gerais' => to_nullable($dados['observacoes_gerais'] ?? null),
        ];

        try {
            $insertStmt->execute($params);
            $sucessos++;
        } catch (Throwable $e) {
            $erros[] = 'Falha ao inserir registro para municipio ' . $municipioNome . ': ' . $e->getMessage();
        }
    }

    fclose($handle);
    $pdo->commit();

    log_activity((int)$user['id'], 'atendimento_import', 'Importou ' . $sucessos . ' registros via CSV.');

    if (!empty($erros)) {
        flash('error', implode(' ', $erros));
    }
    flash('status', 'Importacao finalizada. Registros inseridos: ' . $sucessos . '.');
    redirect('atendimentos.php');
}

$pageTitle = 'Importar atendimentos';
require __DIR__ . '/partials/header.php';
?>
<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-800">Importar planilha de atendimentos</h1>
        <p class="mt-2 text-sm text-slate-600">Envie um arquivo CSV com cabecalho. Reconhecemos colunas como <strong>Municipio</strong>, <strong>Periodo do relatorio</strong>, <strong>Contato principal</strong>, <strong>Secretaria/Escola</strong>, <strong>Status da visita</strong>, <strong>Observacoes</strong>, <strong>Tipo de contato</strong>, <strong>Data contato</strong>, <strong>Situacao atual</strong>, <strong>Valor da proposta</strong>, <strong>Itens/Projeto</strong>, <strong>Data de envio</strong>, <strong>Status da proposta</strong>, <strong>Previsao de fechamento</strong>, <strong>Dificuldades</strong>, <strong>Acoes futuras</strong> e <strong>Observacoes gerais</strong>. A coluna opcional "Representante" permite delegar linhas (apenas admin).</p>
    </div>

    <form method="post" enctype="multipart/form-data" class="space-y-4 rounded-lg bg-white p-6 shadow">
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="planilha">Arquivo CSV</label>
            <input type="file" name="planilha" id="planilha" accept=".csv" required class="w-full text-sm">
            <p class="mt-1 text-xs text-slate-500">Salve sua planilha no formato CSV (ponto e virgula ou virgula).</p>
        </div>
        <?php if ($isAdmin): ?>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="representante_id">Associar registros ao representante</label>
                <select name="representante_id" id="representante_id" required class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Selecione</option>
                    <?php foreach ($representantes as $rep): ?>
                        <option value="<?= $rep['id'] ?>"><?= esc($rep['name']) ?> (<?= esc($rep['email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-xs text-slate-500">Caso a planilha tenha a coluna "Representante", tentaremos localizar automaticamente; se nao for possivel, usaremos o nome escolhido acima.</p>
            </div>
        <?php else: ?>
            <input type="hidden" name="representante_id" value="<?= (int)$user['id'] ?>">
        <?php endif; ?>
        <div class="flex gap-3">
            <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Importar</button>
            <a href="atendimentos.php" class="inline-flex items-center text-sm font-semibold text-slate-600 hover:text-slate-800">Cancelar</a>
        </div>
    </form>
</div>
<?php
require __DIR__ . '/partials/footer.php';

function detect_delimiter($handle): ?string
{
    $pos = ftell($handle);
    $line = fgets($handle);
    fseek($handle, $pos);
    if ($line === false) {
        return null;
    }
    if (substr_count($line, ';') >= substr_count($line, ',')) {
        return ';';
    }
    return ',';
}

function normalize_header(string $header): string
{
    $header = trim($header);
    $header = ltrim($header, "\xEF\xBB\xBF");
    $converted = @iconv('UTF-8', '', $header);
    if ($converted !== false) {
        $header = $converted;
    }
    $header = strtolower($header);
    $header = str_replace(['"', '\''], '', $header);
    $header = preg_replace('/[^a-z0-9]+/', ' ', $header);
    return trim($header);
}

function header_mapping(): array
{
    return [
        'periodo do relatorio' => 'periodo_relatorio',
        'periodo' => 'periodo_relatorio',
        'municipio' => 'municipio',
        'cidade municipio' => 'municipio',
        'cidade' => 'municipio',
        'cidade municipio municipio' => 'municipio',
        'cidade municipio ' => 'municipio',
        'cidade municipio municipio ' => 'municipio',
        'contato principal' => 'contato_principal',
        'secretaria escola visitada' => 'secretaria_escola',
        'secretaria escola' => 'secretaria_escola',
        'status da visita' => 'status_visita',
        'observacoes' => 'observacoes',
        'tipo de contato' => 'tipo_contato',
        'data contato' => 'data_contato',
        'situacao atual' => 'situacao_atual',
        'valor da proposta' => 'valor_proposta',
        'itens projeto' => 'itens_projeto',
        'data de envio' => 'data_envio',
        'status da proposta' => 'status_proposta',
        'previsao de fechamento' => 'previsao_fechamento',
        'dificuldades encontradas' => 'dificuldades',
        'acoes futuras planejadas' => 'acoes_futuras',
        'observacoes gerais' => 'observacoes_gerais',
        'representante' => 'representante_nome',
    ];
}

function localizar_municipio(PDO $pdo, string $nome): ?int
{
    $stmt = $pdo->prepare('SELECT id FROM municipios WHERE nome = :nome LIMIT 1');
    $stmt->execute([':nome' => $nome]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $stmt = $pdo->prepare('SELECT id FROM municipios WHERE nome LIKE :nome LIMIT 1');
    $stmt->execute([':nome' => '%' . $nome . '%']);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function localizar_representante(PDO $pdo, string $referencia): ?int
{
    $referencia = trim($referencia);
    if ($referencia === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE active = 1 AND (email = :ref OR name = :ref) LIMIT 1");
    $stmt->execute([':ref' => $referencia]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE active = 1 AND name LIKE :ref LIMIT 1");
    $stmt->execute([':ref' => '%' . $referencia . '%']);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

function to_nullable(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    return $value === '' ? null : $value;
}

function parse_date(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $value, $matches)) {
        return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value)) {
        return $value;
    }
    return null;
}



