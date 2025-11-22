<?php
require_once __DIR__ . '/bootstrap.php';
require_login();

$pdo  = get_pdo();
$user = current_user();
$isAdmin = is_admin($user);

$municipioId = isset($_GET['municipio_id']) ? (int)$_GET['municipio_id'] : 0;

/**
 * NOVO: reter o filtro do representante (somente admin)
 * Se vier ?representante_id=123, usamos isso para montar o link de "Voltar"
 * e também para redirecionamentos dentro desta página.
 */
$repBack = ($isAdmin && isset($_GET['representante_id'])) ? (int)$_GET['representante_id'] : 0;

if ($municipioId <= 0) {
    flash('error', 'Municipio invalido.');
    // manter o filtro ao voltar
    redirect('duplicidades.php' . ($repBack > 0 ? ('?representante_id=' . $repBack) : ''));
}

if (is_post() && $isAdmin) {
    $responsavelId = isset($_POST['responsavel_id']) ? (int)$_POST['responsavel_id'] : 0;
    if ($responsavelId > 0) {
        $stmtCheck = $pdo->prepare('SELECT id FROM atendimentos WHERE id = :id AND municipio_id = :municipio_id');
        $stmtCheck->execute([':id' => $responsavelId, ':municipio_id' => $municipioId]);
        if ($stmtCheck->fetch()) {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE atendimentos SET responsavel_principal = 0 WHERE municipio_id = :municipio_id')
                ->execute([':municipio_id' => $municipioId]);
            $pdo->prepare('UPDATE atendimentos SET responsavel_principal = 1 WHERE id = :id')
                ->execute([':id' => $responsavelId]);
            $pdo->commit();
            log_activity((int)$user['id'], 'duplicidade_responsavel', 'Definiu responsavel principal atendimento #' . $responsavelId);
            flash('status', 'Responsavel principal atualizado.');
        } else {
            flash('error', 'Atendimento invalido para este municipio.');
        }
    }
    // manter o filtro ao recarregar a comparação
    redirect('duplicidade_comparar.php?municipio_id=' . $municipioId . ($repBack > 0 ? ('&representante_id=' . $repBack) : ''));
}

$stmtMunicipio = $pdo->prepare('SELECT id, nome FROM municipios WHERE id = :id');
$stmtMunicipio->execute([':id' => $municipioId]);
$municipio = $stmtMunicipio->fetch();
if (!$municipio) {
    flash('error', 'Municipio nao encontrado.');
    // manter o filtro ao voltar
    redirect('duplicidades.php' . ($repBack > 0 ? ('?representante_id=' . $repBack) : ''));
}

$sqlAtendimentos = <<<SQL
SELECT a.*, u.name AS representante_usuario_nome, u.email AS representante_email
FROM atendimentos a
LEFT JOIN users u ON u.id = a.representante_id
WHERE a.municipio_id = :municipio_id
  AND a.status_geral <> 'ARQUIVADO'
ORDER BY a.responsavel_principal DESC, a.updated_at DESC
SQL;

$stmt = $pdo->prepare($sqlAtendimentos);
$stmt->execute([':municipio_id' => $municipioId]);
$atendimentos = $stmt->fetchAll();
foreach ($atendimentos as &$item) {
    $item['representante_nome'] = format_representante_nome($item['representante_nome_externo'] ?? null, $item['representante_usuario_nome'] ?? null);
}
unset($item);

if (!$isAdmin) {
    $hasOwn = false;
    foreach ($atendimentos as $a) {
        if ((int)$a['representante_id'] === (int)$user['id']) {
            $hasOwn = true;
            break;
        }
    }
    if (!$hasOwn) {
        flash('error', 'Voce nao tem acesso a esta duplicidade.');
        redirect('duplicidades.php');
    }
}

$pageTitle = 'Comparar atendimentos';
require __DIR__ . '/partials/header.php';

