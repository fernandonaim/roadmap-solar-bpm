<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/data.php';
require_login();

// ── POST action handler ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action']      ?? '';
    $cycle_id = trim($_POST['cycle_id'] ?? '');
    $uid      = trim($_POST['upstream_id'] ?? '');
    $redir    = 'downstream.php' . ($cycle_id ? '?cycle=' . urlencode($cycle_id) : '');

    switch ($action) {
        case 'add_candidatas':
            foreach ((array)($_POST['upstream_ids'] ?? []) as $id) {
                downstream_cycle_add_candidata($cycle_id, trim($id));
            }
            break;
        case 'remove_candidata':
            downstream_cycle_remove_candidata($cycle_id, $uid);
            break;
        case 'prioritize':
        case 'update_hours':
            downstream_cycle_prioritize(
                $cycle_id, $uid,
                (float)($_POST['horas_mes1']      ?? 0),
                (float)($_POST['horas_mes2']      ?? 0),
                (float)($_POST['horas_excedente'] ?? 0),
                trim($_POST['obs'] ?? '')
            );
            break;
        case 'deprioritize':
            downstream_cycle_deprioritize($cycle_id, $uid);
            break;
        case 'update_cycle_status':
            $data = downstream_data();
            $new_status = trim($_POST['status_manual'] ?? '');
            $allowed = ['done', 'current', 'upcoming', ''];
            if (in_array($new_status, $allowed)) {
                foreach ($data['cycles'] as &$cyc) {
                    if ($cyc['id'] === $cycle_id) {
                        if ($new_status === '') unset($cyc['status_manual']);
                        else $cyc['status_manual'] = $new_status;
                        break;
                    }
                }
                downstream_save_data($data);
            }
            header("Location: {$redir}&saved=1"); exit;
        case 'add_cycle':
            $data = downstream_data();
            $new_id = 'ciclo_' . trim($_POST['new_codigo']) . '_' . (int)$_POST['new_ano'];
            $exists = false;
            foreach ($data['cycles'] as $c) { if ($c['id'] === $new_id) { $exists = true; break; } }
            if (!$exists) {
                $new_st = trim($_POST['new_status'] ?? '');
                $entry = ['id' => $new_id, 'codigo' => trim($_POST['new_codigo']), 'ano' => (int)$_POST['new_ano'], 'candidatas' => [], 'priorizadas' => [], 'backlog' => []];
                if (in_array($new_st, ['done','current','upcoming'])) $entry['status_manual'] = $new_st;
                $data['cycles'][] = $entry;
                downstream_save_data($data);
            }
            header('Location: downstream.php?saved=1');
            exit;
    }
    header("Location: {$redir}&saved=1");
    exit;
}

// ── Page state ────────────────────────────────────────────────────────────────
$message      = !empty($_GET['saved']) ? 'Alterações salvas.' : '';
$all_cycles   = downstream_cycles();
$upstream_map = array_column(upstream_all(), null, 'id');
$config       = get_config();

// ── Cycle view ────────────────────────────────────────────────────────────────
$cycle_id = trim($_GET['cycle'] ?? '');
$cycle    = $cycle_id ? downstream_cycle_find($cycle_id) : null;
if ($cycle_id && !$cycle) { header('Location: downstream.php'); exit; }

