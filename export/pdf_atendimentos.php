<?php
// crm/export/pdf_atendimentos.php
// Gera PDF da lista de atendimentos com os mesmos filtros da tela

require_once __DIR__ . '/../bootstrap.php'; // usa o bootstrap do seu CRM (login, PDO etc.)
require_login();

$pdo     = get_pdo();
$user    = current_user();
$isAdmin = is_admin($user);

// ===== Captura filtros vindos da tela (mantive os mesmos nomes usados no projeto) =====
$filters = [
    'municipio_id'     => isset($_GET['municipio_id']) && $_GET['municipio_id'] !== '' ? (int)$_GET['municipio_id'] : null,
    'representante_id' => $isAdmin && isset($_GET['representante_id']) && $_GET['representante_id'] !== '' ? (int)$_GET['representante_id'] : null,
    'situacao_atual'   => trim($_GET['situacao_atual']   ?? ''),
    'status_proposta'  => trim($_GET['status_proposta']  ?? ''),
    'status_geral'     => strtoupper(trim($_GET['status_geral'] ?? '')),
    'data_inicio'      => trim($_GET['data_inicio']      ?? ''),
    'data_fim'         => trim($_GET['data_fim']         ?? ''),
    'com_valor'        => isset($_GET['com_valor'])    && $_GET['com_valor']    === '1',
    'com_previsao'     => isset($_GET['com_previsao']) && $_GET['com_previsao'] === '1',
];

// Se não for admin, amarra ao próprio usuário
if (!$isAdmin) {
    $filters['representante_id'] = (int)$user['id'];
}

// ===== Monta WHERE exatamente como a tela =====
$where  = [];
$params = [];

if ($filters['municipio_id']) {
    $where[] = 'a.municipio_id = :municipio_id';
    $params[':municipio_id'] = $filters['municipio_id'];
}

if (!empty($filters['representante_id'])) {
    $rid = (int)$filters['representante_id'];

    // pega o nome do representante só para filtrar também por nome (quando id estiver nulo)
    $repName = fetch_scalar($pdo, 'SELECT name FROM users WHERE id = :rid', [':rid' => $rid]) ?: '';

    $where[] = '('
             . ' a.representante_id = :rid '
             . ' OR COALESCE(u.name, a.representante_nome_externo) = :repName '
             . ')';

    $params[':rid']     = $rid;
    $params[':repName'] = $repName;
}


if ($filters['situacao_atual'] !== '') {
    $where[] = 'a.situacao_atual = :situacao_atual';
    $params[':situacao_atual'] = $filters['situacao_atual'];
}

if ($filters['status_proposta'] !== '') {
    $where[] = 'a.status_proposta = :status_proposta';
    $params[':status_proposta'] = $filters['status_proposta'];
}

$status = $filters['status_geral'];
if (in_array($status, ['ATIVO','CONCLUIDO','ARQUIVADO'], true)) {
    $where[] = 'a.status_geral = :status_geral';
    $params[':status_geral'] = $status;
} elseif ($status !== 'TODOS') {
    // padrão da tela: Ativos e Concluídos (exclui Arquivados)
    $where[] = "a.status_geral <> 'ARQUIVADO'";
}

if ($filters['data_inicio'] !== '') {
    $where[] = 'DATE(a.updated_at) >= :data_inicio';
    $params[':data_inicio'] = $filters['data_inicio'];
}
if ($filters['data_fim'] !== '') {
    $where[] = 'DATE(a.updated_at) <= :data_fim';
    $params[':data_fim'] = $filters['data_fim'];
}