// NOVO: compor a URL de voltar preservando o filtro de representante
$backUrl = 'duplicidades.php' . ($repBack > 0 ? ('?representante_id=' . $repBack) : '');
?>
<div class="flex flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-slate-800">Comparar atendimentos – <?= esc($municipio['nome']) ?></h1>
            <p class="mt-1 text-sm text-slate-600">Atendimentos ativos registrados para este municipio.</p>
        </div>
        <a href="<?= $backUrl ?>" class="text-sm font-semibold text-slate-600 hover:text-slate-800">&larr; Voltar</a>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <?php foreach ($atendimentos as $atendimento): ?>
            <article class="rounded-lg bg-white p-5 shadow">
                <div class="mb-3 flex items-start justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-800">
                            <?= esc($atendimento['representante_nome'] ?? 'Representante') ?>
                        </h2>
                        <p class="text-xs uppercase tracking-wide text-slate-500">
                            Atualizado em <?= esc(format_date($atendimento['updated_at'], 'd/m/Y H:i')) ?>
                        </p>
                    </div>
                    <?php if ((int)$atendimento['responsavel_principal'] === 1): ?>
                        <span class="rounded-full bg-emerald-100 px-2 py-1 text-xs font-semibold uppercase text-emerald-600">Principal</span>
                    <?php endif; ?>
                </div>

                <!-- Campo promovido: Secretaria / Escola (valor em negrito) -->
                <dl class="space-y-2 text-sm text-slate-600">
                    <div class="grid grid-cols-2">
                        <dt class="font-semibold text-slate-500">Secretaria / Escola</dt>
                        <dd class="font-bold text-slate-800"><?= esc($atendimento['secretaria_escola'] ?? '--') ?></dd>
                    </div>
                    <div class="grid grid-cols-2">
                        <dt class="font-semibold text-slate-500">Data do contato</dt>
                        <dd><?= esc(format_date($atendimento['data_contato'])) ?></dd>
                    </div>
                    <div class="grid grid-cols-2">
                        <dt class="font-semibold text-slate-500">Situacao atual</dt>
                        <dd><?= esc($atendimento['situacao_atual'] ?? '--') ?></dd>
                    </div>
                    <div class="grid grid-cols-2">
                        <dt class="font-semibold text-slate-500">Status proposta</dt>
                        <dd><?= esc($atendimento['status_proposta'] ?? '--') ?></dd>
                    </div>
                    <div class="grid grid-cols-2">
                        <dt class="font-semibold text-slate-500">Valor proposta</dt>
                        <dd><?= esc(format_currency($atendimento['valor_proposta'])) ?></dd>
                    </div>
                    <div class="grid grid-cols-2">
                        <dt class="font-semibold text-slate-500">Status geral</dt>
                        <dd><?= esc($atendimento['status_geral']) ?></dd>
                    </div>
                </dl>

                <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                    <button type="button"
                            class="rounded border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100"
                            data-toggle-card="details-<?= $atendimento['id'] ?>">
                        Ver detalhes
                    </button>

                    <?php if ($isAdmin || (int)$atendimento['representante_id'] === (int)$user['id']): ?>
                        <div class="flex items-center gap-2 text-xs">
                            <a href="atendimento_form.php?id=<?= $atendimento['id'] ?>"
                               class="font-semibold text-slate-600 hover:text-slate-800">Editar atendimento</a>
                            <span class="text-slate-300">|</span>
                            <a href="atendimento_delete.php?id=<?= $atendimento['id'] ?>"
                               class="font-semibold text-rose-600 hover:text-rose-700"
                               onclick="return confirm('Excluir atendimento? Esta acao nao pode ser desfeita.');">
                                Excluir
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <div id="details-<?= $atendimento['id'] ?>" class="mt-4 hidden space-y-3 border-t border-slate-200 pt-4 text-sm text-slate-600">
                    <dl class="grid gap-2">
                        <div class="grid grid-cols-2">
                            <dt class="font-semibold text-slate-500">Periodo do relatorio</dt>
                            <dd><?= esc($atendimento['periodo_relatorio'] ?? '--') ?></dd>
                        </div>
                        <!-- (Secretaria / Escola) foi promovido para o bloco superior -->
                        <div class="grid grid-cols-2">
                            <dt class="font-semibold text-slate-500">Contato principal</dt>
                            <dd><?= esc($atendimento['contato_principal'] ?? '--') ?></dd>
                        </div>
                        <div class="grid grid-cols-2">
                            <dt class="font-semibold text-slate-500">Status da visita</dt>
                            <dd><?= esc($atendimento['status_visita'] ?? '--') ?></dd>
                        </div>
                        <div class="grid grid-cols-2">
                            <dt class="font-semibold text-slate-500">Tipo de contato</dt>
                            <dd><?= esc($atendimento['tipo_contato'] ?? '--') ?></dd>
                        </div>
                        <div class="grid grid-cols-2">
                            <dt class="font-semibold text-slate-500">Data de envio</dt>
                            <dd><?= esc(format_date($atendimento['data_envio'])) ?></dd>
                        </div>
                        <div class="grid grid-cols-2">
                            <dt class="font-semibold text-slate-500">Previsao de fechamento</dt>
                            <dd><?= esc(format_date($atendimento['previsao_fechamento'])) ?></dd>
                        </div>
                        <div class="grid grid-cols-2">
                            <dt class="font-semibold text-slate-500">Dificuldades</dt>
                            <dd><?= esc($atendimento['dificuldades'] ?? '--') ?></dd>
                        </div>
                        <div class="grid grid-cols-2">
                            <dt class="font-semibold text-slate-500">Itens / Projeto</dt>
                            <dd><?= esc($atendimento['itens_projeto'] ?? '--') ?></dd>
                        </div>
                    </dl>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Observacoes</div>
                        <p class="mt-1 text-slate-600"><?= nl2br(esc($atendimento['observacoes'] ?? '--')) ?></p>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Acoes futuras</div>
                        <p class="mt-1 text-slate-600"><?= nl2br(esc($atendimento['acoes_futuras'] ?? '--')) ?></p>
                    </div>
                    <div>
                        <div class="text-xs font-semibold uppercase text-slate-500">Observacoes gerais</div>
                        <p class="mt-1 text-slate-600"><?= nl2br(esc($atendimento['observacoes_gerais'] ?? '--')) ?></p>
                    </div>
                </div>

                <?php if ($isAdmin): ?>
                    <form method="post" class="mt-4">
                        <input type="hidden" name="responsavel_id" value="<?= $atendimento['id'] ?>">
                        <button type="submit" class="w-full rounded border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            Definir como responsavel principal
                        </button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php if (empty($atendimentos)): ?>
            <p class="text-sm text-slate-500">Nenhum atendimento ativo encontrado para este municipio.</p>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
    document.querySelectorAll('[data-toggle-card]').forEach(function(button){
        button.addEventListener('click', function(){
            var targetId = button.getAttribute('data-toggle-card');
            var target = document.getElementById(targetId);
            if (!target) return;
            var hidden = target.classList.contains('hidden');
            target.classList.toggle('hidden');
            button.textContent = hidden ? 'Ocultar detalhes' : 'Ver detalhes';
        });
    });
})();
</script>
<?php
require __DIR__ . '/partials/footer.php';

function truncate_text(?string $text, int $limit = 120): string
{
    if (!$text) return '--';
    $text = trim($text);
    if (mb_strlen($text) <= $limit) return $text;
    return mb_substr($text, 0, $limit) . '...';
}