if ($cycle) {
    $info   = cycle_info($cycle['codigo']);
    $status = cycle_effective_status($cycle);

    $priorizadas_map = [];
    foreach ($cycle['priorizadas'] as $p) $priorizadas_map[$p['upstream_id']] = $p;

    $total_pf   = 0; $total_mes1 = 0; $total_mes2 = 0; $total_exc = 0;
    foreach ($cycle['priorizadas'] as $p) {
        $u = $upstream_map[$p['upstream_id']] ?? null;
        if ($u) $total_pf += (float)($u['pf'] ?? 0);
        $total_mes1 += (float)$p['horas_mes1'];
        $total_mes2 += (float)$p['horas_mes2'];
        $total_exc  += (float)$p['horas_excedente'];
    }
    $total_horas  = $total_mes1 + $total_mes2 + $total_exc;
    $total_meses  = $total_mes1 + $total_mes2;

    $available = downstream_available_upstream($cycle_id);

    // Items with excedente from the previous cycle not yet in this cycle
    $prev_cid   = cycle_prev_id($cycle_id);
    $prev_cycle = $prev_cid ? downstream_cycle_find($prev_cid) : null;
    $excedente_items = [];
    if ($prev_cycle) {
        $prev_info = cycle_info($prev_cycle['codigo']);
        $already   = array_merge(
            $cycle['candidatas'],
            array_column($cycle['priorizadas'], 'upstream_id'),
            $cycle['backlog']
        );
        foreach ($prev_cycle['priorizadas'] as $p) {
            if ((float)$p['horas_excedente'] > 0 && !in_array($p['upstream_id'], $already)) {
                $u = $upstream_map[$p['upstream_id']] ?? null;
                if ($u) $excedente_items[] = [
                    'u'           => $u,
                    'uid'         => $p['upstream_id'],
                    'excedente'   => (float)$p['horas_excedente'],
                    'prev_label'  => $prev_info['label'] . ' ' . $prev_cycle['ano'],
                ];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Downstream — Admin Solar BPM</title>
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
    --accent:  #3b82f6;
    --success: #34d399;
    --danger:  #f87171;
    --warn:    #fb923c;
    --amber:   #f59e0b;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'IBM Plex Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
  input[type=number]::-webkit-inner-spin-button,
  input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
  input[type=number] { -moz-appearance: textfield; appearance: textfield; }

  /* ── Topbar ── */
  .topbar { background: var(--surface); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 28px; height: 52px; }
  .topbar-brand { font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--muted); letter-spacing: 2px; text-transform: uppercase; }
  .topbar-brand span { color: var(--accent); }
  .topbar-right { display: flex; gap: 20px; align-items: center; }
  .topbar-right a { color: var(--muted); text-decoration: none; font-size: 13px; transition: color 0.15s; }
  .topbar-right a:hover { color: var(--text); }
  .topbar-right a.active { color: var(--accent); }

  /* ── Layout ── */
  .main { padding: 32px 28px; max-width: 1400px; margin: 0 auto; }
  .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
  .breadcrumb { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: var(--muted); margin-bottom: 6px; }
  .breadcrumb a { color: var(--accent); text-decoration: none; }
  .breadcrumb a:hover { text-decoration: underline; }
  h1 { font-size: 20px; font-weight: 600; }
  h2 { font-size: 14px; font-weight: 600; }

  /* ── Buttons ── */
  .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 5px; border: 1px solid transparent; font-family: 'IBM Plex Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; transition: opacity 0.15s; white-space: nowrap; }
  .btn:hover { opacity: 0.8; }
  .btn-primary { background: var(--accent); color: #fff; }
  .btn-outline { background: transparent; border-color: var(--border); color: var(--text); }
  .btn-danger  { background: transparent; border-color: rgba(248,113,113,0.4); color: var(--danger); }
  .btn-warn    { background: transparent; border-color: rgba(251,146,60,0.4); color: var(--warn); }
  .btn-success { background: transparent; border-color: rgba(52,211,153,0.4); color: var(--success); }
  .btn-sm { padding: 5px 11px; font-size: 12px; }
  .btn-xs { padding: 3px 8px; font-size: 11px; }

  /* ── Alert ── */
  .alert { padding: 10px 14px; border-radius: 5px; font-size: 13px; margin-bottom: 20px; }
  .alert-success { background: rgba(52,211,153,0.1); border: 1px solid rgba(52,211,153,0.3); color: var(--success); }

  /* ── KPI ── */
  .kpi-row { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 28px; }
  .kpi { background: var(--surface); border: 1px solid var(--border); border-top: 2px solid var(--accent); padding: 14px 18px; min-width: 130px; flex: 1; }
  .kpi.amber { border-top-color: var(--amber); }
  .kpi.green { border-top-color: var(--success); }
  .kpi.warn  { border-top-color: var(--warn); }
  .kpi.danger{ border-top-color: var(--danger); }
  .kpi-label { font-family: 'IBM Plex Mono', monospace; font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
  .kpi-value { font-size: 24px; font-weight: 700; color: #f8fafc; line-height: 1; font-family: 'IBM Plex Mono', monospace; }
  .kpi-sub   { font-size: 11px; color: var(--muted); margin-top: 4px; }

  /* ── Section headers ── */
  .section { margin-bottom: 36px; }
  .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px solid var(--border); flex-wrap: wrap; gap: 8px; }
  .section-title { font-family: 'IBM Plex Mono', monospace; font-size: 10px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); display: flex; align-items: center; gap: 8px; }
  .section-title::before { content: ''; display: inline-block; width: 3px; height: 12px; }
  .section-title.candidatas::before { background: var(--amber); }
  .section-title.priorizadas::before { background: var(--success); }
  .section-title.backlog::before { background: var(--muted); }
  .section-desc { font-size: 12px; color: var(--muted); font-style: italic; }
  .section-count { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: var(--muted); background: var(--border); padding: 2px 8px; border-radius: 3px; }

  /* ── Table ── */
  .table-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  thead th { background: var(--surface); border-bottom: 1px solid var(--border); padding: 10px 12px; text-align: left; font-weight: 500; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); white-space: nowrap; font-family: 'IBM Plex Mono', monospace; }
  thead th.num { text-align: right; }
  tbody tr { border-bottom: 1px solid var(--border); transition: background 0.1s; }
  tbody tr:hover { background: rgba(255,255,255,0.02); }
  tbody td { padding: 10px 12px; vertical-align: middle; }
  tbody td.num { text-align: right; font-family: 'IBM Plex Mono', monospace; font-size: 12px; }
  .td-title { max-width: 340px; }
  .td-title a { color: var(--text); text-decoration: none; font-weight: 500; }
  .td-title a:hover { color: var(--accent); text-decoration: underline; }
  .td-actions { white-space: nowrap; display: flex; gap: 6px; }
  .empty-state { text-align: center; padding: 40px 20px; color: var(--muted); font-size: 13px; font-style: italic; border: 1px dashed var(--border); border-top: none; }

  /* ── Badges ── */
  .badge { display: inline-block; font-size: 10px; font-weight: 500; letter-spacing: 0.5px; padding: 2px 7px; border-radius: 3px; white-space: nowrap; font-family: 'IBM Plex Mono', monospace; }
  .badge.sob      { background: #1c2d4a; color: #60a5fa; border: 1px solid rgba(96,165,250,0.3); }
  .badge.melhoria { background: #1a2e2a; color: #34d399; border: 1px solid rgba(52,211,153,0.3); }
  .badge.sprint   { background: #2d1f3a; color: #c084fc; border: 1px solid rgba(192,132,252,0.3); }
  .badge.implantacao { background: #0f2a2a; color: #2dd4bf; border: 1px solid rgba(45,212,191,0.3); }
  .badge.debito   { background: #2a1a1a; color: #f87171; border: 1px solid rgba(248,113,113,0.3); }
  .badge.custom   { background: #2d1f3a; color: #c084fc; }
  .badge.get      { background: #1a2040; color: #818cf8; }
  .badge.getf     { background: #1a2e2a; color: #34d399; }
  .status-badge { display: inline-flex; align-items: center; gap: 5px; font-family: 'IBM Plex Mono', monospace; font-size: 10px; padding: 3px 9px; border-radius: 3px; font-weight: 500; }
  .status-badge.done     { background: rgba(52,211,153,0.1); border: 1px solid rgba(52,211,153,0.3); color: var(--success); }
  .status-badge.current  { background: rgba(59,130,246,0.15); border: 1px solid rgba(59,130,246,0.4); color: #60a5fa; }
  .status-badge.upcoming { background: var(--surface); border: 1px solid var(--border); color: var(--muted); }

  /* ── Cycle grid (list view) ── */
  .cycle-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
  .cycle-card { background: var(--surface); border: 1px solid var(--border); border-top: 3px solid var(--border); padding: 20px 22px; display: flex; flex-direction: column; gap: 12px; }
  .cycle-card.current  { border-top-color: var(--accent); }
  .cycle-card.done     { border-top-color: var(--success); }
  .cycle-card-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
  .cycle-card-title { font-family: 'IBM Plex Mono', monospace; font-size: 16px; font-weight: 600; letter-spacing: 1px; }
  .cycle-card-period { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: var(--muted); }
  .cycle-card-stats { display: flex; gap: 12px; flex-wrap: wrap; }
  .cycle-stat { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: var(--muted); }
  .cycle-stat strong { color: var(--text); }
  .cycle-card-foot { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }

  /* ── Inline number inputs (priorizadas table) ── */
  .inline-num {
    background: var(--bg); border: 1px solid var(--border); border-radius: 3px;
    color: var(--text); font-family: 'IBM Plex Mono', monospace; font-size: 12px;
    padding: 4px 6px; text-align: right; width: 72px; outline: none;
    transition: border-color 0.15s;
  }
  .inline-num:focus { border-color: var(--accent); }
  .inline-num.mes1:focus { border-color: #60a5fa; }
  .inline-num.mes2:focus { border-color: var(--success); }
  .inline-num.exc:focus  { border-color: var(--warn); }
  td.num-edit { text-align: right; padding: 6px 8px; }

  /* ── Modal base ── */
  .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); align-items: center; justify-content: center; z-index: 100; }
  .modal-overlay.open { display: flex; }
  .modal-box { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 28px; max-width: 440px; width: 94%; }
  .modal-title { font-size: 15px; font-weight: 600; margin-bottom: 10px; }
  .modal-body  { font-size: 13px; color: var(--muted); margin-bottom: 24px; }
  .modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

  /* ── Form modal ── */
  .modal-form-box { max-width: 580px; width: 96%; padding: 0; max-height: 92vh; overflow-y: auto; display: flex; flex-direction: column; }
  .modal-form-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 26px 16px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
  .modal-form-title { font-size: 15px; font-weight: 600; }
  .modal-close-btn { background: none; border: 1px solid var(--border); color: var(--muted); font-size: 16px; cursor: pointer; line-height: 1; padding: 3px 9px; border-radius: 4px; transition: all 0.15s; font-family: 'IBM Plex Mono', monospace; }
  .modal-close-btn:hover { color: var(--text); border-color: var(--muted); }
  .modal-form-body { padding: 22px 26px 26px; flex: 1; }

  /* ── Form fields ── */
  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
  .form-group { display: flex; flex-direction: column; gap: 6px; }
  .form-group.full { grid-column: 1 / -1; }
  .form-group label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
  .form-group input[type=number],
  .form-group input[type=text],
  .form-group select,
  .form-group textarea { background: var(--bg); border: 1px solid var(--border); border-radius: 4px; color: var(--text); font-family: 'IBM Plex Sans', sans-serif; font-size: 13px; padding: 9px 11px; outline: none; transition: border-color 0.15s; width: 100%; }
  .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--accent); }
  .form-group textarea { resize: vertical; min-height: 72px; }
  .form-actions { display: flex; gap: 10px; margin-top: 20px; }
  .form-hint { font-size: 11px; color: var(--muted); margin-top: 4px; }
  .item-title-preview { font-size: 12px; color: var(--text); background: rgba(59,130,246,0.08); border: 1px solid rgba(59,130,246,0.2); padding: 8px 12px; border-radius: 4px; margin-bottom: 16px; line-height: 1.5; }

  /* ── Excedente alert section ── */
  .excedente-section { margin-bottom: 28px; border: 1px solid rgba(251,146,60,0.35); border-left: 3px solid var(--warn); border-radius: 5px; overflow: hidden; }
  .excedente-header { background: rgba(251,146,60,0.08); padding: 10px 16px; display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
  .excedente-header-left { display: flex; align-items: center; gap: 10px; }
  .excedente-icon { font-size: 14px; }
  .excedente-title { font-family: 'IBM Plex Mono', monospace; font-size: 10px; letter-spacing: 2px; text-transform: uppercase; color: var(--warn); }
  .excedente-desc { font-size: 12px; color: var(--muted); }
  .excedente-table table { border: none; }
  .excedente-table thead th { background: rgba(251,146,60,0.04); }
  .excedente-table tbody tr:last-child { border-bottom: none; }

  /* ── Checkbox list ── */
  .check-list { display: flex; flex-direction: column; gap: 2px; max-height: 360px; overflow-y: auto; }
  .check-item { display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; border-radius: 4px; cursor: pointer; transition: background 0.1s; }
  .check-item:hover { background: rgba(59,130,246,0.06); }
  .check-item input[type=checkbox] { margin-top: 2px; accent-color: var(--accent); flex-shrink: 0; width: 15px; height: 15px; }
  .check-item-body { flex: 1; }
  .check-item-title { font-size: 13px; color: var(--text); line-height: 1.4; }
  .check-item-meta  { font-family: 'IBM Plex Mono', monospace; font-size: 10px; color: var(--muted); margin-top: 2px; }
  .check-empty { padding: 24px; text-align: center; color: var(--muted); font-size: 13px; }
</style>
</head>
<body>

<?php include __DIR__ . '/partials/topbar.php'; ?>

<div class="main">

<?php if ($message): ?>
  <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<?php if ($cycle): ?>
<!-- ════════════════════════════════════════════════════════════════════════════
     CYCLE DETAIL VIEW
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <div>
    <div class="breadcrumb"><a href="downstream.php">← Downstream</a> / <?= htmlspecialchars($info['label']) ?> / <?= $cycle['ano'] ?></div>
    <h1><?= htmlspecialchars($info['label']) ?> &mdash; <?= htmlspecialchars($info['periodo']) ?> <?= $cycle['ano'] ?>
      <span style="margin-left:10px">
        <?php $sl = ['done' => 'Concluído', 'current' => 'Atual', 'upcoming' => 'Em breve']; ?>
        <span class="status-badge <?= $status ?>"><?= $sl[$status] ?></span>
      </span>
    </h1>
  </div>
  <form method="post" style="display:flex;align-items:center;gap:8px">
    <input type="hidden" name="action" value="update_cycle_status">
    <input type="hidden" name="cycle_id" value="<?= htmlspecialchars($cycle_id) ?>">
    <select name="status_manual" style="background:var(--bg);border:1px solid var(--border);border-radius:4px;color:var(--text);font-family:'IBM Plex Sans',sans-serif;font-size:13px;padding:7px 10px;outline:none">
      <option value="" <?= empty($cycle['status_manual']) ? 'selected' : '' ?>>Automático (por data)</option>
      <option value="done"     <?= ($cycle['status_manual'] ?? '') === 'done'     ? 'selected' : '' ?>>Concluído</option>
      <option value="current"  <?= ($cycle['status_manual'] ?? '') === 'current'  ? 'selected' : '' ?>>Atual</option>
      <option value="upcoming" <?= ($cycle['status_manual'] ?? '') === 'upcoming' ? 'selected' : '' ?>>Em breve</option>
    </select>
    <button type="submit" class="btn btn-outline btn-sm">Salvar status</button>
  </form>
</div>

<!-- KPIs -->
<div class="kpi-row">
  <div class="kpi amber">
    <div class="kpi-label">Candidatas</div>
    <div class="kpi-value"><?= count($cycle['candidatas']) ?></div>
    <div class="kpi-sub">aguardando SOS</div>
  </div>
  <div class="kpi green">
    <div class="kpi-label">Priorizadas</div>
    <div class="kpi-value"><?= count($cycle['priorizadas']) ?></div>
    <div class="kpi-sub">no ciclo</div>
  </div>
  <div class="kpi amber">
    <div class="kpi-label">Total PF</div>
    <div class="kpi-value"><?= $total_pf > 0 ? fmt_pf($total_pf) : '—' ?></div>
    <div class="kpi-sub">pontos de função</div>
  </div>
  <div class="kpi amber">
    <div class="kpi-label">Total Horas</div>
    <div class="kpi-value"><?= $total_meses > 0 ? number_format($total_meses, 0, ',', '.') . 'h' : '—' ?></div>
    <div class="kpi-sub">previstas</div>
  </div>
  <div class="kpi">
    <div class="kpi-label"><?= htmlspecialchars($info['mes1']) ?></div>
    <div class="kpi-value" style="font-size:20px"><?= $total_mes1 > 0 ? number_format($total_mes1, 0, ',', '.') . 'h' : '—' ?></div>
  </div>
  <div class="kpi green">
    <div class="kpi-label"><?= htmlspecialchars($info['mes2']) ?></div>
    <div class="kpi-value" style="font-size:20px"><?= $total_mes2 > 0 ? number_format($total_mes2, 0, ',', '.') . 'h' : '—' ?></div>
  </div>
  <div class="kpi warn">
    <div class="kpi-label">Excedente</div>
    <div class="kpi-value" style="font-size:20px"><?= $total_exc > 0 ? number_format($total_exc, 0, ',', '.') . 'h' : '—' ?></div>
    <div class="kpi-sub">próximo ciclo</div>
  </div>
</div>

<?php if (!empty($excedente_items)): ?>
<!-- ── EXCEDENTE DO CICLO ANTERIOR ── -->
<div class="excedente-section">
  <div class="excedente-header">
    <div class="excedente-header-left">
      <span class="excedente-icon">⚠</span>
      <div>
        <div class="excedente-title">Excedente do Ciclo Anterior</div>
        <div class="excedente-desc">
          <?= count($excedente_items) === 1 ? '1 demanda' : count($excedente_items) . ' demandas' ?>
          do <strong style="color:var(--text)"><?= htmlspecialchars($excedente_items[0]['prev_label']) ?></strong>
          com horas excedentes — adicione como candidata para este ciclo.
        </div>
      </div>
    </div>
  </div>
  <div class="excedente-table table-wrap">
    <table>
      <thead>
        <tr>
          <th>Demanda</th>
          <th>Tipo</th>
          <th>Resp.</th>
          <th class="num">PF</th>
          <th class="num">Total Horas</th>
          <th class="num" style="color:var(--warn)">Excedente</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($excedente_items as $ei):
          $u    = $ei['u'];
          $pf   = (float)($u['pf'] ?? 0);
          $horas = upstream_horas($u);
        ?>
        <tr>
          <td class="td-title">
            <?php if ($u['url']): ?><a href="<?= htmlspecialchars($u['url']) ?>" target="_blank"><?= htmlspecialchars($u['title']) ?></a>
            <?php else: ?><?= htmlspecialchars($u['title']) ?><?php endif; ?>
          </td>
          <td><span class="badge <?= $u['tipo'] ?>"><?= htmlspecialchars(tipo_label($u['tipo'])) ?></span></td>
          <td style="font-family:'IBM Plex Mono',monospace;font-size:12px;color:<?= responsible_color($u['responsible']) ?>"><?= htmlspecialchars($u['responsible']) ?></td>
          <td class="num"><?= $pf > 0 ? fmt_pf($pf) : '—' ?></td>
          <td class="num"><?= $horas > 0 ? number_format($horas, 0, ',', '.') . 'h' : '—' ?></td>
          <td class="num" style="color:var(--warn);font-weight:600"><?= number_format($ei['excedente'], 0, ',', '.') ?>h</td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="add_candidatas">
              <input type="hidden" name="cycle_id" value="<?= htmlspecialchars($cycle_id) ?>">
              <input type="hidden" name="upstream_ids[]" value="<?= htmlspecialchars($ei['uid']) ?>">
              <button type="submit" class="btn btn-warn btn-xs">+ Candidata</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ── CANDIDATAS ── -->
<div class="section">
  <div class="section-header">
    <div>
      <div class="section-title candidatas">Demandas Candidatas
        <span class="section-count"><?= count($cycle['candidatas']) ?></span>
      </div>
      <div class="section-desc">Candidatas à priorização na reunião de SOS para este ciclo.</div>
    </div>
    <button class="btn btn-primary btn-sm" onclick="openAddModal()">+ Adicionar Candidata</button>
  </div>

  <?php if (empty($cycle['candidatas'])): ?>
    <div class="empty-state">Nenhuma candidata. Adicione demandas aprovadas para avaliar na reunião de SOS.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Demanda</th>
          <th>Tipo</th>
          <th>Evolução</th>
          <th>Resp.</th>
          <th class="num">PF</th>
          <th class="num">Horas</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cycle['candidatas'] as $uid):
          $u = $upstream_map[$uid] ?? null;
          if (!$u) continue;
          $pf = (float)($u['pf'] ?? 0); $horas = upstream_horas($u);
        ?>
        <tr>
          <td class="td-title">
            <?php if ($u['url']): ?><a href="<?= htmlspecialchars($u['url']) ?>" target="_blank"><?= htmlspecialchars($u['title']) ?></a>
            <?php else: ?><?= htmlspecialchars($u['title']) ?><?php endif; ?>
          </td>
          <td><span class="badge <?= $u['tipo'] ?>"><?= htmlspecialchars(tipo_label($u['tipo'])) ?></span></td>
          <td><?php if ($u['tipo_evolucao']): ?><span class="badge <?= $u['tipo_evolucao'] ?>"><?= htmlspecialchars(tipo_evolucao_label($u['tipo_evolucao'])) ?></span><?php else: ?>—<?php endif; ?></td>
          <td style="font-family:'IBM Plex Mono',monospace;font-size:12px;color:<?= responsible_color($u['responsible']) ?>"><?= htmlspecialchars($u['responsible']) ?></td>
          <td class="num"><?= $pf > 0 ? fmt_pf($pf) : '—' ?></td>
          <td class="num"><?= $pf > 0 ? number_format($horas, 0, ',', '.') . 'h' : '—' ?></td>
          <td class="td-actions">
            <button class="btn btn-success btn-xs"
              onclick="openPriorizarModal(<?= htmlspecialchars(json_encode(['id'=>$uid,'title'=>$u['title'],'pf'=>$pf,'horas'=>$horas,'mes1'=>$info['mes1'],'mes2'=>$info['mes2']]), ENT_QUOTES) ?>)">
              Priorizar
            </button>
            <form method="post" style="display:inline" onsubmit="return confirm('Remover esta candidata?')">
              <input type="hidden" name="action" value="remove_candidata">
              <input type="hidden" name="cycle_id" value="<?= htmlspecialchars($cycle_id) ?>">
              <input type="hidden" name="upstream_id" value="<?= htmlspecialchars($uid) ?>">
              <button type="submit" class="btn btn-danger btn-xs">Remover</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ── PRIORIZADAS ── -->
<div class="section">
  <div class="section-header">
    <div>
      <div class="section-title priorizadas">Demandas Priorizadas
        <span class="section-count"><?= count($cycle['priorizadas']) ?></span>
      </div>
      <div class="section-desc">Priorizadas na reunião de SOS — edite as horas diretamente na tabela e salve linha a linha.</div>
    </div>
  </div>

  <?php if (empty($cycle['priorizadas'])): ?>
    <div class="empty-state">Nenhuma demanda priorizada ainda. Promova candidatas após a reunião de SOS.</div>
  <?php else: ?>

  <?php
  /* Hidden forms — uma por linha, referenciadas pelos inputs via HTML5 form= */
  foreach ($cycle['priorizadas'] as $p):
    $fid = 'pf_' . preg_replace('/[^a-z0-9]/i', '_', $p['upstream_id']);
  ?>
  <form method="post" id="<?= $fid ?>">
    <input type="hidden" name="action" value="update_hours">
    <input type="hidden" name="cycle_id" value="<?= htmlspecialchars($cycle_id) ?>">
    <input type="hidden" name="upstream_id" value="<?= htmlspecialchars($p['upstream_id']) ?>">
    <input type="hidden" name="obs" value="<?= htmlspecialchars($p['obs'] ?? '') ?>">
  </form>
  <?php endforeach; ?>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Demanda</th>
          <th>Tipo</th>
          <th class="num">PF</th>
          <th class="num">Total h</th>
          <th class="num"><?= htmlspecialchars($info['mes1']) ?></th>
          <th class="num"><?= htmlspecialchars($info['mes2']) ?></th>
          <th class="num">Excedente</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cycle['priorizadas'] as $p):
          $u   = $upstream_map[$p['upstream_id']] ?? null;
          if (!$u) continue;
          $pf  = (float)($u['pf'] ?? 0);
          $uh  = upstream_horas($u);
          $tot = (float)$p['horas_mes1'] + (float)$p['horas_mes2'];
          $fid = 'pf_' . preg_replace('/[^a-z0-9]/i', '_', $p['upstream_id']);
        ?>
        <tr>
          <td class="td-title">
            <?php if ($u['url']): ?><a href="<?= htmlspecialchars($u['url']) ?>" target="_blank"><?= htmlspecialchars($u['title']) ?></a>
            <?php else: ?><?= htmlspecialchars($u['title']) ?><?php endif; ?>
          </td>
          <td><span class="badge <?= $u['tipo'] ?>"><?= htmlspecialchars(tipo_label($u['tipo'])) ?></span></td>
          <td class="num" style="font-family:'IBM Plex Mono',monospace;font-size:12px"><?= $pf > 0 ? fmt_pf($pf) : '—' ?></td>
          <td class="num" style="font-family:'IBM Plex Mono',monospace;font-size:12px;font-weight:600" id="tot_<?= $fid ?>">
            <?= $tot > 0 ? number_format($tot,0,',','.') . 'h' : '—' ?>
          </td>
          <td class="num-edit">
            <input type="number" name="horas_mes1" form="<?= $fid ?>"
                   value="<?= (float)$p['horas_mes1'] ?: '' ?>"
                   min="0" step="0.5" placeholder="0"
                   class="inline-num mes1"
                   oninput="recalcTotal('<?= $fid ?>')">
          </td>
          <td class="num-edit">
            <input type="number" name="horas_mes2" form="<?= $fid ?>"
                   value="<?= (float)$p['horas_mes2'] ?: '' ?>"
                   min="0" step="0.5" placeholder="0"
                   class="inline-num mes2"
                   oninput="recalcTotal('<?= $fid ?>')">
          </td>
          <td class="num-edit">
            <input type="number" name="horas_excedente" form="<?= $fid ?>"
                   value="<?= (float)$p['horas_excedente'] ?: '' ?>"
                   min="0" step="0.5" placeholder="0"
                   class="inline-num exc"
                   oninput="recalcTotal('<?= $fid ?>')">
          </td>
          <td class="td-actions">
            <button type="submit" form="<?= $fid ?>" class="btn btn-primary btn-xs">Salvar</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Devolver para candidatas?')">
              <input type="hidden" name="action" value="deprioritize">
              <input type="hidden" name="cycle_id" value="<?= htmlspecialchars($cycle_id) ?>">
              <input type="hidden" name="upstream_id" value="<?= htmlspecialchars($p['upstream_id']) ?>">
              <button type="submit" class="btn btn-warn btn-xs">Devolver</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="border-top: 2px solid var(--border);">
          <td colspan="3" style="padding:10px 12px;font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:1px">Totais</td>
          <td style="padding:10px 12px;text-align:right;font-family:'IBM Plex Mono',monospace;font-size:13px;font-weight:600"><?= $total_meses > 0 ? number_format($total_meses,0,',','.') . 'h' : '—' ?></td>
          <td style="padding:10px 8px;text-align:right;font-family:'IBM Plex Mono',monospace;font-size:13px"><?= $total_mes1 > 0 ? number_format($total_mes1,0,',','.') . 'h' : '—' ?></td>
          <td style="padding:10px 8px;text-align:right;font-family:'IBM Plex Mono',monospace;font-size:13px"><?= $total_mes2 > 0 ? number_format($total_mes2,0,',','.') . 'h' : '—' ?></td>
          <td style="padding:10px 8px;text-align:right;font-family:'IBM Plex Mono',monospace;font-size:13px;color:var(--warn)"><?= $total_exc > 0 ? number_format($total_exc,0,',','.') . 'h' : '—' ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- ════════════════════════════════════════════════════════════════════════════
     CYCLE LIST VIEW
     ════════════════════════════════════════════════════════════════════════════ -->
<div class="page-header">
  <h1>Downstream — Ciclos de Entrega</h1>
  <button class="btn btn-outline btn-sm" onclick="openAddCycleModal()">+ Novo ciclo</button>
</div>

<?php
// Group cycles by year for display
$by_year = [];
foreach ($all_cycles as $c) $by_year[$c['ano']][] = $c;
krsort($by_year);
?>

<?php foreach ($by_year as $ano => $cycles_in_year): ?>
<div style="margin-bottom:32px">
  <h2 style="font-family:'IBM Plex Mono',monospace;font-size:12px;letter-spacing:2px;color:var(--muted);text-transform:uppercase;margin-bottom:14px"><?= $ano ?></h2>
  <div class="cycle-grid">
    <?php foreach ($cycles_in_year as $c):
      $ci    = cycle_info($c['codigo']);
      $cstat = cycle_effective_status($c);
      $npri  = count($c['priorizadas']);
      $ncand = count($c['candidatas']);
      $cpf   = 0; $ch = 0;
      foreach ($c['priorizadas'] as $p) {
          $u = $upstream_map[$p['upstream_id']] ?? null;
          if ($u) $cpf += (float)($u['pf'] ?? 0);
          $ch += (float)$p['horas_mes1'] + (float)$p['horas_mes2'];
      }
      $sl = ['done' => 'Concluído', 'current' => 'Atual', 'upcoming' => 'Em breve'];
    ?>
    <div class="cycle-card <?= $cstat ?>">
      <div class="cycle-card-head">
        <div>
          <div class="cycle-card-title"><?= htmlspecialchars($ci['label']) ?></div>
          <div class="cycle-card-period"><?= htmlspecialchars($ci['periodo']) ?> <?= $c['ano'] ?></div>
        </div>
        <span class="status-badge <?= $cstat ?>"><?= $sl[$cstat] ?></span>
      </div>
      <div class="cycle-card-stats">
        <div class="cycle-stat"><strong><?= $ncand ?></strong> candidatas</div>
        <div class="cycle-stat"><strong><?= $npri ?></strong> priorizadas</div>
        <?php if ($cpf > 0): ?><div class="cycle-stat"><strong><?= fmt_pf($cpf) ?></strong> PF</div><?php endif; ?>
        <?php if ($ch > 0): ?><div class="cycle-stat"><strong><?= number_format($ch,0,',','.') ?>h</strong></div><?php endif; ?>
      </div>
      <div class="cycle-card-foot">
        <a href="downstream.php?cycle=<?= urlencode($c['id']) ?>" class="btn btn-primary btn-sm">Gerenciar →</a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if (empty($all_cycles)): ?>
<div style="text-align:center;padding:80px 20px;color:var(--muted)">
  Nenhum ciclo cadastrado. Clique em "Novo ciclo" para começar.
</div>
<?php endif; ?>

<?php endif; ?>
</div><!-- /main -->

<!-- ════════════════ MODALS ════════════════ -->

<!-- Add Candidatas Modal -->
<div class="modal-overlay" id="addModal">
  <div class="modal-box modal-form-box">
    <div class="modal-form-header">
      <span class="modal-form-title">Adicionar Candidatas</span>
      <button class="modal-close-btn" onclick="closeModal('addModal')">✕</button>
    </div>
    <div class="modal-form-body">
      <p style="font-size:12px;color:var(--muted);margin-bottom:10px">
        Demandas com <strong style="color:var(--text)">Especificação Aprovada</strong> disponíveis para este ciclo:
      </p>
      <div style="margin-bottom:12px;position:relative">
        <input type="text" id="candidataSearch" placeholder="Filtrar por título, tipo ou responsável…"
          autocomplete="off"
          style="width:100%;background:var(--bg);border:1px solid var(--border);border-radius:4px;color:var(--text);font-family:'IBM Plex Sans',sans-serif;font-size:13px;padding:8px 32px 8px 11px;outline:none;transition:border-color 0.15s"
          oninput="filterCandidatas(this.value)"
          onfocus="this.style.borderColor='var(--accent)'"
          onblur="this.style.borderColor='var(--border)'">
        <span id="candidataSearchClear" onclick="clearCandidataSearch()"
          style="display:none;position:absolute;right:9px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--muted);font-size:14px;line-height:1;font-family:'IBM Plex Mono',monospace">✕</span>
      </div>
      <form method="post" id="addCandidatasForm">
        <input type="hidden" name="action" value="add_candidatas">
        <input type="hidden" name="cycle_id" value="<?= htmlspecialchars($cycle_id) ?>">
        <?php if (isset($available)):
          if (empty($available)): ?>
          <div class="check-empty">Nenhuma demanda aprovada disponível. Todas já estão neste ciclo ou não foram especificadas.</div>
        <?php else:
          $av_new   = array_filter($available, fn($x) => empty($x['_in_cycles']));
          $av_multi = array_filter($available, fn($x) => !empty($x['_in_cycles']));
        ?>
          <div class="check-list">
            <?php foreach ($av_new as $av):
              $av_pf      = (float)($av['pf'] ?? 0);
              $av_search  = strtolower($av['title'] . ' ' . tipo_label($av['tipo']) . ' ' . $av['responsible']);
            ?>
            <label class="check-item" data-search="<?= htmlspecialchars($av_search) ?>">
              <input type="checkbox" name="upstream_ids[]" value="<?= htmlspecialchars($av['id']) ?>">
              <div class="check-item-body">
                <div class="check-item-title"><?= htmlspecialchars($av['title']) ?></div>
                <div class="check-item-meta">
                  <?= htmlspecialchars(tipo_label($av['tipo'])) ?>
                  <?php if ($av['responsible']): ?> · <?= htmlspecialchars($av['responsible']) ?><?php endif; ?>
                  <?php if ($av_pf > 0): ?> · PF <?= fmt_pf($av_pf) ?> · <?= number_format($av_pf*12,0,',','.') ?>h<?php endif; ?>
                </div>
                <?php if (!empty($av['_cand_cycles'])): ?>
                <div class="check-item-meta" style="color:#6366f1;margin-top:2px">
                  Candidata em: <?= htmlspecialchars(implode(', ', $av['_cand_cycles'])) ?>
                </div>
                <?php endif; ?>
              </div>
            </label>
            <?php endforeach; ?>

            <?php if (!empty($av_multi)): ?>
            <div class="candidata-section-sep" style="padding:8px 10px 4px;font-family:'IBM Plex Mono',monospace;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--warn);border-top:1px solid var(--border);margin-top:4px">
              Já Priorizadas em outro ciclo
            </div>
            <?php foreach ($av_multi as $av):
              $av_pf      = (float)($av['pf'] ?? 0);
              $av_search  = strtolower($av['title'] . ' ' . tipo_label($av['tipo']) . ' ' . $av['responsible'] . ' ' . implode(' ', $av['_in_cycles']));
            ?>
            <label class="check-item" data-search="<?= htmlspecialchars($av_search) ?>" data-multicycle="1" style="border-left:2px solid rgba(251,146,60,0.4);margin-left:2px">
              <input type="checkbox" name="upstream_ids[]" value="<?= htmlspecialchars($av['id']) ?>">
              <div class="check-item-body">
                <div class="check-item-title"><?= htmlspecialchars($av['title']) ?></div>
                <div class="check-item-meta">
                  <?= htmlspecialchars(tipo_label($av['tipo'])) ?>
                  <?php if ($av['responsible']): ?> · <?= htmlspecialchars($av['responsible']) ?><?php endif; ?>
                  <?php if ($av_pf > 0): ?> · PF <?= fmt_pf($av_pf) ?> · <?= number_format($av_pf*12,0,',','.') ?>h<?php endif; ?>
                  <span style="color:var(--warn)"> · Priorizada em: <?= htmlspecialchars(implode(', ', $av['_in_cycles'])) ?></span>
                </div>
                <?php if (!empty($av['_cand_cycles'])): ?>
                <div class="check-item-meta" style="color:#6366f1;margin-top:2px">
                  Candidata em: <?= htmlspecialchars(implode(', ', $av['_cand_cycles'])) ?>
                </div>
                <?php endif; ?>
              </div>
            </label>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn btn-primary">Adicionar selecionadas</button>
            <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Cancelar</button>
          </div>
        <?php endif; endif; ?>
      </form>
    </div>
  </div>
</div>

<!-- Priorizar Modal -->
<div class="modal-overlay" id="priorizarModal">
  <div class="modal-box modal-form-box">
    <div class="modal-form-header">
      <span class="modal-form-title">Priorizar Demanda</span>
      <button class="modal-close-btn" onclick="closeModal('priorizarModal')">✕</button>
    </div>
    <div class="modal-form-body">
      <div id="priorizarTitlePreview" class="item-title-preview"></div>
      <form method="post" id="priorizarForm">
        <input type="hidden" name="action" value="prioritize">
        <input type="hidden" name="cycle_id" value="<?= htmlspecialchars($cycle_id) ?>">
        <input type="hidden" name="upstream_id" id="priorizar_uid">
        <div style="display:flex;gap:16px;margin-bottom:16px">
          <div style="flex:1">
            <div class="form-group"><label>PF</label><input type="text" id="priorizar_pf" readonly style="color:var(--muted)"></div>
          </div>
          <div style="flex:1">
            <div class="form-group"><label>Total Horas (PF×12h)</label><input type="text" id="priorizar_horas" readonly style="color:var(--muted)"></div>
          </div>
        </div>
        <div class="form-grid-3">
          <div class="form-group">
            <label id="priorizar_mes1_label">Mês 1 (h)</label>
            <input type="number" name="horas_mes1" id="priorizar_mes1" min="0" step="0.5" placeholder="0" oninput="updatePriorizarTotal()">
          </div>
          <div class="form-group">
            <label id="priorizar_mes2_label">Mês 2 (h)</label>
            <input type="number" name="horas_mes2" id="priorizar_mes2" min="0" step="0.5" placeholder="0" oninput="updatePriorizarTotal()">
          </div>
          <div class="form-group">
            <label>Excedente (h)</label>
            <input type="number" name="horas_excedente" id="priorizar_exc" min="0" step="0.5" placeholder="0" oninput="updatePriorizarTotal()">
          </div>
        </div>
        <div class="form-hint" id="priorizar_total_hint" style="margin-top:8px;margin-bottom:16px"></div>
        <div class="form-group" style="margin-top:8px">
          <label>Observações</label>
          <textarea name="obs" id="priorizar_obs" placeholder="Notas sobre a priorização..."></textarea>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Confirmar priorização</button>
          <button type="button" class="btn btn-outline" onclick="closeModal('priorizarModal')">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Cycle Modal -->
<div class="modal-overlay" id="addCycleModal">
  <div class="modal-box" style="max-width:380px">
    <div class="modal-title">Novo Ciclo</div>
    <div class="modal-body">
      <form method="post" id="addCycleForm">
        <input type="hidden" name="action" value="add_cycle">
        <div class="form-grid">
          <div class="form-group">
            <label>Código</label>
            <select name="new_codigo">
              <option value="02">02 — Jan/Fev</option>
              <option value="04">04 — Mar/Abr</option>
              <option value="06">06 — Mai/Jun</option>
              <option value="08">08 — Jul/Ago</option>
              <option value="10">10 — Set/Out</option>
              <option value="12">12 — Nov/Dez</option>
            </select>
          </div>
          <div class="form-group">
            <label>Ano</label>
            <input type="number" name="new_ano" value="<?= date('Y') ?>" min="2024" max="2040">
          </div>
        </div>
        <div class="form-group" style="margin-top:12px">
          <label>Status</label>
          <select name="new_status">
            <option value="">Automático (por data)</option>
            <option value="done">Concluído</option>
            <option value="current">Atual</option>
            <option value="upcoming">Em breve</option>
          </select>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">Criar ciclo</button>
          <button type="button" class="btn btn-outline" onclick="closeModal('addCycleModal')">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openAddCycleModal() { openModal('addCycleModal'); }

/* ── Priorizar modal ── */
function openPriorizarModal(data) {
    document.getElementById('priorizarTitlePreview').textContent = data.title;
    document.getElementById('priorizar_uid').value               = data.id;
    document.getElementById('priorizar_pf').value                = data.pf > 0 ? data.pf : '—';
    document.getElementById('priorizar_horas').value             = data.horas > 0 ? data.horas + 'h' : '—';
    document.getElementById('priorizar_mes1_label').textContent  = (data.mes1 || 'Mês 1') + ' (h)';
    document.getElementById('priorizar_mes2_label').textContent  = (data.mes2 || 'Mês 2') + ' (h)';
    document.getElementById('priorizar_mes1').value = '';
    document.getElementById('priorizar_mes2').value = '';
    document.getElementById('priorizar_exc').value  = '';
    document.getElementById('priorizar_obs').value  = '';
    updatePriorizarTotal();
    openModal('priorizarModal');
    setTimeout(() => document.getElementById('priorizar_mes1').focus(), 80);
}

function updatePriorizarTotal() {
    const m1  = parseFloat(document.getElementById('priorizar_mes1').value) || 0;
    const m2  = parseFloat(document.getElementById('priorizar_mes2').value) || 0;
    const ex  = parseFloat(document.getElementById('priorizar_exc').value)  || 0;
    const tot = m1 + m2 + ex;
    document.getElementById('priorizar_total_hint').textContent =
        tot > 0 ? 'Total: ' + tot.toLocaleString('pt-BR') + 'h · Mês 1: ' + m1 + 'h · Mês 2: ' + m2 + 'h · Excedente: ' + ex + 'h' : '';
}

/* ── Inline recalc (priorizadas table) ── */
function recalcTotal(fid) {
    const row  = document.querySelector('[form="' + fid + '"].mes1').closest('tr');
    const m1   = parseFloat(row.querySelector('.mes1').value) || 0;
    const m2   = parseFloat(row.querySelector('.mes2').value) || 0;
    const tot  = m1 + m2;
    const cell = document.getElementById('tot_' + fid);
    if (cell) cell.textContent = tot > 0 ? tot.toLocaleString('pt-BR') + 'h' : '—';
}

/* ── Candidatas search filter ── */
function filterCandidatas(q) {
    const term  = q.trim().toLowerCase();
    const items = document.querySelectorAll('#addModal .check-list .check-item');
    const sep   = document.querySelector('#addModal .candidata-section-sep');
    const clear = document.getElementById('candidataSearchClear');

    if (clear) clear.style.display = term ? 'block' : 'none';

    let visNew = 0, visMulti = 0;
    items.forEach(function(el) {
        const match = !term || el.dataset.search.includes(term);
        el.style.display = match ? '' : 'none';
        if (match) {
            if (el.dataset.multicycle) visMulti++; else visNew++;
        }
    });

    if (sep) sep.style.display = (!term || visMulti > 0) ? '' : 'none';
}

function clearCandidataSearch() {
    const inp = document.getElementById('candidataSearch');
    if (inp) { inp.value = ''; inp.focus(); }
    filterCandidatas('');
}

function openAddModal() {
    const inp = document.getElementById('candidataSearch');
    if (inp) { inp.value = ''; }
    filterCandidatas('');
    openModal('addModal');
    setTimeout(() => { if (inp) inp.focus(); }, 80);
}

/* ── Close modals ── */
['addModal','priorizarModal','addCycleModal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('click', function(e) { if (e.target === this) closeModal(id); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') ['addModal','priorizarModal','addCycleModal'].forEach(id => closeModal(id));
});
</script>
</body>
</html>