if ($filters['com_valor']) {
    $where[] = 'a.valor_proposta IS NOT NULL AND a.valor_proposta > 0';
}
if ($filters['com_previsao']) {
    $where[] = 'a.previsao_fechamento IS NOT NULL';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ===== Consulta (mesmas colunas exibidas na tabela) =====
$sql = <<<SQL
SELECT
    a.id,
    m.nome              AS municipio_nome,                     -- Prefeitura (município)
    COALESCE(u.name, a.representante_nome_externo) AS representante_nome,
    a.situacao_atual,
    a.status_proposta,
    a.status_geral,
    a.valor_proposta,
    a.updated_at
FROM atendimentos a
INNER JOIN municipios m ON m.id = a.municipio_id
LEFT JOIN users u ON u.id = a.representante_id
{$whereSql}
ORDER BY a.updated_at DESC, m.nome ASC
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== Labels de status (igual a badge da tela) =====
$statusLabels = [
    'ATIVO'      => 'Ativo',
    'CONCLUIDO'  => 'Concluido',
    'ARQUIVADO'  => 'Arquivado',
];

// Título (TODOS ou nome do representante)
$repTitulo = 'TODOS';
if (!empty($filters['representante_id'])) {
    $repTitulo = fetch_scalar($pdo, 'SELECT name FROM users WHERE id = :rid', [':rid' => (int)$filters['representante_id']]) ?: 'Representante';
}
$geradoEm = date('d/m/Y H:i');

// ===== HTML do relatório =====
ob_start();
?>
<!doctype html>
<html lang="pt-br">
<meta charset="utf-8">
<style>
  @page { margin: 18px; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color:#111; }
  h1 { font-size: 18px; margin: 0 0 6px; }
  .meta { font-size: 12px; margin-bottom: 10px; }
  table { width:100%; border-collapse: collapse; }
  thead { display: table-header-group; } /* cabeçalho repete nas páginas */
  th, td { border:1px solid #e5e7eb; padding:6px 8px; text-align:left; }
  th { background:#f3f4f6; font-weight:600; }
  .right { text-align:right; }
  .badge { display:inline-block; padding:1px 7px; border-radius:999px; border:1px solid #d1d5db; font-size: 11px; }
</style>
<body>
  <h1>Atendimentos – <?= htmlspecialchars($repTitulo) ?></h1>
  <div class="meta">Gerado em: <?= htmlspecialchars($geradoEm) ?> • Registros: <?= count($rows) ?></div>
  <table>
    <thead>
      <tr>
        <th>Prefeitura</th>
        <th>Representante</th>
        <th>Situação</th>
        <th>Status da Proposta</th>
        <th>Status Registro</th>
        <th class="right">Valor</th>
        <th>Última atualização</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="7">Nenhum registro encontrado para os filtros informados.</td></tr>
    <?php else: foreach ($rows as $r): ?>
      <tr>
        <td><?= htmlspecialchars($r['municipio_nome'] ?? '-') ?></td>
        <td><?= htmlspecialchars($r['representante_nome'] ?? '-') ?></td>
        <td><?= htmlspecialchars($r['situacao_atual'] ?? '--') ?></td>
        <td><?= htmlspecialchars($r['status_proposta'] ?? '--') ?></td>
        <td>
          <?php $lab = $statusLabels[$r['status_geral']] ?? ($r['status_geral'] ?? '--'); ?>
          <span class="badge"><?= htmlspecialchars($lab) ?></span>
        </td>
        <td class="right">
          <?php
           $v = $r['valor_proposta'];
           echo (is_null($v) || $v === '') ? '-' : 'R$ ' . number_format((float)$v, 2, ',', '.');
          ?>
        </td>
        <td><?= $r['updated_at'] ? date('d/m/Y H:i', strtotime($r['updated_at'])) : '-' ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</body>
</html>
<?php
$html = ob_get_clean();

// ===== Dompdf (instalação manual no seu /public_html/vendor/dompdf/dompdf) =====
require_once __DIR__ . '/../../vendor/dompdf/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isFontSubsettingEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape'); // paisagem
$dompdf->render();

$fname = 'atendimentos_' . preg_replace('/\s+/', '_', $repTitulo) . '_' . date('Ymd_His') . '.pdf';
$dompdf->stream($fname, ['Attachment' => true]);

// ===== Helper local =====
function fetch_scalar(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $v = $stmt->fetchColumn();
    return $v === false ? null : $v;
}
