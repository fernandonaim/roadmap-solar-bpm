<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/data.php';
require_login();

$action = $_GET['action'] ?? 'list';
$id     = $_GET['id']     ?? '';

// ── Delete ────────────────────────────────────────────────────────────────────
if ($action === 'delete' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    upstream_delete($id);
    header('Location: upstream.php?deleted=1');
    exit;
}

// ── Add / Edit POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'])) {
    $fields = [
        'title'         => trim($_POST['title']         ?? ''),
        'url'           => trim($_POST['url']           ?? ''),
        'phase'         => $_POST['phase']              ?? 'EPD',
        'etapa'         => trim($_POST['etapa']         ?? ''),
        'tipo'          => $_POST['tipo']               ?? 'sob',
        'tipo_evolucao' => $_POST['tipo_evolucao']      ?? '',
        'responsible'   => $_POST['responsible']        ?? 'Naim',
        'pf'            => max(0, (float)str_replace(',', '.', $_POST['pf']    ?? '0')),
        'horas'         => max(0, (float)str_replace(',', '.', $_POST['horas'] ?? '0')),
        'details'       => trim($_POST['details']       ?? ''),
    ];
    $err = '';
    if (empty($fields['title']))  $err = 'O título é obrigatório.';
    elseif (empty($fields['tipo'])) $err = 'Selecione o Tipo.';
    if ($err) {
        $_SESSION['_bounce'] = ['error' => $err, 'data' => $fields, 'modal' => $action, 'id' => $id];
        header('Location: upstream.php');
        exit;
    }
    if ($action === 'add')             upstream_create($fields);
    elseif ($action === 'edit' && $id) upstream_update($id, $fields);
    header('Location: upstream.php?saved=1');
    exit;
}

// ── Page state ────────────────────────────────────────────────────────────────
$bounce = $_SESSION['_bounce'] ?? null;
unset($_SESSION['_bounce']);

$message = '';
if (!empty($_GET['saved']))   $message = 'Item salvo com sucesso.';
if (!empty($_GET['deleted'])) $message = 'Item removido.';

$items    = upstream_all();
$config   = get_config();
$resps    = $config['responsibles'];
$tipos    = $config['tipos'];
$tipos_ev = $config['tipos_evolucao'];

$filter_phase = $_GET['phase'] ?? '';
$filter_resp  = $_GET['resp']  ?? '';
$search       = trim($_GET['q'] ?? '');
$filtered     = $items;
if ($filter_phase) $filtered = array_filter($filtered, fn($i) => $i['phase'] === $filter_phase);
if ($filter_resp)  $filtered = array_filter($filtered, fn($i) => $i['responsible'] === $filter_resp);
if ($search)       $filtered = array_filter($filtered, fn($i) => stripos($i['title'], $search) !== false);
$filtered = array_values($filtered);

