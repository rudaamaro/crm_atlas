<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$pdo = get_pdo();

// obtém dados do usuário logado
$userId = (int)$_SESSION['user_id'];
$stmtUser = $pdo->prepare('SELECT id, role, estado FROM users WHERE id = :id');
$stmtUser->execute([':id' => $userId]);
$user = $stmtUser->fetch();

if (!$user || is_vendedor($user)) {
    flash('auth', 'Acesso restrito aos administradores ou representantes.');
    redirect_dashboard($user ?? []);
}

// captura filtro manual (estado) da URL
$filtroEstado = isset($_GET['estado']) && $_GET['estado'] !== '' ? trim($_GET['estado']) : null;

// se não for admin, restringe ao(s) estado(s) dele
if ($user && $user['role'] !== 'ADMIN' && $user['role'] !== 'ADMIN/REPRESENTANTE') {
    // busca todos os estados vinculados ao usuário
    $stmtEstados = $pdo->prepare('SELECT estado FROM user_estados WHERE user_id = :id');
    $stmtEstados->execute([':id' => $userId]);
    $estadosExtra = $stmtEstados->fetchAll(PDO::FETCH_COLUMN);

    // junta o estado principal + adicionais
    $estadosPermitidos = array_unique(array_merge([$user['estado']], $estadosExtra));

    // se o filtro manual não for permitido, remove
    if ($filtroEstado && !in_array($filtroEstado, $estadosPermitidos, true)) {
        flash('error', 'Você não tem permissão para visualizar este estado.');
        redirect('municipios.php');
    }

    // monta filtro SQL dinâmico
    $params = [];
    $sql = "SELECT * FROM municipios WHERE estado IN (" . implode(',', array_fill(0, count($estadosPermitidos), '?')) . ")";
    $params = $estadosPermitidos;

    // se houver filtro manual, restringe ainda mais
    if ($filtroEstado) {
        $sql .= " AND estado = ?";
        $params[] = $filtroEstado;
    }

    $sql .= " ORDER BY nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $municipiosPermitidos = $stmt->fetchAll();
} else {
    // Admin pode ver tudo, mas respeita filtro manual
    if ($filtroEstado) {
        $stmt = $pdo->prepare('SELECT * FROM municipios WHERE estado = :estado ORDER BY nome');
        $stmt->execute([':estado' => $filtroEstado]);
    } else {
        $stmt = $pdo->query('SELECT * FROM municipios ORDER BY nome');
    }
    $municipiosPermitidos = $stmt->fetchAll();
}

if (is_post()) {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'criar') {
        if (!is_admin($user)) {
            flash('auth', 'Apenas administradores podem criar municípios.');
            redirect('municipios.php');
        }
        $nome = trim($_POST['nome'] ?? '');
        $codigo_ibge = trim($_POST['codigo_ibge'] ?? '');
        $estadoMunicipio = trim($_POST['estado'] ?? $user['estado'] ?? '');

        if ($nome === '') {
            flash('error', 'Informe o nome do município.');
            redirect('municipios.php');
        }

        $stmt = $pdo->prepare('SELECT id FROM municipios WHERE nome = :nome LIMIT 1');
        $stmt->execute([':nome' => $nome]);
        if ($stmt->fetch()) {
            flash('error', 'Este município já está cadastrado.');
            redirect('municipios.php');
        }

        if ($user['role'] !== 'ADMIN' && $user['role'] !== 'ADMIN/REPRESENTANTE') {
            if (!in_array($estadoMunicipio, $estadosPermitidos, true)) {
                flash('error', 'Você não tem permissão para cadastrar municípios de outro estado.');
                redirect('municipios.php');
            }
        }

        $pdo->prepare('INSERT INTO municipios (nome, codigo_ibge, estado)
                       VALUES (:nome, :codigo_ibge, :estado)')
            ->execute([
                ':nome' => $nome,
                ':codigo_ibge' => $codigo_ibge,
                ':estado' => $estadoMunicipio,
            ]);

        log_activity((int)$_SESSION['user_id'], 'municipio_create', 'Criou município ' . $nome);
        flash('status', 'Município adicionado com sucesso.');
        redirect('municipios.php');
    }
}

/**
 * Filtro:
 * - compatível com o antigo ?sem_atendimentos=1
 * - novo select ?filtro_atend = "" | "com" | "sem"
 */
