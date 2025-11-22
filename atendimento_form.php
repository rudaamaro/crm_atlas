<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$pdo = get_pdo();
$user = current_user();
$isAdmin = is_admin($user);
$isRep = is_representante($user);
$isVendor = is_vendedor($user);

$statusOptions = [
    'ATIVO' => 'Ativo',
    'CONCLUIDO' => 'Concluido',
    'ARQUIVADO' => 'Arquivado',
];

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

if (is_post()) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $isEdit = $id > 0;

    $municipioId = (int)($_POST['municipio_id'] ?? 0);

    $representanteTipo = $isAdmin ? ($_POST['representante_tipo'] ?? 'interno') : 'interno';
    $representanteTipo = $representanteTipo === 'externo' ? 'externo' : 'interno';
    $representanteNomeExterno = $isAdmin ? trim($_POST['representante_externo'] ?? '') : '';
    if (!$isAdmin) {
        $representanteNomeExterno = '';
    }
    if ($representanteNomeExterno !== '') {
        if (function_exists('mb_substr')) {
            $representanteNomeExterno = mb_substr($representanteNomeExterno, 0, 120);
        } else {
            $representanteNomeExterno = substr($representanteNomeExterno, 0, 120);
        }
    }

    $vendedorId = null;

    if ($isAdmin && $representanteTipo === 'externo') {
        $representanteId = null;
    } else {
        if ($isVendor) {
            $representanteId = (int)($user['representante_id'] ?? 0);
            $vendedorId = (int)$user['id'];
        } else {
            $representanteId = $isAdmin ? (int)($_POST['representante_id'] ?? 0) : (int)$user['id'];
        }
    }

    if ($isAdmin) {
        $_POST['representante_tipo'] = $representanteTipo;
        $_POST['representante_externo'] = $representanteNomeExterno;
    }

    $statusGeral = strtoupper(trim($_POST['status_geral'] ?? 'ATIVO'));
    if (!array_key_exists($statusGeral, $statusOptions)) {
        $statusGeral = 'ATIVO';
    }
    if (!$isAdmin && $statusGeral === 'ARQUIVADO') {
        $statusGeral = 'CONCLUIDO';
    }

    $_POST['status_geral'] = $statusGeral;

    $formValues = [
        'periodo_relatorio' => trim($_POST['periodo_relatorio'] ?? ''),
        'secretaria_escola' => trim($_POST['secretaria_escola'] ?? ''),
        'contato_principal' => trim($_POST['contato_principal'] ?? ''),
        'status_visita' => trim($_POST['status_visita'] ?? ''),
        'observacoes' => trim($_POST['observacoes'] ?? ''),
        'tipo_contato' => trim($_POST['tipo_contato'] ?? ''),
        'data_contato' => trim($_POST['data_contato'] ?? ''),
        'situacao_atual' => trim($_POST['situacao_atual'] ?? ''),
        'valor_proposta' => money_from_input($_POST['valor_proposta'] ?? ''), // <-- apenas esta linha foi alterada
        'itens_projeto' => trim($_POST['itens_projeto'] ?? ''),
        'data_envio' => trim($_POST['data_envio'] ?? ''),
        'status_proposta' => trim($_POST['status_proposta'] ?? ''),
        'previsao_fechamento' => trim($_POST['previsao_fechamento'] ?? ''),
        'dificuldades' => trim($_POST['dificuldades'] ?? ''),
        'acoes_futuras' => trim($_POST['acoes_futuras'] ?? ''),
        'observacoes_gerais' => trim($_POST['observacoes_gerais'] ?? ''),
        'status_geral' => $statusGeral,
        'responsavel_principal' => $isAdmin && isset($_POST['responsavel_principal']) ? 1 : 0,
    ];

    remember_old(array_merge($_POST, ['valor_proposta' => $_POST['valor_proposta'] ?? '' ]));

    $errors = [];
    if ($municipioId <= 0) {
        $errors[] = 'Selecione um municipio.';
    }
    if ($isAdmin && $representanteTipo === 'externo') {
        if ($representanteNomeExterno === '') {
            $errors[] = 'Informe o nome do representante externo.';
        }
    } else {
        if ($representanteId <= 0) {
            $errors[] = 'Selecione um representante valido.';
        }
    }

    if ($isVendor && $representanteId <= 0) {
        $errors[] = 'Seu usuario precisa estar vinculado a um representante.';
    }

    if ($isEdit) {
        $existing = find_atendimento($pdo, $id);
        if (!$existing) {
            $errors[] = 'Atendimento nao encontrado.';
        } elseif (
            !$isAdmin
            && !($isVendor && (int)$existing['vendedor_id'] === (int)$user['id'])
            && !($isRep && (int)$existing['representante_id'] === (int)$user['id'])
        ) {
            $errors[] = 'Voce nao tem permissao para editar este atendimento.';
        }
    }

    foreach (['data_contato', 'data_envio', 'previsao_fechamento'] as $dateField) {
        if ($formValues[$dateField] !== '' && !valid_date($formValues[$dateField])) {
            $errors[] = 'Data invalida em ' . str_replace('_', ' ', $dateField) . '.';
        }
    }

    if (!empty($errors)) {
        flash('error', implode(' ', $errors));
        redirect($isEdit ? 'atendimento_form.php?id=' . $id : 'atendimento_form.php');
    }

    $representanteIdParam = ($representanteId !== null && (int)$representanteId > 0) ? (int)$representanteId : null;
    $vendedorIdParam = ($vendedorId !== null && (int)$vendedorId > 0) ? (int)$vendedorId : null;
    $representanteNomeExternoParam = null;
    if ($isAdmin && $representanteTipo === 'externo' && $representanteNomeExterno !== '') {
        $nomeAdminParaRegistro = trim((string)($user['name'] ?? ''));
        if ($nomeAdminParaRegistro === '') {
            $nomeAdminParaRegistro = 'Externo';
        }
        $combinedNome = $nomeAdminParaRegistro . '|' . $representanteNomeExterno;
        if (function_exists('mb_substr')) {
            $combinedNome = mb_substr($combinedNome, 0, 150);
        } else {
            $combinedNome = substr($combinedNome, 0, 150);
        }
        $representanteNomeExternoParam = $combinedNome;
    }

    $baseParams = [
        ':municipio_id' => $municipioId,
        ':representante_id' => $representanteIdParam,
        ':vendedor_id' => $vendedorIdParam,
        ':representante_nome_externo' => $representanteNomeExternoParam,
        ':periodo_relatorio' => nullable($formValues['periodo_relatorio']),
        ':secretaria_escola' => nullable($formValues['secretaria_escola']),
        ':contato_principal' => nullable($formValues['contato_principal']),
        ':status_visita' => nullable($formValues['status_visita']),
        ':observacoes' => nullable($formValues['observacoes']),
        ':tipo_contato' => nullable($formValues['tipo_contato']),
        ':data_contato' => $formValues['data_contato'] !== '' ? $formValues['data_contato'] : null,
        ':situacao_atual' => nullable($formValues['situacao_atual']),
        ':valor_proposta' => $formValues['valor_proposta'],
        ':itens_projeto' => nullable($formValues['itens_projeto']),
        ':data_envio' => $formValues['data_envio'] !== '' ? $formValues['data_envio'] : null,
        ':status_proposta' => nullable($formValues['status_proposta']),
        ':previsao_fechamento' => $formValues['previsao_fechamento'] !== '' ? $formValues['previsao_fechamento'] : null,
        ':dificuldades' => nullable($formValues['dificuldades']),
        ':acoes_futuras' => nullable($formValues['acoes_futuras']),
        ':observacoes_gerais' => nullable($formValues['observacoes_gerais']),
        ':status_geral' => $formValues['status_geral'],
        ':responsavel_principal' => $formValues['responsavel_principal'],
    ];

    if ($isEdit) {
        $sql = <<<SQL
UPDATE atendimentos
SET municipio_id = :municipio_id,
    representante_id = :representante_id,
    vendedor_id = :vendedor_id,
    representante_nome_externo = :representante_nome_externo,
    periodo_relatorio = :periodo_relatorio,
    secretaria_escola = :secretaria_escola,
    contato_principal = :contato_principal,
    status_visita = :status_visita,
    observacoes = :observacoes,
    tipo_contato = :tipo_contato,
    data_contato = :data_contato,
    situacao_atual = :situacao_atual,
    valor_proposta = :valor_proposta,
    itens_projeto = :itens_projeto,
    data_envio = :data_envio,
    status_proposta = :status_proposta,
    previsao_fechamento = :previsao_fechamento,
    dificuldades = :dificuldades,
    acoes_futuras = :acoes_futuras,
    observacoes_gerais = :observacoes_gerais,
    status_geral = :status_geral,
    responsavel_principal = :responsavel_principal
WHERE id = :id
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($baseParams, [':id' => $id]));
        log_activity((int)$user['id'], 'atendimento_update', 'Atualizou atendimento #' . $id);
        flash('status', 'Atendimento atualizado com sucesso.');
    } else {
        $sql = <<<SQL
INSERT INTO atendimentos (
    municipio_id,
    representante_id,
    vendedor_id,
    representante_nome_externo,
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
    :vendedor_id,
    :representante_nome_externo,
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
    :status_geral,
    :responsavel_principal
)
SQL;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($baseParams);
        $novoId = (int)$pdo->lastInsertId();
        log_activity((int)$user['id'], 'atendimento_create', 'Criou atendimento #' . $novoId);
        flash('status', 'Atendimento criado com sucesso.');
    }

    clear_old();
    redirect('atendimentos.php');
}