$items_map = array_column($items, null, 'id');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Upstream — Admin Solar BPM</title>
<link rel="icon" type="image/png" href="../simbolo_Processos_Digitais.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:      #0d0d14;
    --surface: #13131f;
    --border:  #1e1e30;
    --text:    #e2e2f0;
    --muted:   #5a5a7a;
    --accent:  #60a5fa;
    --success: #34d399;
    --danger:  #f87171;
    --warn:    #fb923c;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'IBM Plex Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
  input[type=number]::-webkit-inner-spin-button,
  input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
  input[type=number] { -moz-appearance: textfield; appearance: textfield; }

  /* ── Topbar ── */
  .topbar {
    background: var(--surface); border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 28px; height: 52px;
  }
  .topbar-brand { font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--muted); letter-spacing: 2px; text-transform: uppercase; }
  .topbar-brand span { color: var(--accent); }
  .topbar-right { display: flex; gap: 20px; align-items: center; }
  .topbar-right a { color: var(--muted); text-decoration: none; font-size: 13px; transition: color 0.15s; }
  .topbar-right a:hover { color: var(--text); }
  .topbar-right a.active { color: var(--accent); }

  /* ── Layout ── */
  .main { padding: 32px 28px; max-width: 1200px; margin: 0 auto; }
  .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
  h1 { font-size: 20px; font-weight: 600; }

  /* ── Buttons ── */
  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 5px; border: 1px solid transparent;
    font-family: 'IBM Plex Sans', sans-serif; font-size: 13px; font-weight: 500;
    cursor: pointer; text-decoration: none; transition: opacity 0.15s; white-space: nowrap;
  }
  .btn:hover { opacity: 0.8; }
  .btn-primary { background: var(--accent); color: #0d0d14; }
  .btn-outline { background: transparent; border-color: var(--border); color: var(--text); }
  .btn-danger  { background: transparent; border-color: rgba(248,113,113,0.4); color: var(--danger); }
  .btn-sm { padding: 5px 11px; font-size: 12px; }

  /* ── Alert ── */
  .alert { padding: 10px 14px; border-radius: 5px; font-size: 13px; margin-bottom: 20px; }
  .alert-success { background: rgba(52,211,153,0.1); border: 1px solid rgba(52,211,153,0.3); color: var(--success); }
  .alert-error   { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.3); color: var(--danger); }

  /* ── Filters ── */
  .filters {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
    background: var(--surface); border: 1px solid var(--border); border-radius: 6px;
    padding: 12px 16px; margin-bottom: 20px;
  }
  .filters input[type=text] {
    background: var(--bg); border: 1px solid var(--border); border-radius: 4px;
    color: var(--text); font-family: 'IBM Plex Mono', monospace; font-size: 13px;
    padding: 7px 10px; outline: none; width: 200px;
  }
  .filters input:focus { border-color: var(--accent); }
  .filters select {
    background: var(--bg); border: 1px solid var(--border); border-radius: 4px;
    color: var(--text); font-size: 13px; padding: 7px 10px; outline: none; cursor: pointer;
  }
  .filters label { font-size: 12px; color: var(--muted); }
  .results-count { font-size: 12px; color: var(--muted); margin-left: auto; }

  /* ── Table ── */
  .table-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead th {
    background: var(--surface); border-bottom: 1px solid var(--border);
    padding: 10px 12px; text-align: left; font-weight: 500;
    font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--muted);
    white-space: nowrap;
  }
  tbody tr { border-bottom: 1px solid var(--border); transition: background 0.1s; }
  tbody tr:hover { background: rgba(255,255,255,0.02); }
  tbody td { padding: 10px 12px; vertical-align: middle; }
  .td-title { max-width: 320px; }
  .td-title a { color: var(--text); text-decoration: none; font-weight: 500; }
  .td-title a:hover { color: var(--accent); text-decoration: underline; }
  .td-title .item-id { font-family: 'IBM Plex Mono', monospace; font-size: 10px; color: var(--muted); display: block; margin-top: 2px; }
  .td-actions { white-space: nowrap; display: flex; gap: 6px; }
  .empty-state { text-align: center; padding: 60px 20px; color: var(--muted); font-size: 14px; }

  /* ── Badges ── */
  .badge {
    display: inline-block; font-size: 10px; font-weight: 500; letter-spacing: 0.5px;
    padding: 2px 7px; border-radius: 3px; white-space: nowrap; font-family: 'IBM Plex Mono', monospace;
  }
  .badge.epd  { background: #1a2040; color: #818cf8; }
  .badge.ers  { background: #1a2e2a; color: #34d399; }
  .badge.sob  { background: #1c2d4a; color: #60a5fa; border: 1px solid rgba(96,165,250,0.3); }
  .badge.melhoria { background: #1a2e2a; color: #34d399; border: 1px solid rgba(52,211,153,0.3); }
  .badge.sprint { background: #2d1f3a; color: #c084fc; border: 1px solid rgba(192,132,252,0.3); }
  .badge.implantacao { background: #0f2a2a; color: #2dd4bf; border: 1px solid rgba(45,212,191,0.3); }
  .badge.debito { background: #2a1a1a; color: #f87171; border: 1px solid rgba(248,113,113,0.3); }
  .badge.custom { background: #2d1f3a; color: #c084fc; }
  .badge.get    { background: #1a2040; color: #818cf8; }
  .badge.getf   { background: #1a2e2a; color: #34d399; }
  .etapa-badge {
    font-family: 'IBM Plex Mono', monospace; font-size: 10px; color: var(--muted);
    background: var(--bg); border: 1px solid var(--border); border-radius: 3px; padding: 2px 7px;
    white-space: nowrap;
  }
  .resp-chip {
    display: inline-flex; align-items: center; justify-content: center;
    width: 22px; height: 22px; border-radius: 50%;
    font-family: 'IBM Plex Mono', monospace; font-size: 10px; font-weight: 500;
    border: 1px solid rgba(255,255,255,0.15);
  }

  /* ── Modal base ── */
  .modal-overlay {
    display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75);
    align-items: center; justify-content: center; z-index: 100;
  }
  .modal-overlay.open { display: flex; }
  .modal-box {
    background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
    padding: 28px; max-width: 420px; width: 92%;
  }
  .modal-title { font-size: 15px; font-weight: 600; margin-bottom: 10px; }
  .modal-body  { font-size: 13px; color: var(--muted); margin-bottom: 24px; }
  .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

  /* ── Form modal ── */
  .modal-form-box {
    max-width: 700px; width: 96%; padding: 0;
    max-height: 92vh; overflow-y: auto; display: flex; flex-direction: column;
  }
  .modal-form-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 26px 16px; border-bottom: 1px solid var(--border); flex-shrink: 0;
  }
  .modal-form-title { font-size: 16px; font-weight: 600; }
  .modal-close-btn {
    background: none; border: 1px solid var(--border); color: var(--muted);
    font-size: 16px; cursor: pointer; line-height: 1; padding: 3px 9px; border-radius: 4px;
    transition: all 0.15s; font-family: 'IBM Plex Mono', monospace;
  }
  .modal-close-btn:hover { color: var(--text); border-color: var(--muted); }
  .modal-form-body { padding: 22px 26px 26px; flex: 1; }

  /* ── Form fields ── */
  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .form-group { display: flex; flex-direction: column; gap: 6px; }
  .form-group.full { grid-column: 1 / -1; }
  .form-group label {
    font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px;
  }
  .form-group input[type=text],
  .form-group input[type=url],
  .form-group input[type=number],
  .form-group select,
  .form-group textarea {
    background: var(--bg); border: 1px solid var(--border); border-radius: 4px;
    color: var(--text); font-family: 'IBM Plex Sans', sans-serif; font-size: 13px;
    padding: 9px 11px; outline: none; transition: border-color 0.15s; width: 100%;
  }
  .form-group input:focus,
  .form-group select:focus,
  .form-group textarea:focus { border-color: var(--accent); }
  .form-group textarea { resize: vertical; min-height: 80px; }
  .form-actions { display: flex; gap: 10px; margin-top: 20px; }
</style>
</head>
<body>

<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="main">
  <div class="page-header">
    <h1>Upstream Pipeline</h1>
    <button class="btn btn-primary" onclick="openAddModal()">+ Novo item</button>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <form method="get" class="filters">
    <label>Busca:</label>
    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Filtrar por título...">
    <label>Fase:</label>
    <select name="phase" onchange="this.form.submit()">
      <option value="">Todas</option>
      <option value="EPD" <?= $filter_phase === 'EPD' ? 'selected' : '' ?>>EPD</option>
      <option value="ERS" <?= $filter_phase === 'ERS' ? 'selected' : '' ?>>ERS</option>
    </select>
    <label>Responsável:</label>
    <select name="resp" onchange="this.form.submit()">
      <option value="">Todos</option>
      <?php foreach ($resps as $r): ?>
        <option value="<?= $r['name'] ?>" <?= $filter_resp === $r['name'] ? 'selected' : '' ?>><?= $r['name'] ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
    <?php if ($filter_phase || $filter_resp || $search): ?>
      <a href="upstream.php" class="btn btn-outline btn-sm">Limpar</a>
    <?php endif; ?>
    <span class="results-count"><?= count($filtered) ?> item(s)</span>
  </form>

  <?php if (empty($filtered)): ?>
    <div class="empty-state">
      Nenhum item encontrado.
      <button onclick="openAddModal()" style="background:none;border:none;color:var(--accent);cursor:pointer;font-size:14px;margin-left:6px;">Adicionar o primeiro →</button>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Título</th>
          <th>Fase</th>
          <th>Etapa</th>
          <th>Tipo</th>
          <th>Evolução</th>
          <th style="text-align:right">PF</th>
          <th style="text-align:right">Horas</th>
          <th>Resp.</th>
          <th>Atualizado</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($filtered as $i): ?>
        <tr>
          <td class="td-title">
            <?php if ($i['url']): ?>
              <a href="<?= htmlspecialchars($i['url']) ?>" target="_blank"><?= htmlspecialchars($i['title']) ?></a>
            <?php else: ?>
              <?= htmlspecialchars($i['title']) ?>
            <?php endif; ?>
            <span class="item-id"><?= htmlspecialchars($i['id']) ?></span>
          </td>
          <td><span class="badge <?= strtolower($i['phase']) ?>"><?= $i['phase'] ?></span></td>
          <td><span class="etapa-badge"><?= htmlspecialchars($i['etapa']) ?></span></td>
          <td><span class="badge <?= $i['tipo'] ?>"><?= htmlspecialchars(tipo_label($i['tipo'])) ?></span></td>
          <td>
            <?php if ($i['tipo_evolucao']): ?>
              <span class="badge <?= $i['tipo_evolucao'] ?>"><?= htmlspecialchars(tipo_evolucao_label($i['tipo_evolucao'])) ?></span>
            <?php else: ?>
              <span style="color:var(--muted)">—</span>
            <?php endif; ?>
          </td>
          <?php $pf = (float)($i['pf'] ?? 0); $horas = upstream_horas($i); ?>
          <td style="text-align:right;font-family:'IBM Plex Mono',monospace;font-size:12px;<?= $pf ? '' : 'color:var(--muted)' ?>">
            <?= $pf ? fmt_pf($pf) : '—' ?>
          </td>
          <td style="text-align:right;font-family:'IBM Plex Mono',monospace;font-size:12px;<?= $horas ? '' : 'color:var(--muted)' ?>">
            <?= $horas ? number_format($horas, 0, ',', '.') . 'h' : '—' ?>
          </td>
          <td>
            <span style="font-family:'IBM Plex Mono',monospace;font-size:12px;font-weight:500;color:<?= responsible_color($i['responsible']) ?>">
              <?= htmlspecialchars($i['responsible']) ?>
            </span>
          </td>
          <td style="font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--muted)"><?= $i['updated_at'] ?></td>
          <td class="td-actions">
            <button class="btn btn-outline btn-sm"
                    onclick="openEditModal('<?= htmlspecialchars(addslashes($i['id'])) ?>')">Editar</button>
            <button class="btn btn-danger btn-sm"
                    onclick="confirmDelete('<?= htmlspecialchars(addslashes($i['id'])) ?>','<?= htmlspecialchars(addslashes($i['title'])) ?>')">Excluir</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── Form modal (Add / Edit) ── -->
<div class="modal-overlay" id="formModal">
  <div class="modal-box modal-form-box">
    <div class="modal-form-header">
      <span id="formModalTitle" class="modal-form-title">Novo item</span>
      <button class="modal-close-btn" onclick="closeFormModal()">✕</button>
    </div>
    <div class="modal-form-body">
      <div id="formModalError" class="alert alert-error" style="display:none;margin-bottom:16px;"></div>
      <form method="post" id="itemForm" action="upstream.php?action=add">
        <div class="form-grid">

          <div class="form-group full">
            <label for="f_title">Título *</label>
            <input type="text" id="f_title" name="title" required placeholder="Nome da demanda / feature">
          </div>

          <div class="form-group full">
            <label for="f_url">Link (CLM / Jira / Confluence)</label>
            <input type="url" id="f_url" name="url" placeholder="https://...">
          </div>

          <div class="form-group">
            <label for="f_phase">Fase</label>
            <select id="f_phase" name="phase" onchange="updateEtapas(this.value)">
              <option value="EPD">EPD</option>
              <option value="ERS">ERS</option>
            </select>
          </div>

          <div class="form-group">
            <label for="f_etapa">Etapa</label>
            <select id="f_etapa" name="etapa">
              <?php foreach ($config['epd_etapas'] as $e): ?>
                <option value="<?= htmlspecialchars($e) ?>"><?= htmlspecialchars($e) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="f_tipo">Tipo</label>
            <select id="f_tipo" name="tipo">
              <option value="">— Selecione —</option>
              <?php foreach ($tipos as $t): ?>
                <option value="<?= $t['key'] ?>"><?= htmlspecialchars($t['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="f_tipo_evolucao">Tipo Evolução</label>
            <select id="f_tipo_evolucao" name="tipo_evolucao">
              <option value="">— Nenhum —</option>
              <?php foreach ($tipos_ev as $t): ?>
                <option value="<?= $t['key'] ?>"><?= htmlspecialchars($t['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="f_pf">PF (Pontos de Função)</label>
            <input type="text" inputmode="decimal" id="f_pf" name="pf" placeholder="—" autocomplete="off">
          </div>

          <div class="form-group">
            <label for="f_horas">Horas <span style="font-weight:300;color:var(--muted)">(PF×12h — editável)</span></label>
            <input type="text" inputmode="decimal" id="f_horas" name="horas" placeholder="—" autocomplete="off">
            <span id="f_horas_hint" style="font-size:11px;color:var(--muted)"></span>
          </div>

          <div class="form-group">
            <label for="f_responsible">Responsável</label>
            <select id="f_responsible" name="responsible">
              <option value="">— Selecione —</option>
              <?php foreach ($resps as $r): ?>
                <option value="<?= $r['name'] ?>"><?= htmlspecialchars($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group full">
            <label for="f_details">Detalhes / Comentário</label>
            <textarea id="f_details" name="details" placeholder="Observações, contexto, links adicionais..."></textarea>
          </div>

        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Salvar item</button>
          <button type="button" class="btn btn-outline" onclick="closeFormModal()">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Delete modal ── -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-box">
    <div class="modal-title">Excluir item</div>
    <div class="modal-body" id="deleteModalBody">Tem certeza?</div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeDeleteModal()">Cancelar</button>
      <form method="post" id="deleteForm" style="display:inline">
        <button type="submit" class="btn btn-danger">Excluir</button>
      </form>
    </div>
  </div>
</div>

<script>
const etapasByPhase = <?= json_encode([
    'EPD' => $config['epd_etapas'],
    'ERS' => $config['ers_etapas'],
]) ?>;

const itemsMap = <?= json_encode($items_map) ?>;
const bounce   = <?= json_encode($bounce) ?>;

const defaultResp = <?= json_encode($resps[0]['name'] ?? 'Naim') ?>;

function updateEtapas(phase, currentEtapa) {
    const sel  = document.getElementById('f_etapa');
    const cur  = currentEtapa !== undefined ? currentEtapa : sel.value;
    const opts = etapasByPhase[phase] || [];
    sel.innerHTML = opts.map(e =>
        `<option value="${e}"${e === cur ? ' selected' : ''}>${e}</option>`
    ).join('');
}

let horasManual = false;
const parseDec = v => parseFloat((v || '').toString().replace(',', '.')) || 0;

document.getElementById('f_pf').addEventListener('input', function () {
    if (horasManual) return;
    const pf = parseDec(this.value);
    const hf = document.getElementById('f_horas');
    hf.value = pf > 0 ? (pf * 12) : '';
    updateHorasHint();
});

document.getElementById('f_horas').addEventListener('input', function () {
    horasManual = true;
    updateHorasHint();
});

function updateHorasHint() {
    const pf   = parseDec(document.getElementById('f_pf').value);
    const h    = parseDec(document.getElementById('f_horas').value);
    const hint = document.getElementById('f_horas_hint');
    if (horasManual && pf > 0 && h !== pf * 12) {
        hint.textContent = 'Manual · auto seria ' + (pf * 12) + 'h';
    } else {
        hint.textContent = '';
    }
}

function populateForm(data) {
    if (!data) return;
    horasManual = false;
    document.getElementById('f_title').value         = data.title         || '';
    document.getElementById('f_url').value           = data.url           || '';
    document.getElementById('f_phase').value         = data.phase         || 'EPD';
    updateEtapas(data.phase || 'EPD', data.etapa || '');
    document.getElementById('f_tipo').value          = data.tipo          || '';
    document.getElementById('f_tipo_evolucao').value = data.tipo_evolucao || '';
    document.getElementById('f_responsible').value   = data.responsible   || '';
    const pf = parseFloat(data.pf) || 0;
    const hr = parseFloat(data.horas) || 0;
    document.getElementById('f_pf').value    = pf > 0 ? pf : '';
    document.getElementById('f_horas').value = hr > 0 ? hr : (pf > 0 ? pf * 12 : '');
    if (hr > 0 && pf > 0 && hr !== pf * 12) horasManual = true;
    document.getElementById('f_details').value = data.details || '';
    updateHorasHint();
}

function openAddModal() {
    document.getElementById('formModalTitle').textContent = 'Novo item';
    document.getElementById('itemForm').action = 'upstream.php?action=add';
    populateForm({ phase: 'EPD', tipo: '', responsible: '' });
    document.getElementById('formModalError').style.display = 'none';
    document.getElementById('formModal').classList.add('open');
    setTimeout(() => document.getElementById('f_title').focus(), 80);
}

function openEditModal(id) {
    const item = itemsMap[id];
    if (!item) return;
    document.getElementById('formModalTitle').textContent = 'Editar item';
    document.getElementById('itemForm').action = `upstream.php?action=edit&id=${encodeURIComponent(id)}`;
    populateForm(item);
    document.getElementById('formModalError').style.display = 'none';
    document.getElementById('formModal').classList.add('open');
    setTimeout(() => document.getElementById('f_title').focus(), 80);
}

function closeFormModal() {
    document.getElementById('formModal').classList.remove('open');
}

function confirmDelete(id, title) {
    document.getElementById('deleteModalBody').textContent =
        `Excluir "${title}"? Esta ação não pode ser desfeita.`;
    document.getElementById('deleteForm').action =
        `upstream.php?action=delete&id=${encodeURIComponent(id)}`;
    document.getElementById('deleteModal').classList.add('open');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('open');
}

document.getElementById('formModal').addEventListener('click', function(e) {
    if (e.target === this) closeFormModal();
});
document.getElementById('deleteModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeFormModal(); closeDeleteModal(); }
});

// Re-open modal after validation bounce
if (bounce) {
    const errEl = document.getElementById('formModalError');
    errEl.textContent = bounce.error;
    errEl.style.display = 'block';
    if (bounce.modal === 'edit' && bounce.id) {
        document.getElementById('formModalTitle').textContent = 'Editar item';
        document.getElementById('itemForm').action =
            `upstream.php?action=edit&id=${encodeURIComponent(bounce.id)}`;
    } else {
        document.getElementById('formModalTitle').textContent = 'Novo item';
        document.getElementById('itemForm').action = 'upstream.php?action=add';
    }
    populateForm(bounce.data);
    document.getElementById('formModal').classList.add('open');
    setTimeout(() => document.getElementById('f_title').focus(), 80);
}
</script>
</body>
</html>