$legacySem = isset($_GET['sem_atendimentos']) && $_GET['sem_atendimentos'] === '1';
$filtroAtend = $_GET['filtro_atend'] ?? '';
if ($legacySem && $filtroAtend === '') {
    $filtroAtend = 'sem';
}

// Base da consulta
$sqlMunicipios = "
    SELECT
        m.id,
        m.nome,
        m.codigo_ibge,
        m.estado,
        m.representante_id,
        m.vendedor_id,
        m.created_at,
        COUNT(a.id) AS ativos,
        MIN(a.id) AS primeiro_atendimento_id
    FROM municipios m
    LEFT JOIN atendimentos a
        ON a.municipio_id = m.id
       AND a.status_geral <> 'ARQUIVADO'
";

// filtro por estado (manual ou por permissão)
$where = [];
$paramsMunicipios = [];

if (!empty($filtroEstado)) {
    $where[] = "m.estado = ?";
    $paramsMunicipios[] = $filtroEstado;
} elseif ($user && $user['role'] !== 'ADMIN' && $user['role'] !== 'ADMIN/REPRESENTANTE') {
    $placeholders = implode(',', array_fill(0, count($estadosPermitidos), '?'));
    $where[] = "m.estado IN ($placeholders)";
    $paramsMunicipios = array_merge($paramsMunicipios, $estadosPermitidos);
}

if (!empty($where)) {
    $sqlMunicipios .= " WHERE " . implode(' AND ', $where);
}

$sqlMunicipios .= "
    GROUP BY m.id, m.nome, m.codigo_ibge, m.created_at, m.estado, m.representante_id, m.vendedor_id
";

if ($filtroAtend === 'sem') {
    $sqlMunicipios .= " HAVING ativos = 0";
} elseif ($filtroAtend === 'com') {
    $sqlMunicipios .= " HAVING ativos > 0";
}

$sqlMunicipios .= " ORDER BY m.estado, m.nome";

$stmtMunicipios = $pdo->prepare($sqlMunicipios);
$stmtMunicipios->execute($paramsMunicipios);
$municipios = $stmtMunicipios->fetchAll();
$responsaveisMunicipios = [];
if (!empty($municipios)) {
    $repIds = array_unique(array_filter(array_column($municipios, 'representante_id')));
    $vendIds = array_unique(array_filter(array_column($municipios, 'vendedor_id')));
    $allIds = array_unique(array_merge($repIds, $vendIds));
    if (!empty($allIds)) {
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $stmtUsers = $pdo->prepare("SELECT id, name, role FROM users WHERE id IN ($placeholders)");
        $stmtUsers->execute($allIds);
        foreach ($stmtUsers->fetchAll() as $u) {
            $responsaveisMunicipios[(int)$u['id']] = $u;
        }
    }
}

$totalMunicipios = count($municipios);
$pageTitle = 'Municípios';
require __DIR__ . '/partials/header.php';

// Lista de todos os estados brasileiros (tabela fixa)
$stmtEstados = $pdo->query("SELECT sigla FROM estados_brasil ORDER BY sigla ASC");
$listaEstados = $stmtEstados->fetchAll(PDO::FETCH_COLUMN);