$atendimento = null;
if ($isEdit) {
    $atendimento = find_atendimento($pdo, $id);
    if (!$atendimento) {
        flash('error', 'Atendimento nao encontrado.');
        redirect('atendimentos.php');
    }
    if (
        !$isAdmin
        && !($isVendor && (int)$atendimento['vendedor_id'] === (int)$user['id'])
        && !($isRep && (int)$atendimento['representante_id'] === (int)$user['id'])
    ) {
        flash('error', 'Voce nao tem permissao para visualizar este atendimento.');
        redirect('atendimentos.php');
    }
}

// ----------------------------------------------------------
// FILTRO DE MUNICÍPIOS POR ESTADO DO USUÁRIO
// ----------------------------------------------------------
$userId = (int)$_SESSION['user_id'];
$stmtUser = $pdo->prepare('SELECT role, estado FROM users WHERE id = :id');
$stmtUser->execute([':id' => $userId]);
$userData = $stmtUser->fetch();

$isAdmin = $userData && in_array($userData['role'], ['ADMIN', 'ADMIN/REPRESENTANTE'], true);

// Busca estados extras (tabela user_estados)
$stmtExtras = $pdo->prepare('SELECT estado FROM user_estados WHERE user_id = :id');
$stmtExtras->execute([':id' => $userId]);
$estadosExtras = $stmtExtras->fetchAll(PDO::FETCH_COLUMN);