?>
<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-slate-800">Municípios</h1>
            <p class="mt-1 text-sm text-slate-600">Cadastre manualmente e acompanhe onde há atendimentos ativos.</p>
        </div>

        <!-- Novo filtro -->
        <form method="get" class="flex items-center gap-2 text-sm text-slate-600">
            <label for="estado" class="font-medium">Estado:</label>
            <select id="estado" name="estado" class="rounded border border-slate-300 px-3 py-1 text-sm">
                <option value="">Todos</option>
                <?php foreach ($listaEstados as $uf): ?>
                    <option value="<?= htmlspecialchars($uf) ?>" <?= $uf === $filtroEstado ? 'selected' : '' ?>>
                        <?= htmlspecialchars($uf) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="filtro_atend" class="font-medium">Mostrar:</label>
            <select id="filtro_atend" name="filtro_atend" class="rounded border border-slate-300 px-3 py-1 text-sm">
                <option value="" <?= $filtroAtend === '' ? 'selected' : '' ?>>Todos</option>
                <option value="com" <?= $filtroAtend === 'com' ? 'selected' : '' ?>>Apenas com atendimento</option>
                <option value="sem" <?= $filtroAtend === 'sem' ? 'selected' : '' ?>>Apenas sem atendimento</option>
            </select>

            <button type="submit"
                class="rounded border border-slate-300 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                Filtrar
            </button>
        </form>
    </div>

    <section class="grid gap-4 md:grid-cols-2">
        <form method="post" class="rounded-lg bg-white p-5 shadow space-y-4">
            <input type="hidden" name="acao" value="criar">
            <h2 class="text-lg font-semibold text-slate-800">Adicionar município</h2>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="nome">Nome</label>
                <input type="text" id="nome" name="nome" required
                    class="w-full rounded border border-slate-200 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="codigo_ibge">Código IBGE</label>
                <input type="text" id="codigo_ibge" name="codigo_ibge"
                    class="w-full rounded border border-slate-200 px-3 py-2 text-sm" placeholder="Opcional">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase text-slate-500" for="estado">Estado</label>
                <select id="estado" name="estado" class="w-full rounded border border-slate-200 px-3 py-2 text-sm" required>
                    <?php foreach ($listaEstados as $uf): ?>
                        <option value="<?= htmlspecialchars($uf) ?>"><?= htmlspecialchars($uf) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit"
                class="rounded bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                Salvar
            </button>
        </form>

        <div class="rounded-lg bg-white p-5 shadow">
            <h2 class="text-lg font-semibold text-slate-800">Resumo</h2>
            <p class="mt-2 text-sm text-slate-600">Municípios listados: <strong><?= $totalMunicipios ?></strong></p>
            <p class="text-sm text-slate-600">Atendimentos ativos consideram registros com status diferente de Arquivado.</p>
        </div>
    </section>

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-slate-200 bg-white text-sm shadow">
            <thead class="bg-slate-50">
                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-slate-600">
                    <th class="px-4 py-3">Nome</th>
                    <th class="px-4 py-3">Estado</th>
                    <th class="px-4 py-3">Representante</th>
                    <th class="px-4 py-3">Vendedor</th>
                    <th class="px-4 py-3">Atendimentos ativos</th>
                    <th class="px-4 py-3">Código IBGE</th>
                    <th class="px-4 py-3">Cadastro</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php $i = 0; foreach ($municipios as $linha): $i++; ?>
                    <tr>
                        <td class="px-4 py-3 font-semibold text-slate-800">
                            <span class="text-slate-400"><?= $i ?> - </span><?= esc($linha['nome']) ?>
                        </td>
                        <td class="px-4 py-3 text-slate-600"><?= esc($linha['estado'] ?? '') ?></td>
                        <td class="px-4 py-3 text-slate-600">
                            <?php if (!empty($linha['representante_id']) && isset($responsaveisMunicipios[(int)$linha['representante_id']])): ?>
                                <?= esc($responsaveisMunicipios[(int)$linha['representante_id']]['name']) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            <?php if (!empty($linha['vendedor_id']) && isset($responsaveisMunicipios[(int)$linha['vendedor_id']])): ?>
                                <?= esc($responsaveisMunicipios[(int)$linha['vendedor_id']]['name']) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            <div class="flex items-center gap-2">
                                <span class="font-semibold text-slate-700"><?= (int)$linha['ativos'] ?></span>
                                <?php if ((int)$linha['ativos'] > 0): ?>
                                    <?php if ((int)$linha['ativos'] === 1 && !empty($linha['primeiro_atendimento_id'])): ?>
                                        <a href="atendimento_form.php?id=<?= $linha['primeiro_atendimento_id'] ?>"
                                           class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100">
                                           Exibir
                                        </a>
                                    <?php else: ?>
                                        <a href="duplicidade_comparar.php?municipio_id=<?= $linha['id'] ?>"
                                           class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100">
                                           Exibir
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="atendimento_form.php?municipio_id=<?= $linha['id'] ?>"
                                       class="rounded border border-slate-300 px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100">
                                       +
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-slate-600"><?= esc($linha['codigo_ibge'] ?? '') ?></td>
                        <td class="px-4 py-3 text-slate-500"><?= esc(format_date($linha['created_at'], 'd/m/Y')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($municipios)): ?>
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">
                            Nenhum município encontrado com o filtro atual.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
require __DIR__ . '/partials/footer.php';