// Junta o estado principal do usuário + os adicionais (sem duplicar)
$estadosPermitidos = array_filter(array_unique(array_merge([$userData['estado']], $estadosExtras)));

// Monta consulta de municípios conforme o tipo de usuário
if ($isAdmin) {
    // Admin pode ver todos os municípios
    $stmtMunicipios = $pdo->query('SELECT id, nome FROM municipios ORDER BY nome');
    $municipios = $stmtMunicipios->fetchAll();
} else {
    // Representante: restringe aos estados vinculados
    if (empty($estadosPermitidos)) {
        // Caso extremo: usuário sem estado vinculado -> não mostra nada
        $municipios = [];
    } else {
        $placeholders = implode(',', array_fill(0, count($estadosPermitidos), '?'));
        $sql = "SELECT id, nome FROM municipios WHERE estado IN ($placeholders) ORDER BY nome";
        $stmtMunicipios = $pdo->prepare($sql);
        $stmtMunicipios->execute($estadosPermitidos);
        $municipios = $stmtMunicipios->fetchAll();
    }
}

$representantes = $isAdmin ? $pdo->query("SELECT id, name FROM users WHERE role LIKE '%REPRESENTANTE%' AND active = 1 ORDER BY name")->fetchAll() : [];

$pageTitle = $isEdit ? 'Editar atendimento' : 'Novo atendimento';
require __DIR__ . '/partials/header.php';
?>
<div class="max-w-4xl">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-slate-800"><?= esc($pageTitle) ?></h1>
        <a href="atendimentos.php" class="text-sm font-semibold text-slate-600 hover:text-slate-800">&larr; Voltar</a>
    </div>
    <form method="post" class="space-y-6 rounded-lg bg-white p-6 shadow">
        <input type="hidden" name="id" value="<?= $isEdit ? $atendimento['id'] : '' ?>">
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="municipio_id">Municipio *</label>
                <select name="municipio_id" id="municipio_id" required class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
                    <option value="">Selecione</option>
                    <?php
                    $defaultMunicipio = $atendimento['municipio_id'] ?? '';
                    if (!$isEdit && $defaultMunicipio === '' && isset($_GET['municipio_id'])) {
                        $defaultMunicipio = (int)$_GET['municipio_id'];
                    }
                    $selectedMunicipio = old('municipio_id', $defaultMunicipio);
                    foreach ($municipios as $municipio): ?>
                        <option value="<?= $municipio['id'] ?>" <?= (string)$selectedMunicipio === (string)$municipio['id'] ? 'selected' : '' ?>><?= esc($municipio['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($isAdmin): ?>
                <?php
                $selectedRep = old('representante_id', $atendimento['representante_id'] ?? '');
                if ($selectedRep === '0') {
                    $selectedRep = '';
                }
                [$storedAdminName, $storedExternalName] = parse_representante_externo($atendimento['representante_nome_externo'] ?? '');
                $defaultTipo = 'interno';
                if (($selectedRep === '' || $selectedRep === null) && $storedExternalName !== '') {
                    $defaultTipo = 'externo';
                }
                $representanteTipoAtual = old('representante_tipo', $defaultTipo);
                if ($representanteTipoAtual !== 'externo') {
                    $representanteTipoAtual = 'interno';
                }
                $externalValue = old('representante_externo', $storedExternalName);
                ?>
                <div>
                    <span class="mb-1 block text-xs font-semibold uppercase text-slate-500">Representante *</span>
                    <div class="mb-3 flex flex-wrap gap-4 text-sm text-slate-600">
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="representante_tipo" id="representante_tipo_interno" value="interno" <?= $representanteTipoAtual === 'externo' ? '' : 'checked' ?> class="h-4 w-4 text-slate-900">
                            <span>Equipe interna</span>
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="radio" name="representante_tipo" id="representante_tipo_externo" value="externo" <?= $representanteTipoAtual === 'externo' ? 'checked' : '' ?> class="h-4 w-4 text-slate-900">
                            <span>Representante externo</span>
                        </label>
                    </div>
                    <div data-representante-interno class="<?= $representanteTipoAtual === 'externo' ? 'hidden' : '' ?>">
                        <select name="representante_id" id="representante_id" <?= $representanteTipoAtual === 'externo' ? 'disabled' : 'required' ?> class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
                            <option value="">Selecione</option>
                            <?php foreach ($representantes as $rep): ?>
                                <option value="<?= $rep['id'] ?>" <?= (string)$selectedRep === (string)$rep['id'] ? 'selected' : '' ?>><?= esc($rep['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div data-representante-externo class="<?= $representanteTipoAtual === 'externo' ? '' : 'hidden' ?>">
                        <input type="text" name="representante_externo" id="representante_externo" value="<?= esc($externalValue) ?>" <?= $representanteTipoAtual === 'externo' ? 'required' : 'disabled' ?> class="w-full rounded border border-slate-200 px-3 py-2 text-sm" placeholder="Nome do representante externo">
                        <p class="mt-1 text-xs text-slate-500">O nome sera exibido como <?= esc($user['name']) ?> (nome informado acima).</p>
                    </div>
                </div>
            <?php else: ?>
                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase text-slate-500">Representante *</label>
                    <input type="text" value="<?= esc($user['name'] ?? $user['email']) ?>" disabled class="w-full rounded border border-slate-200 bg-slate-100 px-3 py-2 text-sm">
                </div>
            <?php endif; ?>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="periodo_relatorio">Periodo do relatorio</label>
                <input type="text" id="periodo_relatorio" name="periodo_relatorio" value="<?= esc(old('periodo_relatorio', $atendimento['periodo_relatorio'] ?? '')) ?>" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="secretaria_escola">Secretaria/Escola</label>
                <input type="text" id="secretaria_escola" name="secretaria_escola" value="<?= esc(old('secretaria_escola', $atendimento['secretaria_escola'] ?? '')) ?>" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="contato_principal">Contato principal</label>
                <input type="text" id="contato_principal" name="contato_principal" value="<?= esc(old('contato_principal', $atendimento['contato_principal'] ?? '')) ?>" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="status_visita">Status da visita</label>
                <input type="text" id="status_visita" name="status_visita" value="<?= esc(old('status_visita', $atendimento['status_visita'] ?? '')) ?>" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="tipo_contato">Tipo de contato</label>
                <input type="text" id="tipo_contato" name="tipo_contato" value="<?= esc(old('tipo_contato', $atendimento['tipo_contato'] ?? '')) ?>" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="data_contato">Data do contato</label>
                <input type="date" id="data_contato" name="data_contato" value="<?= esc(old('data_contato', $atendimento['data_contato'] ?? '')) ?>" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="situacao_atual">Situacao atual</label>
                <input type="text" id="situacao_atual" name="situacao_atual" value="<?= esc(old('situacao_atual', $atendimento['situacao_atual'] ?? '')) ?>" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="valor_proposta">Valor da proposta</label>
                <input type="text" id="valor_proposta" name="valor_proposta" value="<?= esc(old('valor_proposta', $atendimento['valor_proposta'] ?? '')) ?>" placeholder="Ex: 12500,00" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="data_envio">Data de envio</label>
                <input type="date" id="data_envio" name="data_envio" value="<?= esc(old('data_envio', $atendimento['data_envio'] ?? '')) ?>" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="status_proposta">Status da proposta</label>
                <input type="text" id="status_proposta" name="status_proposta" value="<?= esc(old('status_proposta', $atendimento['status_proposta'] ?? '')) ?>" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="previsao_fechamento">Previsao de fechamento</label>
                <input type="date" id="previsao_fechamento" name="previsao_fechamento" value="<?= esc(old('previsao_fechamento', $atendimento['previsao_fechamento'] ?? '')) ?>" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="status_geral">Status do registro</label>
                <select id="status_geral" name="status_geral" class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
                    <?php
                    $currentStatus = old('status_geral', $atendimento['status_geral'] ?? 'ATIVO');
                    foreach ($statusOptions as $value => $label):
                        $disabled = (!$isAdmin && $value === 'ARQUIVADO') ? 'disabled' : '';
                    ?>
                        <option value="<?= $value ?>" <?= $currentStatus === $value ? 'selected' : '' ?> <?= $disabled ?>><?= esc($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$isAdmin): ?>
                    <p class="mt-1 text-xs text-slate-500">Representantes podem marcar como Concluido; arquivar apenas administradores.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="flex items-center gap-2 pt-6">
                <?php $isResponsavel = (int)old('responsavel_principal', $atendimento['responsavel_principal'] ?? 0); ?>
                <?php if ($isAdmin): ?>
                    <input type="checkbox" id="responsavel_principal" name="responsavel_principal" value="1" <?= $isResponsavel === 1 ? 'checked' : '' ?> class="h-4 w-4 text-slate-900">
                    <label for="responsavel_principal" class="text-sm text-slate-600">Marcar como responsavel principal</label>
                <?php else: ?>
                    <input type="checkbox" disabled <?= $isResponsavel === 1 ? 'checked' : '' ?> class="h-4 w-4 text-slate-900">
                    <span class="text-sm text-slate-500">Responsavel principal (somente admin altera)</span>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="itens_projeto">Itens / Projeto</label>
            <textarea id="itens_projeto" name="itens_projeto" rows="3" class="w-full rounded border border-slate-200 px-3 py-2 text-sm"><?= esc(old('itens_projeto', $atendimento['itens_projeto'] ?? '')) ?></textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="dificuldades">Dificuldades</label>
            <textarea id="dificuldades" name="dificuldades" rows="3" class="w-full rounded border border-slate-200 px-3 py-2 text-sm"><?= esc(old('dificuldades', $atendimento['dificuldades'] ?? '')) ?></textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="acoes_futuras">Acoes futuras</label>
            <textarea id="acoes_futuras" name="acoes_futuras" rows="3" class="w-full rounded border border-slate-200 px-3 py-2 text-sm"><?= esc(old('acoes_futuras', $atendimento['acoes_futuras'] ?? '')) ?></textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="observacoes">Observacoes</label>
            <textarea id="observacoes" name="observacoes" rows="3" class="w-full rounded border border-slate-200 px-3 py-2 text-sm"><?= esc(old('observacoes', $atendimento['observacoes'] ?? '')) ?></textarea>
        </div>

        <div>
            <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="observacoes_gerais">Observacoes gerais</label>
            <textarea id="observacoes_gerais" name="observacoes_gerais" rows="3" class="w-full rounded border border-slate-200 px-3 py-2 text-sm"><?= esc(old('observacoes_gerais', $atendimento['observacoes_gerais'] ?? '')) ?></textarea>
        </div>

        <div class="flex justify-end gap-3">
            <a href="atendimentos.php" class="rounded border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancelar</a>
            <button type="submit" class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Salvar</button>
        </div>
    </form>
</div>
<?php if ($isAdmin): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var radios = document.querySelectorAll('input[name="representante_tipo"]');
    if (!radios.length) {
        return;
    }
    var internoWrapper = document.querySelector('[data-representante-interno]');
    var externoWrapper = document.querySelector('[data-representante-externo]');
    var selectInterno = document.getElementById('representante_id');
    var externoInput = document.getElementById('representante_externo');

    function toggleCampos() {
        var selecionado = document.querySelector('input[name="representante_tipo"]:checked');
        var isExterno = selecionado && selecionado.value === 'externo';
        if (isExterno) {
            if (internoWrapper) {
                internoWrapper.classList.add('hidden');
            }
            if (selectInterno) {
                selectInterno.setAttribute('disabled', 'disabled');
                selectInterno.removeAttribute('required');
            }
            if (externoWrapper) {
                externoWrapper.classList.remove('hidden');
            }
            if (externoInput) {
                externoInput.removeAttribute('disabled');
                externoInput.setAttribute('required', 'required');
            }
        } else {
            if (internoWrapper) {
                internoWrapper.classList.remove('hidden');
            }
            if (selectInterno) {
                selectInterno.removeAttribute('disabled');
                selectInterno.setAttribute('required', 'required');
            }
            if (externoWrapper) {
                externoWrapper.classList.add('hidden');
            }
            if (externoInput) {
                externoInput.setAttribute('disabled', 'disabled');
                externoInput.removeAttribute('required');
            }
        }
    }

    radios.forEach(function (radio) {
        radio.addEventListener('change', toggleCampos);
    });

    toggleCampos();
});
</script>
<?php endif; ?>
<?php
clear_old();
require __DIR__ . '/partials/footer.php';

function find_atendimento(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM atendimentos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function valid_date(string $value): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

function nullable($value)
{
    return $value === '' ? null : $value;
}

/**
 * Converte string de dinheiro do input para decimal com ponto, sem alterar o valor.
 * Aceita “10.325,00”, “10325,00”, “10,325.00”, “10325.00” ou “10325”.
 * Retorna null se vazio.
 */
function money_from_input($raw)
{
    $s = trim((string)$raw);
    if ($s === '') {
        return null;
    }

    $s = preg_replace('/\s+/', '', $s);

    $lastComma = strrpos($s, ',');
    $lastDot   = strrpos($s, '.');

    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }
    } elseif ($lastComma !== false) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace(',', '', $s);
    }

    if (substr_count($s, '.') > 1) {
        $pos = strrpos($s, '.');
        $s = preg_replace('/\./', '', substr($s, 0, $pos)) . '.' . substr($s, $pos + 1);
    }

    return $s;
}
