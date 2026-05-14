<?php
require_once __DIR__ . '/includes/auth.php';
auth_start();

$action = $_GET['action'] ?? '';
$id     = $_GET['id']     ?? '';
$login_error = '';

// ── Login ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    if (do_login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '')) {
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
    $login_error = 'Usuário ou senha inválidos.';
}

// ── Logout ────────────────────────────────────────────────────────────────────
if ($action === 'logout') {
    do_logout();
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

// ── Admin CRUD (require login) ────────────────────────────────────────────────
if (is_logged_in()) {
    if ($action === 'delete' && $id && $_SERVER['REQUEST_METHOD'] === 'POST') {
        upstream_delete($id);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?deleted=1'); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit'])) {
        $fields = [
            'title'         => trim($_POST['title']         ?? ''),
            'url'           => trim($_POST['url']           ?? ''),
            'phase'         => $_POST['phase']              ?? 'EPD',
            'etapa'         => trim($_POST['etapa']         ?? ''),
            'tipo'          => $_POST['tipo']               ?? '',
            'tipo_evolucao' => $_POST['tipo_evolucao']      ?? '',
            'responsible'   => $_POST['responsible']        ?? '',
            'pf'            => max(0, (float)str_replace(',', '.', $_POST['pf']    ?? '0')),
            'horas'         => max(0, (float)str_replace(',', '.', $_POST['horas'] ?? '0')),
            'details'       => trim($_POST['details']       ?? ''),
        ];
        $err = '';
        if (empty($fields['title']))  $err = 'O título é obrigatório.';
        elseif (empty($fields['tipo'])) $err = 'Selecione o Tipo.';
        if ($err) {
            $_SESSION['_bounce'] = ['error' => $err, 'data' => $fields, 'modal' => $action, 'id' => $id];
            header('Location: ' . $_SERVER['PHP_SELF']); exit;
        }
        if ($action === 'add')             upstream_create($fields);
        elseif ($action === 'edit' && $id) upstream_update($id, $fields);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?saved=1'); exit;
    }
}

$bounce    = $_SESSION['_bounce'] ?? null;
unset($_SESSION['_bounce']);
$logged_in = is_logged_in();

$notify = '';
if (!empty($_GET['saved']))   $notify = 'Item salvo com sucesso.';
if (!empty($_GET['deleted'])) $notify = 'Item removido.';

$items      = upstream_all();
$config     = get_config();
$resps      = $config['responsibles']   ?? [];
$epd_etapas = $config['epd_etapas']     ?? [];
$ers_etapas = $config['ers_etapas']     ?? [];
$tipos      = $config['tipos']          ?? [];
$tipos_ev   = $config['tipos_evolucao'] ?? [];

// IDs priorizados em algum ciclo downstream (apenas priorizadas — candidatas ainda aparecem no upstream)
$cycle_priorizados = [];
foreach (downstream_cycles() as $_dc)
    foreach ($_dc['priorizadas'] as $_p) $cycle_priorizados[$_p['upstream_id']] = true;
// Oculta do upstream itens ERS/Especificação Aprovada que já foram priorizados
$items = array_values(array_filter($items, function($i) use ($cycle_priorizados) {
    if ($i['phase'] === 'ERS' && $i['etapa'] === 'Especificação Aprovada' && isset($cycle_priorizados[$i['id']])) return false;
    return true;
}));

usort($items, function($a, $b) use ($epd_etapas, $ers_etapas) {
    if ($a['phase'] !== $b['phase']) return $a['phase'] === 'EPD' ? -1 : 1;
    $order = array_flip($a['phase'] === 'EPD' ? $epd_etapas : $ers_etapas);
    return ($order[$a['etapa']] ?? 99) - ($order[$b['etapa']] ?? 99);
});

function group_by_etapa(array $items, string $phase, array $etapas): array {
    $result = [];
    foreach ($etapas as $e)
        $result[$e] = array_values(array_filter($items, fn($i) => $i['phase'] === $phase && $i['etapa'] === $e));
    return $result;
}

$epd_grouped = group_by_etapa($items, 'EPD', $epd_etapas);
$ers_grouped = group_by_etapa($items, 'ERS', $ers_etapas);
$color_map   = array_column($resps, 'color', 'name');
$epd_count   = count(array_filter($items, fn($i) => $i['phase'] === 'EPD'));
$ers_count   = count(array_filter($items, fn($i) => $i['phase'] === 'ERS'));
$items_map   = array_column($items, null, 'id');
$table_cols  = $logged_in ? 10 : 9;

$ev_counts = ['custom' => 0, 'get' => 0, 'getf' => 0];
$tipo_counts = [];
foreach ($items as $i) {
    if ($i['tipo_evolucao']) $ev_counts[$i['tipo_evolucao']] = ($ev_counts[$i['tipo_evolucao']] ?? 0) + 1;
    $tipo_counts[$i['tipo']] = ($tipo_counts[$i['tipo']] ?? 0) + 1;
}
$ev_segs = [
    ['label'=>'Customizada','key'=>'custom','value'=>$ev_counts['custom'],'color'=>'#c084fc'],
    ['label'=>'GET',        'key'=>'get',   'value'=>$ev_counts['get'],   'color'=>'#38bdf8'],
    ['label'=>'GETF',       'key'=>'getf',  'value'=>$ev_counts['getf'],  'color'=>'#86efac'],
];
$tipo_colors = ['sob'=>'#60a5fa','melhoria'=>'#34d399','sprint'=>'#a78bfa','implantacao'=>'#2dd4bf','debito'=>'#f87171'];
$tipo_labels = ['sob'=>'Sob Demanda','melhoria'=>'Melhoria','sprint'=>'Sprint','implantacao'=>'Implantação','debito'=>'Débito'];
$tipo_segs = [];
foreach ($tipo_colors as $k => $c) {
    $v = $tipo_counts[$k] ?? 0;
    if ($v > 0) $tipo_segs[] = ['label'=>$tipo_labels[$k],'key'=>$k,'value'=>$v,'color'=>$c];
}

function make_donut(array $segments, int $r = 40, int $sw = 16): string {
    $total = array_sum(array_column($segments, 'value'));
    $lpad  = 32; $cx = $r + (int)($sw/2) + $lpad; $cy = $cx; $size = $cx * 2;
    if ($total === 0) {
        return sprintf('<svg width="%d" height="%d" viewBox="0 0 %d %d"><circle cx="%d" cy="%d" r="%d" fill="none" stroke="#1f2937" stroke-width="%d"/></svg>', $size, $size, $size, $size, $cx, $cy, $r, $sw);
    }
    $circ = 2 * M_PI * $r; $rot = -90; $parts = [];
    foreach ($segments as $seg) {
        if ($seg['value'] <= 0) continue;
        $f = $seg['value'] / $total;
        $parts[] = sprintf('<circle cx="%d" cy="%d" r="%d" fill="none" stroke="%s" stroke-width="%d" stroke-dasharray="%.3f %.3f" transform="rotate(%.3f %d %d)"/>',
            $cx, $cy, $r, $seg['color'], $sw, $f*$circ, (1-$f)*$circ, $rot, $cx, $cy);
        $mid = deg2rad($rot + $f * 180);
        $lr  = $r + (int)($sw/2) + 22;
        $pct = round($f * 100);
        if ($pct >= 7) $parts[] = sprintf('<text x="%.1f" y="%.1f" text-anchor="middle" dominant-baseline="central" font-family="IBM Plex Mono,monospace" font-size="13" font-weight="600" fill="%s">%d%%</text>',
            $cx + $lr*cos($mid), $cy + $lr*sin($mid), $seg['color'], $pct);
        $rot += $f * 360;
    }
    return sprintf('<svg width="%d" height="%d" viewBox="0 0 %d %d">%s</svg>', $size, $size, $size, $size, implode('', $parts));
}

$upstream_map_pub = array_column(upstream_all(), null, 'id');
$sb_map   = ['done' => ['sb' => 'sb-done', 'label' => 'Concluído'],
             'current' => ['sb' => 'sb-curr', 'label' => 'Atual'],
             'upcoming' => ['sb' => 'sb-soon', 'label' => 'Em breve']];
$cycles = [];
foreach (downstream_cycles() as $raw) {
    $ci  = cycle_info($raw['codigo']);
    $st  = cycle_effective_status($raw);
    $cycles[] = [
        'id'         => $raw['id'],
        'num'        => $raw['codigo'],
        'title'      => $ci['label'],
        'period'     => $ci['periodo'] . ' ' . $raw['ano'],
        'sb'         => $sb_map[$st]['sb'],
        'label'      => $sb_map[$st]['label'],
        'mes1'       => $ci['mes1'],
        'mes2'       => $ci['mes2'],
        'ano'        => $raw['ano'],
        'candidatas' => $raw['candidatas'],
        'priorizadas'=> $raw['priorizadas'],
        'status'     => $st,
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Roadmap Solar BPM</title>
<link rel="icon" type="image/png" href="simbolo_Processos_Digitais.png">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;600;700&display=swap" rel="stylesheet"/>
<style>
  :root {
    --bg: #0e1117; --surface: #161b27; --border: #1f2937;
    --accent: #f59e0b; --accent2: #3b82f6; --accent3: #10b981;
    --danger: #ef4444; --text: #e2e8f0; --muted: #64748b;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: 'IBM Plex Sans', sans-serif; font-size: 15px; min-height: 100vh; display: flex; flex-direction: column; }

  /* ── Notify bar ── */
  .notify-bar { padding: 8px 40px; background: rgba(52,211,153,0.08); border-bottom: 1px solid rgba(52,211,153,0.2); color: #34d399; font-family: 'IBM Plex Mono', monospace; font-size: 13px; letter-spacing: 1px; text-align: center; }

  /* ── Layout ── */
  .page-wrap { display: flex; flex: 1; }

  /* ── Sidebar ── */
  .sidebar-nav { width: 200px; min-width: 200px; background: #111827; border-right: 1px solid var(--border); display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; overflow-y: auto; overflow-x: hidden; transition: width 0.22s ease, min-width 0.22s ease; flex-shrink: 0; z-index: 30; }
  .sidebar-nav.collapsed { width: 48px; min-width: 48px; }
  .sidebar-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 10px 14px 14px; border-bottom: 1px solid var(--border); flex-shrink: 0; min-height: 52px; }
  .sidebar-title { font-family: 'IBM Plex Mono', monospace; font-size: 9px; letter-spacing: 2px; color: var(--muted); text-transform: uppercase; white-space: nowrap; overflow: hidden; opacity: 1; transition: opacity 0.15s; }
  .sidebar-nav.collapsed .sidebar-title { opacity: 0; width: 0; }
  .sidebar-toggle { background: #1f2937; border: 1px solid #374151; color: #94a3b8; cursor: pointer; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 11px; border-radius: 3px; font-family: 'IBM Plex Mono', monospace; transition: all 0.15s; }
  .sidebar-toggle:hover { background: #374151; color: #e2e8f0; }
  .sidebar-body { flex: 1; padding: 8px 0; overflow: hidden; }
  .sidebar-group-label { font-family: 'IBM Plex Mono', monospace; font-size: 9px; letter-spacing: 2px; text-transform: uppercase; padding: 10px 14px 3px; display: flex; align-items: center; gap: 7px; white-space: nowrap; overflow: hidden; }
  .sidebar-nav.collapsed .sidebar-group-label { justify-content: center; padding: 10px 0 3px; }
  .sidebar-group-label .dot { width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
  .sidebar-group-label.up { color: var(--accent); }
  .sidebar-group-label.up .dot { background: var(--accent); }
  .sidebar-group-label.dw { color: var(--accent2); margin-top: 6px; }
  .sidebar-group-label.dw .dot { background: var(--accent2); }
  .group-label-text { opacity: 1; transition: opacity 0.15s; }
  .sidebar-nav.collapsed .group-label-text { opacity: 0; width: 0; overflow: hidden; }
  .sidebar-divider { border: none; border-top: 1px solid var(--border); margin: 6px 12px; }
  .sidebar-item { display: flex; align-items: center; gap: 10px; padding: 7px 14px; cursor: pointer; border-left: 3px solid transparent; transition: background 0.15s, border-color 0.15s; overflow: hidden; white-space: nowrap; }
  .sidebar-nav.collapsed .sidebar-item { padding: 7px 0; justify-content: center; border-left-width: 0; }
  .sidebar-item:hover { background: #1f2937; }
  .sidebar-item.active { background: #161c2c; }
  .sidebar-item.active.up-item { border-left-color: var(--accent); }
  .sidebar-item.active.dw-item { border-left-color: var(--accent2); }
  .sidebar-icon { font-family: 'IBM Plex Mono', monospace; font-size: 11px; font-weight: 700; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border-radius: 3px; }
  .up-item .sidebar-icon { background: #2a1f0a; color: var(--accent); }
  .dw-item .sidebar-icon { background: #0f1e36; color: var(--accent2); }
  .sidebar-item-text { display: flex; flex-direction: column; gap: 1px; opacity: 1; transition: opacity 0.15s; overflow: hidden; flex: 1; }
  .sidebar-nav.collapsed .sidebar-item-text { opacity: 0; width: 0; flex: 0; }
  .sidebar-item-title { font-family: 'IBM Plex Mono', monospace; font-size: 12px; font-weight: 600; color: var(--text); letter-spacing: 0.3px; }
  .sidebar-item-sub { font-size: 11px; color: var(--muted); }
  .sidebar-item.active.up-item .sidebar-item-title { color: var(--accent); }
  .sidebar-item.active.dw-item .sidebar-item-title { color: var(--accent2); }
  .sidebar-badge { font-family: 'IBM Plex Mono', monospace; font-size: 9px; padding: 1px 5px; border-radius: 2px; white-space: nowrap; margin-left: auto; flex-shrink: 0; opacity: 1; transition: opacity 0.15s; }
  .sidebar-nav.collapsed .sidebar-badge { opacity: 0; width: 0; overflow: hidden; padding: 0; }
  .sb-done { background: #0f2a0f; color: #4ade80; }
  .sb-curr { background: #0f1e36; color: #60a5fa; }
  .sb-soon { background: #1f2937; color: #475569; }
  .sb-up   { background: #2a1f0a; color: var(--accent); }
  .sidebar-footer { border-top: 1px solid var(--border); padding: 12px 14px; display: flex; flex-direction: column; gap: 2px; flex-shrink: 0; overflow: hidden; }
  .sidebar-footer-label { font-family: 'IBM Plex Mono', monospace; font-size: 9px; letter-spacing: 1.5px; text-transform: uppercase; color: #374151; white-space: nowrap; }
  .sidebar-footer-date  { font-family: 'IBM Plex Mono', monospace; font-size: 11px; font-weight: 600; color: var(--muted); white-space: nowrap; }
  .sidebar-nav.collapsed .sidebar-footer-label,
  .sidebar-nav.collapsed .sidebar-footer-date { opacity: 0; }

  /* ── Main ── */
  .main-content { flex: 1; min-width: 0; display: flex; flex-direction: column; }
  .top-bar { display: flex; justify-content: space-between; align-items: center; padding: 24px 40px 0; background: var(--bg); }
  .top-bar-logo img { height: 70px; width: auto; display: block; }
  .top-bar-right { display: flex; align-items: center; gap: 12px; }
  .top-bar-meta { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: var(--muted); letter-spacing: 1px; }

  /* ── Admin top controls ── */
  .admin-login-btn { background: none; border: 1px solid #1e2535; color: #374151; cursor: pointer; padding: 3px 10px; font-size: 12px; font-family: 'IBM Plex Mono', monospace; transition: all 0.15s; letter-spacing: 0.5px; }
  .admin-login-btn:hover { border-color: var(--muted); color: var(--muted); }
  .admin-new-btn { background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.35); color: var(--accent); cursor: pointer; padding: 5px 14px; font-size: 12px; font-family: 'IBM Plex Mono', monospace; font-weight: 600; transition: all 0.15s; letter-spacing: 0.5px; }
  .admin-new-btn:hover { background: rgba(245,158,11,0.18); }
  .admin-user { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: var(--muted); }
  .admin-logout { background: none; border: 1px solid var(--border); color: var(--muted); cursor: pointer; padding: 4px 10px; font-size: 11px; font-family: 'IBM Plex Mono', monospace; transition: all 0.15s; text-decoration: none; display: inline-flex; align-items: center; }
  .admin-logout:hover { border-color: var(--danger); color: var(--danger); }

  /* ── Panels ── */
  .cycle-panel { display: none; }
  .cycle-panel.active { display: block; padding: 40px; }
  .panel-header { display: flex; flex-direction: column; gap: 4px; padding-bottom: 20px; margin-bottom: 28px; border-bottom: 1px solid var(--border); }
  .panel-header-label { font-family: 'IBM Plex Mono', monospace; font-size: 10px; letter-spacing: 3px; text-transform: uppercase; color: var(--accent2); }
  .panel-header-title { font-family: 'IBM Plex Mono', monospace; font-size: 23px; font-weight: 700; color: var(--text); letter-spacing: 1px; display: flex; align-items: baseline; gap: 12px; }
  .panel-header-period { font-size: 14px; font-weight: 400; color: var(--muted); letter-spacing: 1px; }
  .upstream-panel-header .panel-header-title { color: var(--accent); }

  /* ── Cards / Grid ── */
  .card { background: var(--surface); border: 1px solid var(--border); padding: 20px 22px; }
  .card-title { font-family: 'IBM Plex Mono', monospace; font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
  .card-title::before { content: ''; display: inline-block; width: 3px; height: 12px; background: var(--accent); }
  .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 20px; }
  .grid   { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
  .full   { grid-column: 1 / -1; }

  /* ── Tables ── */
  table { width: 100%; border-collapse: collapse; }
  thead tr { border-bottom: 1px solid var(--border); }
  th { font-family: 'IBM Plex Mono', monospace; font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--muted); padding: 8px 10px; text-align: left; }
  th.num { text-align: right; }
  tbody tr { border-bottom: 1px solid #1a2030; transition: background 0.15s; }
  tbody tr:hover { background: #1a2230; }
  td { padding: 10px 10px; font-size: 14px; vertical-align: middle; }
  td.num { text-align: right; font-family: 'IBM Plex Mono', monospace; font-size: 13px; }

  /* ── Badges ── */
  .badge { display: inline-block; font-family: 'IBM Plex Mono', monospace; font-size: 11px; padding: 2px 8px; font-weight: 600; }
  .badge.epd        { background: #0f2233; color: #38bdf8; }
  .badge.ers        { background: #1a1208; color: #fb923c; }
  .badge.sob        { background: #1c2d4a; color: #60a5fa; }
  .badge.melhoria   { background: #1a2e2a; color: #34d399; }
  .badge.sprint     { background: #2d1f3a; color: #a78bfa; }
  .badge.implantacao{ background: #0f2a2a; color: #2dd4bf; }
  .badge.debito     { background: #2a1a1a; color: #f87171; }
  .badge.custom     { background: #1f1635; color: #c084fc; }
  .badge.get        { background: #0f2233; color: #38bdf8; }
  .badge.getf       { background: #1a2410; color: #86efac; }
  .etapa-badge { display: inline-block; font-family: 'IBM Plex Mono', monospace; font-size: 11px; padding: 2px 8px; font-weight: 600; background: #111827; color: #6b7280; white-space: nowrap; }

  /* ── Links ── */
  .demand-link { color: var(--text); text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
  .demand-link:hover { color: var(--accent2); }
  .demand-link::after { content: '↗'; font-size: 11px; color: var(--muted); flex-shrink: 0; }
  .demand-link:hover::after { color: var(--accent2); }

  /* ── Comment toggle ── */
  .comment-toggle { background: none; border: 1px solid var(--border); color: var(--muted); font-family: 'IBM Plex Mono', monospace; font-size: 11px; padding: 3px 8px; cursor: pointer; letter-spacing: 1px; transition: all 0.15s; white-space: nowrap; }
  .comment-toggle:hover { border-color: var(--accent); color: var(--accent); }
  .comment-toggle.open  { border-color: var(--accent); color: var(--accent); background: #1a1508; }
  .comment-row          { display: none; }
  .comment-row.visible  { display: table-row; }
  .comment-cell { padding: 0 10px 12px 10px !important; background: #0f1520; border-bottom: 1px solid var(--border) !important; }
  .comment-inner { border-left: 3px solid var(--accent); padding: 10px 14px; background: #13192a; }
  .comment-label { font-family: 'IBM Plex Mono', monospace; font-size: 11px; letter-spacing: 1.5px; text-transform: uppercase; color: var(--accent); margin-bottom: 8px; }

  /* ── Legend ── */
  .legend-title { font-family: 'IBM Plex Mono', monospace; font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
  .legend-title::before { content: ''; display: inline-block; width: 3px; height: 12px; background: var(--accent2); }
  .legend-list { display: grid; grid-template-columns: auto 1fr; column-gap: 14px; align-items: start; }
  .legend-list > :not(.legend-sep) { padding: 10px 0; }
  .legend-list > :nth-child(1), .legend-list > :nth-child(2) { padding-top: 0; }
  .legend-list > :nth-last-child(1), .legend-list > :nth-last-child(2) { padding-bottom: 0; }
  .legend-list .badge { white-space: nowrap; }
  .legend-sep { grid-column: 1 / -1; border: none; border-top: 1px solid var(--border); margin: 0; }
  .legend-desc { font-size: 13px; color: #94a3b8; line-height: 1.5; }
  .legend-desc strong { color: var(--text); display: block; margin-bottom: 2px; font-size: 14px; }

  /* ── Donut chart ── */
  .donut-wrap { display: flex; align-items: center; gap: 20px; }
  .donut-wrap svg { flex-shrink: 0; }
  .donut-legend { display: flex; flex-direction: column; gap: 9px; }
  .donut-item { display: flex; align-items: center; gap: 8px; font-size: 13px; }
  .donut-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
  .donut-count { font-family: 'IBM Plex Mono', monospace; font-size: 12px; color: var(--muted); margin-left: auto; padding-left: 12px; }

  /* ── Filter + toggle row ── */
  .filter-toggle-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
  .resp-filter-bar { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
  .resp-filter-label { font-family: 'IBM Plex Mono', monospace; font-size: 10px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); margin-right: 4px; }
  .resp-btn { font-family: 'IBM Plex Mono', monospace; font-size: 11px; font-weight: 600; padding: 4px 12px; border: 1px solid var(--border); background: none; cursor: pointer; transition: all 0.15s; }
  .resp-btn[data-resp="todos"]          { color: var(--muted); }
  .resp-btn[data-resp="todos"].active   { border-color: var(--muted); color: var(--text); background: #1a1f2e; }
  .resp-btn[data-resp="Naim"].active    { border-color: #60a5fa; background: #0d1f36; color: #60a5fa; }
  .resp-btn[data-resp="Michael"].active { border-color: #818cf8; background: #13152e; color: #818cf8; }
  .resp-btn[data-resp="Vitorino"].active{ border-color: #34d399; background: #0b2318; color: #34d399; }
  .resp-btn[data-resp="Claudio"].active { border-color: #fb923c; background: #2a1608; color: #fb923c; }
  .resp-btn:hover { opacity: 0.8; }
  .upstream-toggle { display: flex; gap: 0; }
  .view-btn { font-family: 'IBM Plex Mono', monospace; font-size: 11px; letter-spacing: 1px; text-transform: uppercase; padding: 6px 14px; border: 1px solid var(--border); background: none; color: var(--muted); cursor: pointer; transition: all 0.15s; }
  .view-btn + .view-btn { border-left: none; }
  .view-btn.active { border-color: var(--accent); color: var(--accent); background: #1a1508; }
  .view-btn:hover  { color: var(--text); }

  /* ── View containers ── */
  .upstream-view        { display: none; }
  .upstream-view.active { display: block; }
  .table-view-wrap { overflow-x: auto; }

  /* ── Kanban 2-column ── */
  .kanban-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .kanban-phase-col { border: 1px solid var(--border); background: #0a0e18; }
  .kanban-phase-header {
    padding: 10px 14px; border-bottom: 1px solid var(--border);
    font-family: 'IBM Plex Mono', monospace; font-size: 13px;
    letter-spacing: 1px; text-transform: uppercase; color: var(--muted);
    display: flex; justify-content: space-between; align-items: center;
  }
  .kanban-phase-header.epd { border-top: 2px solid #38bdf8; }
  .kanban-phase-header.ers { border-top: 2px solid #fb923c; }
  .kanban-phase-count { font-family: 'IBM Plex Mono', monospace; font-size: 11px; font-weight: 700; background: var(--border); color: #ffffff; padding: 1px 7px; }

  /* ── Etapa sections ── */
  .etapa-section { border-bottom: 1px solid #141c28; }
  .etapa-section:last-child { border-bottom: none; }
  .etapa-section-header {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 14px; cursor: pointer;
    font-family: 'IBM Plex Mono', monospace; font-size: 12px;
    letter-spacing: 0.5px; text-transform: uppercase;
    color: #38bdf8; background: #0d1320;
    user-select: none; transition: opacity 0.15s;
  }
  .etapa-section-header:hover { opacity: 0.85; }
  .etapa-toggle { font-size: 11px; transition: transform 0.2s; display: inline-block; flex-shrink: 0; }
  .etapa-toggle.closed { transform: rotate(-90deg); }
  .etapa-section-count { margin-left: auto; background: #1f2937; color: #ffffff; font-size: 10px; font-weight: 700; padding: 1px 5px; }
  .etapa-section-cards { display: flex; flex-direction: column; gap: 6px; padding: 8px 8px 8px 32px; }
  .etapa-section-cards.collapsed { display: none; }

  /* ── Kanban cards ── */
  .kanban-card { background: var(--surface); border: 1px solid var(--border); border-left: 3px solid #2a3448; transition: background 0.1s; }
  .kanban-card:hover { background: #1a2030; border-left-color: var(--accent2); }
  .kanban-card-body { padding: 9px 12px; }
  .kanban-card-name { font-size: 13px; color: var(--text); line-height: 1.4; margin-bottom: 6px; display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
  .kanban-card-name .name-text { flex: 1; }
  .kanban-card-name a { color: var(--text); text-decoration: none; }
  .kanban-card-name a:hover { color: var(--accent2); }
  .kanban-card-name a::after { content: ' ↗'; font-size: 10px; color: var(--muted); }
  .kanban-card-meta { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
  .kanban-resp { font-family: 'IBM Plex Mono', monospace; font-size: 11px; font-weight: 700; margin-left: auto; }
  .kanban-empty { padding: 10px 14px; font-size: 12px; color: #374151; font-family: 'IBM Plex Mono', monospace; }
  .pf-chip { font-family: 'IBM Plex Mono', monospace; font-size: 10px; color: var(--muted); background: var(--border); padding: 1px 6px; border-radius: 2px; white-space: nowrap; }

  /* ── Downstream panel sections ── */
  .dw-section { margin-bottom: 32px; }
  .dw-section-header { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
  .dw-section-dot { width: 3px; height: 14px; border-radius: 2px; flex-shrink: 0; }
  .dw-section-dot.cand { background: var(--accent); }
  .dw-section-dot.prio { background: var(--accent3); }
  .dw-section-title { font-family: 'IBM Plex Mono', monospace; font-size: 12px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); }
  .dw-section-title.cand { color: var(--accent); }
  .dw-section-title.prio { color: var(--accent3); }
  .dw-section-count { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: var(--muted); background: var(--border); padding: 1px 7px; border-radius: 3px; margin-left: 6px; }
  .dw-section-count.cand { color: var(--accent); background: rgba(59,130,246,0.12); }
  .dw-section-count.prio { color: var(--accent3); background: rgba(52,211,153,0.12); }
  .dw-section-desc { font-size: 12px; color: var(--muted); font-style: italic; margin-top: 3px; }
  .dw-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .dw-table thead th { background: var(--surface); border-bottom: 1px solid var(--border); padding: 8px 12px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); font-family: 'IBM Plex Mono', monospace; white-space: nowrap; }
  .dw-table thead th.r { text-align: right; }
  .dw-table tbody tr { border-bottom: 1px solid var(--border); }
  .dw-table tbody tr:last-child { border-bottom: none; }
  .dw-table tbody td { padding: 9px 12px; vertical-align: middle; }
  .dw-table tbody td.r { text-align: right; font-family: 'IBM Plex Mono', monospace; font-size: 12px; }
  .dw-table tfoot td { padding: 8px 12px; font-family: 'IBM Plex Mono', monospace; font-size: 12px; vertical-align: middle; }
  .dw-table tfoot td.r { text-align: right; }
  .dw-table .td-title a { color: var(--text); text-decoration: none; font-weight: 500; }
  .dw-table .td-title a:hover { color: var(--accent2); }
  .dw-empty { padding: 24px; text-align: center; color: var(--muted); font-size: 13px; font-style: italic; border: 1px dashed var(--border); }
  .dw-h-chips { display: flex; gap: 6px; flex-wrap: wrap; }
  .dw-h-chip { font-family: 'IBM Plex Mono', monospace; font-size: 10px; padding: 1px 6px; border-radius: 2px; white-space: nowrap; }
  .dw-h-chip.m1 { background: rgba(96,165,250,0.15); color: #60a5fa; }
  .dw-h-chip.m2 { background: rgba(16,185,129,0.15); color: var(--accent3); }
  .dw-h-chip.ex { background: rgba(245,158,11,0.15); color: var(--accent); }

  /* ── Card details toggle ── */
  .card-detail-toggle { display: block; width: 100%; background: none; border: none; border-top: 1px solid var(--border); color: var(--muted); font-family: 'IBM Plex Mono', monospace; font-size: 10px; letter-spacing: 0.5px; padding: 5px 12px; cursor: pointer; text-align: left; transition: color 0.15s; }
  .card-detail-toggle:hover, .card-detail-toggle.open { color: var(--accent); }
  .card-detail-panel { display: none; border-top: 1px solid var(--border); padding: 10px 12px; background: #0f1520; }
  .card-detail-panel.open { display: block; }
  .card-detail-inner { border-left: 2px solid var(--accent); padding: 6px 10px; font-size: 12px; color: var(--muted); line-height: 1.6; white-space: pre-wrap; }

  /* ── Card admin actions ── */
  .card-admin-bar { display: flex; gap: 4px; flex-shrink: 0; }
  .card-admin-btn { background: none; border: 1px solid #1e2535; color: #374151; font-family: 'IBM Plex Mono', monospace; font-size: 11px; padding: 2px 8px; cursor: pointer; transition: all 0.15s; }
  .card-admin-btn.edit:hover   { border-color: var(--accent2); color: var(--accent2); }
  .card-admin-btn.delete:hover { border-color: var(--danger);  color: var(--danger); }

  /* ── Table admin actions ── */
  .td-actions { white-space: nowrap; display: flex; gap: 5px; }
  .tbl-btn { background: none; border: 1px solid #1e2535; color: #374151; font-family: 'IBM Plex Mono', monospace; font-size: 11px; padding: 3px 8px; cursor: pointer; transition: all 0.15s; }
  .tbl-btn.edit:hover   { border-color: var(--accent2); color: var(--accent2); }
  .tbl-btn.delete:hover { border-color: var(--danger);  color: var(--danger); }

  /* ── Empty state ── */
  .empty-state { display: flex; align-items: center; justify-content: center; height: 320px; color: var(--muted); font-family: 'IBM Plex Mono', monospace; font-size: 14px; border: 1px dashed var(--border); letter-spacing: 1px; }

  /* ── Footer ── */
  footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid var(--border); font-size: 12px; color: var(--muted); font-family: 'IBM Plex Mono', monospace; display: flex; justify-content: space-between; }

  /* ── Modal base ── */
  .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.78); align-items: center; justify-content: center; z-index: 200; }
  .modal-overlay.open { display: flex; }

  /* ── Login modal ── */
  .modal-login { max-width: 360px; width: 92%; background: var(--surface); border: 1px solid var(--border); padding: 30px 28px 26px; }
  .login-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 22px; }
  .login-logo img { height: 70px; width: auto; display: block; }
  .login-sub { font-family: 'IBM Plex Mono', monospace; font-size: 11px; letter-spacing: 2px; text-transform: uppercase; color: var(--muted); margin-top: 4px; }
  .modal-x { background: none; border: 1px solid var(--border); color: var(--muted); font-size: 15px; cursor: pointer; padding: 2px 8px; font-family: 'IBM Plex Mono', monospace; transition: all 0.15s; }
  .modal-x:hover { border-color: var(--muted); color: var(--text); }
  .login-error { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #f87171; font-size: 13px; padding: 8px 12px; margin-bottom: 16px; display: none; font-family: 'IBM Plex Mono', monospace; }
  .login-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
  .login-field label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; font-family: 'IBM Plex Mono', monospace; }
  .login-field input { background: #0a0e18; border: 1px solid var(--border); color: var(--text); font-family: 'IBM Plex Sans', sans-serif; font-size: 15px; padding: 10px 12px; outline: none; transition: border-color 0.15s; width: 100%; }
  .login-field input:focus { border-color: var(--accent); }
  .login-submit { width: 100%; padding: 11px; background: var(--accent); border: none; color: #0e1117; font-family: 'IBM Plex Sans', sans-serif; font-size: 15px; font-weight: 600; cursor: pointer; transition: opacity 0.15s; margin-top: 6px; }
  .login-submit:hover { opacity: 0.85; }

  /* ── Form modal (add/edit) ── */
  .modal-form { max-width: 700px; width: 96%; background: var(--surface); border: 1px solid var(--border); max-height: 92vh; overflow-y: auto; display: flex; flex-direction: column; }
  .modal-form-hd { display: flex; align-items: center; justify-content: space-between; padding: 18px 26px 16px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
  .modal-form-title { font-size: 17px; font-weight: 600; font-family: 'IBM Plex Mono', monospace; letter-spacing: 0.5px; }
  .modal-form-bd { padding: 22px 26px 26px; }
  .form-error { background: rgba(248,113,113,0.1); border: 1px solid rgba(248,113,113,0.3); color: #f87171; font-size: 13px; padding: 8px 12px; margin-bottom: 16px; display: none; font-family: 'IBM Plex Mono', monospace; }
  .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .form-group { display: flex; flex-direction: column; gap: 6px; }
  .form-group.full { grid-column: 1 / -1; }
  .form-group label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
  .form-group input, .form-group select, .form-group textarea { background: #0a0e18; border: 1px solid var(--border); color: var(--text); font-family: 'IBM Plex Sans', sans-serif; font-size: 14px; padding: 9px 11px; outline: none; transition: border-color 0.15s; width: 100%; }
  input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
  input[type=number] { -moz-appearance: textfield; appearance: textfield; }
  .form-hint-small { font-size: 11px; color: var(--muted); margin-top: 3px; }
  .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--accent); }
  .form-group textarea { resize: vertical; min-height: 80px; }
  .form-actions { display: flex; gap: 10px; margin-top: 20px; }
  .btn-save { background: var(--accent); border: none; color: #0e1117; font-family: 'IBM Plex Sans', sans-serif; font-size: 14px; font-weight: 600; padding: 9px 20px; cursor: pointer; transition: opacity 0.15s; }
  .btn-save:hover { opacity: 0.85; }
  .btn-cancel { background: none; border: 1px solid var(--border); color: var(--text); font-family: 'IBM Plex Sans', sans-serif; font-size: 14px; padding: 9px 16px; cursor: pointer; transition: all 0.15s; }
  .btn-cancel:hover { border-color: var(--muted); }

  /* ── Delete confirm modal ── */
  .modal-confirm { max-width: 400px; width: 92%; background: var(--surface); border: 1px solid var(--border); padding: 28px; }
  .modal-confirm-title { font-size: 16px; font-weight: 600; margin-bottom: 10px; }
  .modal-confirm-body { font-size: 14px; color: var(--muted); margin-bottom: 24px; line-height: 1.5; }
  .modal-confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
  .btn-danger { background: none; border: 1px solid rgba(248,113,113,0.4); color: #f87171; font-family: 'IBM Plex Sans', sans-serif; font-size: 14px; padding: 8px 16px; cursor: pointer; transition: all 0.15s; }
  .btn-danger:hover { background: rgba(248,113,113,0.1); }
  .btn-outline-sm { background: none; border: 1px solid var(--border); color: var(--text); font-family: 'IBM Plex Sans', sans-serif; font-size: 14px; padding: 8px 16px; cursor: pointer; transition: all 0.15s; }
  .btn-outline-sm:hover { border-color: var(--muted); }

  /* ── Responsive ── */
  @media (max-width: 900px) { .kanban-two-col { grid-template-columns: 1fr; } }
  @media (max-width: 768px) {
    .page-wrap { flex-direction: column; }
    .sidebar-nav { width: 100% !important; min-width: unset !important; height: auto; position: relative; }
    .sidebar-body { display: flex; flex-direction: row; flex-wrap: wrap; padding: 4px 8px; }
    .sidebar-item { padding: 5px 8px; border-left: none; border-bottom: 2px solid transparent; }
    .sidebar-item.active.up-item { border-bottom-color: var(--accent); border-left-color: transparent; }
    .sidebar-item.active.dw-item { border-bottom-color: var(--accent2); border-left-color: transparent; }
    .sidebar-item-sub, .sidebar-badge { display: none; }
    .cycle-panel.active { padding: 16px; }
    .grid-3 { grid-template-columns: 1fr; }
    .filter-toggle-row { flex-direction: column; align-items: flex-start; }
    .form-grid { grid-template-columns: 1fr; }
    .form-group.full { grid-column: 1; }
  }
</style>
</head>
<body>

<?php if ($notify): ?>
<div class="notify-bar" id="notifyBar"><?= htmlspecialchars($notify) ?></div>
<?php endif; ?>

<div class="page-wrap">

<!-- Sidebar -->
<nav class="sidebar-nav" id="sidebar-nav">
  <div class="sidebar-header">
    <span class="sidebar-title">Roadmap Solar BPM</span>
    <button class="sidebar-toggle" id="sidebar-toggle" onclick="toggleSidebar()" title="Retrair menu">◀</button>
  </div>
  <div class="sidebar-body">
    <div class="sidebar-group-label up"><div class="dot"></div><span class="group-label-text">Upstream</span></div>
    <div class="sidebar-item up-item active" onclick="showCycle('upstream', this)">
      <div class="sidebar-icon">UP</div>
      <div class="sidebar-item-text">
        <span class="sidebar-item-title">Pipeline</span>
        <span class="sidebar-item-sub">Produto · EPD &amp; ERS</span>
      </div>
      <span class="sidebar-badge sb-up">Ativo</span>
    </div>
    <hr class="sidebar-divider">
    <div class="sidebar-group-label dw"><div class="dot"></div><span class="group-label-text">Downstream</span></div>
    <div class="sidebar-item dw-item" onclick="showCycle('dw-all', this)">
      <div class="sidebar-icon" style="font-size:9px">ALL</div>
      <div class="sidebar-item-text">
        <span class="sidebar-item-title">Todas as Demandas</span>
        <span class="sidebar-item-sub">Visão consolidada</span>
      </div>
    </div>
    <?php foreach ($cycles as $c): ?>
    <div class="sidebar-item dw-item" onclick="showCycle('<?= $c['id'] ?>', this)">
      <div class="sidebar-icon"><?= $c['num'] ?></div>
      <div class="sidebar-item-text">
        <span class="sidebar-item-title"><?= $c['title'] ?></span>
        <span class="sidebar-item-sub"><?= $c['period'] ?></span>
      </div>
      <span class="sidebar-badge <?= $c['sb'] ?>"><?= $c['label'] ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="sidebar-footer">
    <span class="sidebar-footer-label">Atualizado em</span>
    <span class="sidebar-footer-date"><?= date('d M Y') ?></span>
  </div>
</nav>

<!-- Main -->
<div class="main-content">
  <div class="top-bar">
    <div class="top-bar-logo"><img src="logo_solarBPM.png" alt="Solar BPM"></div>
    <div class="top-bar-right">
      <div class="top-bar-meta">SOLAR BPM · PRODUTO · ROADMAP 2026</div>
      <?php if ($logged_in): ?>
        <button class="admin-new-btn" onclick="openAddModal()">+ Novo item</button>
        <span class="admin-user">Olá, <?= htmlspecialchars(current_user()) ?></span>
        <a href="?action=logout" class="admin-logout">Sair</a>
      <?php else: ?>
        <button class="admin-login-btn" onclick="openLoginModal()">⚙ Admin</button>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── UPSTREAM ── -->
  <div class="cycle-panel active" id="upstream">

    <div class="panel-header upstream-panel-header">
      <div class="panel-header-label">Pipeline</div>
      <div class="panel-header-title">Upstream <span class="panel-header-period">EPD &amp; ERS</span></div>
    </div>

    <!-- 3-col: legend + 2 donuts -->
    <div class="grid-3">

      <div class="card">
        <div class="legend-title">Legenda — Tipo Evolução</div>
        <div class="legend-list">
          <span class="badge custom">Customizada</span>
          <div class="legend-desc">Demanda exclusiva para o cliente solicitante.</div>
          <hr class="legend-sep">
          <span class="badge get">GET</span>
          <div class="legend-desc"><strong>Garantia de Evolução Tecnológica</strong>Entregue a todos os clientes.</div>
          <hr class="legend-sep">
          <span class="badge getf">GETF</span>
          <div class="legend-desc"><strong>Garantia de Evolução Tecnológica e Funcional</strong>Disponível para clientes com plano GETF.</div>
        </div>
      </div>

      <div class="card">
        <div class="legend-title">Tipo Evolução (Upstream)</div>
        <div class="donut-wrap">
          <?= make_donut($ev_segs) ?>
          <div class="donut-legend">
            <?php foreach ($ev_segs as $s): ?>
            <div class="donut-item">
              <div class="donut-dot" style="background:<?= $s['color'] ?>"></div>
              <span><?= $s['label'] ?></span>
              <span class="donut-count"><?= $s['value'] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="legend-title">Tipo de Demanda (Upstream)</div>
        <div class="donut-wrap">
          <?= make_donut($tipo_segs) ?>
          <div class="donut-legend">
            <?php foreach ($tipo_segs as $s): ?>
            <div class="donut-item">
              <div class="donut-dot" style="background:<?= $s['color'] ?>"></div>
              <span><?= $s['label'] ?></span>
              <span class="donut-count"><?= $s['value'] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </div>

    <!-- Filter + Toggle row -->
    <div class="filter-toggle-row">
      <div class="resp-filter-bar">
        <span class="resp-filter-label">Filtrar:</span>
        <button class="resp-btn active" data-resp="todos" onclick="filterResp(this)">Todos</button>
        <?php foreach ($resps as $r): ?>
        <button class="resp-btn" data-resp="<?= htmlspecialchars($r['name']) ?>"
                onclick="filterResp(this)" style="color:<?= $r['color'] ?>">
          <?= htmlspecialchars($r['name']) ?>
        </button>
        <?php endforeach; ?>
        <div style="position:relative;margin-left:8px">
          <input type="text" id="upstreamNameSearch" placeholder="Buscar demanda…"
            autocomplete="off"
            style="background:var(--surface);border:1px solid var(--border);border-radius:4px;color:var(--text);font-family:'IBM Plex Sans',sans-serif;font-size:12px;padding:4px 26px 4px 10px;outline:none;width:190px;transition:border-color 0.15s"
            oninput="applyUpstreamFilters()"
            onfocus="this.style.borderColor='var(--accent)'"
            onblur="this.style.borderColor='var(--border)'">
          <span id="upstreamNameClear" onclick="clearUpstreamSearch()"
            style="display:none;position:absolute;right:7px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--muted);font-size:13px;font-family:'IBM Plex Mono',monospace;line-height:1">✕</span>
        </div>
      </div>
      <div class="upstream-toggle">
        <button class="view-btn active" onclick="setUpstreamView('kanban', this)">■ Kanban</button>
        <button class="view-btn" onclick="setUpstreamView('table', this)">≡ Tabela</button>
      </div>
    </div>

    <!-- ── KANBAN VIEW ── -->
    <div class="upstream-view active" id="pip-kanban">
      <div class="kanban-two-col">

        <!-- EPD column -->
        <div class="kanban-phase-col">
          <div class="kanban-phase-header epd">
            <span>EPD — Especificação de Produto e Design</span>
            <span class="kanban-phase-count"><?= $epd_count ?></span>
          </div>
          <?php foreach ($epd_grouped as $etapa => $cards): ?>
          <div class="etapa-section">
            <div class="etapa-section-header" onclick="toggleEtapa(this)">
              <span class="etapa-toggle">▼</span>
              <?= htmlspecialchars($etapa) ?>
              <span class="etapa-section-count"><?= count($cards) ?></span>
            </div>
            <div class="etapa-section-cards">
              <?php if (empty($cards)): ?>
                <div class="kanban-empty">—</div>
              <?php else: foreach ($cards as $card): ?>
              <div class="kanban-card" data-resp="<?= htmlspecialchars($card['responsible']) ?>" data-title="<?= htmlspecialchars(strtolower($card['title'])) ?>">
                <div class="kanban-card-body">
                  <div class="kanban-card-name">
                    <span class="name-text">
                      <?php if ($card['url']): ?>
                        <a href="<?= htmlspecialchars($card['url']) ?>" target="_blank"><?= htmlspecialchars($card['title']) ?></a>
                      <?php else: ?>
                        <?= htmlspecialchars($card['title']) ?>
                      <?php endif; ?>
                    </span>
                    <?php if ($logged_in): ?>
                    <div class="card-admin-bar">
                      <button class="card-admin-btn edit" onclick="openEditModal('<?= addslashes($card['id']) ?>')">✎</button>
                      <button class="card-admin-btn delete" onclick="confirmDelete('<?= addslashes($card['id']) ?>','<?= addslashes($card['title']) ?>')">✕</button>
                    </div>
                    <?php endif; ?>
                  </div>
                  <div class="kanban-card-meta">
                    <span class="badge <?= $card['tipo'] ?>"><?= htmlspecialchars(tipo_label($card['tipo'])) ?></span>
                    <?php if ($card['tipo_evolucao']): ?>
                    <span class="badge <?= $card['tipo_evolucao'] ?>"><?= htmlspecialchars(tipo_evolucao_label($card['tipo_evolucao'])) ?></span>
                    <?php endif; ?>
                    <?php $cpf = (float)($card['pf'] ?? 0); $ch = upstream_horas($card); ?>
                    <?php if ($ch > 0): ?>
                    <span class="pf-chip">PF <?= $cpf > 0 ? fmt_pf($cpf) : '?' ?> · <?= number_format($ch,0,',','.') ?>h</span>
                    <?php endif; ?>
                    <span class="kanban-resp" style="color:<?= $color_map[$card['responsible']] ?? '#888' ?>">
                      <?= htmlspecialchars($card['responsible']) ?>
                    </span>
                  </div>
                </div>
                <?php if (!empty($card['details'])): ?>
                <div class="card-detail-panel" id="cdp_<?= htmlspecialchars($card['id']) ?>">
                  <div class="card-detail-inner"><?= htmlspecialchars($card['details']) ?></div>
                </div>
                <button class="card-detail-toggle" onclick="toggleCardDetail('<?= htmlspecialchars($card['id']) ?>', this)">+ detalhes</button>
                <?php endif; ?>
              </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- ERS column -->
        <div class="kanban-phase-col">
          <div class="kanban-phase-header ers">
            <span>ERS — Especificação de Requisitos de Software</span>
            <span class="kanban-phase-count"><?= $ers_count ?></span>
          </div>
          <?php foreach ($ers_grouped as $etapa => $cards): ?>
          <div class="etapa-section">
            <div class="etapa-section-header" onclick="toggleEtapa(this)" style="color:#fb923c">
              <span class="etapa-toggle">▼</span>
              <?= htmlspecialchars($etapa) ?>
              <span class="etapa-section-count"><?= count($cards) ?></span>
            </div>
            <div class="etapa-section-cards">
              <?php if (empty($cards)): ?>
                <div class="kanban-empty">—</div>
              <?php else: foreach ($cards as $card): ?>
              <div class="kanban-card" data-resp="<?= htmlspecialchars($card['responsible']) ?>" data-title="<?= htmlspecialchars(strtolower($card['title'])) ?>">
                <div class="kanban-card-body">
                  <div class="kanban-card-name">
                    <span class="name-text">
                      <?php if ($card['url']): ?>
                        <a href="<?= htmlspecialchars($card['url']) ?>" target="_blank"><?= htmlspecialchars($card['title']) ?></a>
                      <?php else: ?>
                        <?= htmlspecialchars($card['title']) ?>
                      <?php endif; ?>
                    </span>
                    <?php if ($logged_in): ?>
                    <div class="card-admin-bar">
                      <button class="card-admin-btn edit" onclick="openEditModal('<?= addslashes($card['id']) ?>')">✎</button>
                      <button class="card-admin-btn delete" onclick="confirmDelete('<?= addslashes($card['id']) ?>','<?= addslashes($card['title']) ?>')">✕</button>
                    </div>
                    <?php endif; ?>
                  </div>
                  <div class="kanban-card-meta">
                    <span class="badge <?= $card['tipo'] ?>"><?= htmlspecialchars(tipo_label($card['tipo'])) ?></span>
                    <?php if ($card['tipo_evolucao']): ?>
                    <span class="badge <?= $card['tipo_evolucao'] ?>"><?= htmlspecialchars(tipo_evolucao_label($card['tipo_evolucao'])) ?></span>
                    <?php endif; ?>
                    <?php $cpf = (float)($card['pf'] ?? 0); $ch = upstream_horas($card); ?>
                    <?php if ($ch > 0): ?>
                    <span class="pf-chip">PF <?= $cpf > 0 ? fmt_pf($cpf) : '?' ?> · <?= number_format($ch,0,',','.') ?>h</span>
                    <?php endif; ?>
                    <span class="kanban-resp" style="color:<?= $color_map[$card['responsible']] ?? '#888' ?>">
                      <?= htmlspecialchars($card['responsible']) ?>
                    </span>
                  </div>
                </div>
                <?php if (!empty($card['details'])): ?>
                <div class="card-detail-panel" id="cdp_<?= htmlspecialchars($card['id']) ?>">
                  <div class="card-detail-inner"><?= htmlspecialchars($card['details']) ?></div>
                </div>
                <button class="card-detail-toggle" onclick="toggleCardDetail('<?= htmlspecialchars($card['id']) ?>', this)">+ detalhes</button>
                <?php endif; ?>
              </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

      </div>
    </div><!-- /pip-kanban -->

    <!-- ── TABLE VIEW ── -->
    <div class="upstream-view" id="pip-table">
      <div class="table-view-wrap">
        <table>
          <thead>
            <tr>
              <th></th>
              <th>Demanda</th>
              <th>Fase</th>
              <th>Etapa</th>
              <th>Tipo</th>
              <th>Evolução</th>
              <th style="text-align:right">PF</th>
              <th style="text-align:right">Horas</th>
              <th>Resp.</th>
              <?php if ($logged_in): ?><th>Ações</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $item): $cid = htmlspecialchars($item['id']); ?>
            <tr class="item-row" data-resp="<?= htmlspecialchars($item['responsible']) ?>" data-title="<?= htmlspecialchars(strtolower($item['title'])) ?>">
              <td>
                <?php if ($item['details']): ?>
                <button class="comment-toggle" onclick="toggleComment('<?= $cid ?>', this)">+ detalhes</button>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($item['url']): ?>
                  <a class="demand-link" href="<?= htmlspecialchars($item['url']) ?>" target="_blank"><?= htmlspecialchars($item['title']) ?></a>
                <?php else: ?>
                  <?= htmlspecialchars($item['title']) ?>
                <?php endif; ?>
              </td>
              <td><span class="badge <?= strtolower($item['phase']) ?>"><?= $item['phase'] ?></span></td>
              <td><span class="etapa-badge"><?= htmlspecialchars($item['etapa']) ?></span></td>
              <td><span class="badge <?= $item['tipo'] ?>"><?= htmlspecialchars(tipo_label($item['tipo'])) ?></span></td>
              <td>
                <?php if ($item['tipo_evolucao']): ?>
                  <span class="badge <?= $item['tipo_evolucao'] ?>"><?= htmlspecialchars(tipo_evolucao_label($item['tipo_evolucao'])) ?></span>
                <?php else: ?>—<?php endif; ?>
              </td>
              <?php $ipf = (float)($item['pf'] ?? 0); $ih = upstream_horas($item); ?>
              <td style="text-align:right;font-family:'IBM Plex Mono',monospace;font-size:12px;color:<?= $ipf ? 'var(--text)' : 'var(--muted)' ?>">
                <?= $ipf ? fmt_pf($ipf) : '—' ?>
              </td>
              <td style="text-align:right;font-family:'IBM Plex Mono',monospace;font-size:12px;color:<?= $ih ? 'var(--text)' : 'var(--muted)' ?>">
                <?= $ih ? number_format($ih, 0, ',', '.') . 'h' : '—' ?>
              </td>
              <td>
                <span style="font-family:'IBM Plex Mono',monospace;font-size:12px;font-weight:600;color:<?= $color_map[$item['responsible']] ?? '#888' ?>;">
                  <?= htmlspecialchars($item['responsible']) ?>
                </span>
              </td>
              <?php if ($logged_in): ?>
              <td>
                <div class="td-actions">
                  <button class="tbl-btn edit" onclick="openEditModal('<?= addslashes($item['id']) ?>')">Editar</button>
                  <button class="tbl-btn delete" onclick="confirmDelete('<?= addslashes($item['id']) ?>','<?= addslashes($item['title']) ?>')">Excluir</button>
                </div>
              </td>
              <?php endif; ?>
            </tr>
            <?php if ($item['details']): ?>
            <tr class="comment-row" id="<?= $cid ?>">
              <td colspan="<?= $table_cols ?>" class="comment-cell">
                <div class="comment-inner">
                  <div class="comment-label">Observações</div>
                  <p style="font-size:14px;color:#94a3b8;line-height:1.6;"><?= nl2br(htmlspecialchars($item['details'])) ?></p>
                </div>
              </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php if (empty($items)): ?>
            <tr><td colspan="<?= $table_cols ?>" style="text-align:center;padding:40px;color:var(--muted);font-family:'IBM Plex Mono',monospace;font-size:14px;">
              Nenhum item cadastrado.
              <?php if ($logged_in): ?><button onclick="openAddModal()" style="background:none;border:none;color:var(--accent2);cursor:pointer;font-size:14px;margin-left:6px;">Adicionar →</button><?php endif; ?>
            </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div><!-- /pip-table -->

    <footer>
      <span>Solar BPM · Produto · Roadmap</span>
      <span>Gerado em <?= date('d/m/Y') ?></span>
    </footer>
  </div><!-- /upstream -->

  <!-- ── TODAS AS DEMANDAS ── -->
  <div class="cycle-panel" id="dw-all">
    <div class="panel-header">
      <div class="panel-header-label">Downstream</div>
      <div class="panel-header-title">Todas as Demandas</div>
    </div>

    <?php
    // Uma entrada por demanda única, agregando ciclos priorizados e candidatos
    $all_demands = [];
    foreach ($cycles as $_c) {
        $_label = $_c['title'] . ' ' . $_c['ano'];
        $_prio_ids = array_column($_c['priorizadas'], 'upstream_id');
        foreach ($_c['priorizadas'] as $_p) {
            $_id = $_p['upstream_id'];
            if (!isset($all_demands[$_id])) $all_demands[$_id] = ['u' => $upstream_map_pub[$_id] ?? null, 'prio_cycles' => [], 'cand_cycles' => []];
            $all_demands[$_id]['prio_cycles'][] = $_label;
        }
        foreach ($_c['candidatas'] as $_uid) {
            if (!isset($all_demands[$_uid])) $all_demands[$_uid] = ['u' => $upstream_map_pub[$_uid] ?? null, 'prio_cycles' => [], 'cand_cycles' => []];
            $all_demands[$_uid]['cand_cycles'][] = $_label;
        }
    }
    $all_demands = array_filter($all_demands, fn($_d) => $_d['u'] !== null);
    $total_all_demands = count($all_demands);
    ?>

    <div style="margin-bottom:16px;position:relative;max-width:480px">
      <input type="text" id="dwAllSearch" placeholder="Filtrar por nome da demanda…"
        autocomplete="off"
        style="width:100%;background:var(--surface);border:1px solid var(--border);border-radius:4px;color:var(--text);font-family:'IBM Plex Sans',sans-serif;font-size:13px;padding:9px 34px 9px 12px;outline:none;transition:border-color 0.15s"
        oninput="filterDwAll(this.value)"
        onfocus="this.style.borderColor='var(--accent2)'"
        onblur="this.style.borderColor='var(--border)'">
      <span id="dwAllClear" onclick="clearDwAll()"
        style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--muted);font-size:14px;font-family:'IBM Plex Mono',monospace;line-height:1">✕</span>
    </div>
    <div style="font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:14px">
      <span id="dwAllCount"><?= $total_all_demands ?></span> de <?= $total_all_demands ?> demandas
    </div>

    <?php if (empty($all_demands)): ?>
      <div style="padding:40px;text-align:center;color:var(--muted);font-size:13px;font-style:italic">Nenhuma demanda em nenhum ciclo ainda.</div>
    <?php else: ?>
    <div style="overflow-x:auto">
      <table class="dw-table" id="dwAllTable">
        <thead><tr>
          <th>Demanda</th>
          <th>Tipo</th>
          <th>Evolução</th>
          <th>Resp.</th>
          <th class="r">PF</th>
          <th class="r">Total h</th>
          <th>Ciclo Priorizado</th>
          <th>Ciclo Candidata</th>
        </tr></thead>
        <tbody>
        <?php foreach ($all_demands as $_d):
          $_u   = $_d['u'];
          $_pf  = (float)($_u['pf'] ?? 0);
          $_tot = $_pf > 0 ? $_pf * 12 : 0;
          $_search = strtolower($_u['title'] . ' ' . $_u['responsible'] . ' ' . implode(' ', $_d['prio_cycles']) . ' ' . implode(' ', $_d['cand_cycles']));
        ?>
        <tr data-search="<?= htmlspecialchars($_search) ?>">
          <td class="td-title">
            <?php if ($_u['url']): ?><a href="<?= htmlspecialchars($_u['url']) ?>" target="_blank"><?= htmlspecialchars($_u['title']) ?></a>
            <?php else: ?><?= htmlspecialchars($_u['title']) ?><?php endif; ?>
          </td>
          <td><span class="badge <?= $_u['tipo'] ?>"><?= htmlspecialchars(tipo_label($_u['tipo'])) ?></span></td>
          <td><?php if ($_u['tipo_evolucao']): ?><span class="badge <?= $_u['tipo_evolucao'] ?>"><?= htmlspecialchars(tipo_evolucao_label($_u['tipo_evolucao'])) ?></span><?php else: ?>—<?php endif; ?></td>
          <td style="font-family:'IBM Plex Mono',monospace;font-size:12px;color:<?= $color_map[$_u['responsible']] ?? '#888' ?>"><?= htmlspecialchars($_u['responsible']) ?></td>
          <td class="r"><?= $_pf > 0 ? fmt_pf($_pf) : '—' ?></td>
          <td class="r" style="font-weight:600"><?= $_tot > 0 ? number_format($_tot, 0, ',', '.') . 'h' : '—' ?></td>
          <td style="font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--accent3)">
            <?= !empty($_d['prio_cycles']) ? htmlspecialchars(implode(', ', $_d['prio_cycles'])) : '—' ?>
          </td>
          <td style="font-family:'IBM Plex Mono',monospace;font-size:11px;color:var(--accent2)">
            <?= !empty($_d['cand_cycles']) ? htmlspecialchars(implode(', ', $_d['cand_cycles'])) : '—' ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Downstream panels -->
  <?php foreach ($cycles as $c): ?>
  <?php
    // Compute totals + chart segments from priorizadas items
    $dw_tot_m1 = 0; $dw_tot_m2 = 0; $dw_tot_ex = 0;
    $dw_ev_counts = []; $dw_tipo_counts = [];
    foreach ($c['priorizadas'] as $p) {
        $u = $upstream_map_pub[$p['upstream_id']] ?? null;
        $dw_tot_m1 += (float)$p['horas_mes1'];
        $dw_tot_m2 += (float)$p['horas_mes2'];
        $dw_tot_ex += (float)$p['horas_excedente'];
        if (!$u) continue;
        $ev = $u['tipo_evolucao'] ?: '__none';
        $dw_ev_counts[$ev] = ($dw_ev_counts[$ev] ?? 0) + 1;
        $dw_tipo_counts[$u['tipo']] = ($dw_tipo_counts[$u['tipo']] ?? 0) + 1;
    }
    $dw_tot_all = $dw_tot_m1 + $dw_tot_m2;
    $dw_ev_segs = [];
    foreach ([['label'=>'Customizada','key'=>'custom','color'=>'#c084fc'],['label'=>'GET','key'=>'get','color'=>'#38bdf8'],['label'=>'GETF','key'=>'getf','color'=>'#86efac']] as $e)
        if (($dw_ev_counts[$e['key']] ?? 0) > 0) $dw_ev_segs[] = array_merge($e, ['value'=>$dw_ev_counts[$e['key']]]);
    $dw_tipo_segs = [];
    foreach ($tipo_colors as $k => $col)
        if (($dw_tipo_counts[$k] ?? 0) > 0) $dw_tipo_segs[] = ['label'=>$tipo_labels[$k],'key'=>$k,'value'=>$dw_tipo_counts[$k],'color'=>$col];
  ?>
  <div class="cycle-panel" id="<?= htmlspecialchars($c['id']) ?>">
    <div class="panel-header">
      <div class="panel-header-label">Downstream</div>
      <div class="panel-header-title"><?= htmlspecialchars($c['title']) ?> <span class="panel-header-period"><?= htmlspecialchars($c['period']) ?></span></div>
    </div>

    <!-- KPI cards -->
    <?php if (!empty($c['priorizadas'])): ?>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:28px">
      <div class="card" style="flex:1;min-width:180px;border-top:2px solid var(--accent2);padding:16px 20px">
        <div style="font-family:'IBM Plex Mono',monospace;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:8px">Total de Horas no Ciclo</div>
        <div style="font-family:'IBM Plex Mono',monospace;font-size:28px;font-weight:700;color:#f8fafc;line-height:1"><?= $dw_tot_all > 0 ? number_format($dw_tot_all, 0, ',', '.') . 'h' : '—' ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:6px">
          <?php if ($dw_tot_m1 > 0): ?><span style="color:#60a5fa"><?= number_format($dw_tot_m1, 0, ',', '.') ?>h <?= htmlspecialchars($c['mes1']) ?></span><?php endif; ?>
          <?php if ($dw_tot_m2 > 0): ?> · <span style="color:var(--accent3)"><?= number_format($dw_tot_m2, 0, ',', '.') ?>h <?= htmlspecialchars($c['mes2']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="card" style="flex:1;min-width:180px;border-top:2px solid var(--accent);padding:16px 20px">
        <div style="font-family:'IBM Plex Mono',monospace;font-size:9px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:8px">Excedente</div>
        <div style="font-family:'IBM Plex Mono',monospace;font-size:28px;font-weight:700;color:<?= $dw_tot_ex > 0 ? 'var(--accent)' : '#f8fafc' ?>;line-height:1"><?= $dw_tot_ex > 0 ? number_format($dw_tot_ex, 0, ',', '.') . 'h' : '—' ?></div>
        <div style="font-size:11px;color:var(--muted);margin-top:6px">horas a concluir no próximo ciclo</div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Charts (only when there are priorizadas) -->
    <?php if (!empty($c['priorizadas'])): ?>
    <div class="grid-3" style="margin-bottom:32px">
      <div class="card">
        <div class="legend-title">Legenda — Tipo Evolução</div>
        <div class="legend-list">
          <span class="badge custom">Customizada</span>
          <div class="legend-desc">Demanda exclusiva para o cliente solicitante.</div>
          <hr class="legend-sep">
          <span class="badge get">GET</span>
          <div class="legend-desc"><strong>Garantia de Evolução Tecnológica</strong>Entregue a todos os clientes.</div>
          <hr class="legend-sep">
          <span class="badge getf">GETF</span>
          <div class="legend-desc"><strong>Garantia de Evolução Tecnológica e Funcional</strong>Disponível para clientes com plano GETF.</div>
        </div>
      </div>
      <div class="card">
        <div class="legend-title">Tipo Evolução — <?= htmlspecialchars($c['title']) ?></div>
        <?php if (empty($dw_ev_segs)): ?>
          <div style="padding:20px 0;color:var(--muted);font-size:13px">Não informado</div>
        <?php else: ?>
        <div class="donut-wrap">
          <?= make_donut($dw_ev_segs) ?>
          <div class="donut-legend">
            <?php foreach ($dw_ev_segs as $s): ?>
            <div class="donut-item">
              <div class="donut-dot" style="background:<?= $s['color'] ?>"></div>
              <span><?= $s['label'] ?></span>
              <span class="donut-count"><?= $s['value'] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <div class="card">
        <div class="legend-title">Tipo de Demanda — <?= htmlspecialchars($c['title']) ?></div>
        <?php if (empty($dw_tipo_segs)): ?>
          <div style="padding:20px 0;color:var(--muted);font-size:13px">Sem dados</div>
        <?php else: ?>
        <div class="donut-wrap">
          <?= make_donut($dw_tipo_segs) ?>
          <div class="donut-legend">
            <?php foreach ($dw_tipo_segs as $s): ?>
            <div class="donut-item">
              <div class="donut-dot" style="background:<?= $s['color'] ?>"></div>
              <span><?= $s['label'] ?></span>
              <span class="donut-count"><?= $s['value'] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Priorizadas -->
    <div class="dw-section">
      <div class="dw-section-header">
        <div class="dw-section-dot prio"></div>
        <div>
          <span class="dw-section-title prio">Priorizadas <span class="dw-section-count prio"><?= count($c['priorizadas']) ?></span></span>
          <div class="dw-section-desc">Demandas aprovadas na reunião de SOS com distribuição de horas por mês.</div>
        </div>
      </div>
      <?php if (empty($c['priorizadas'])): ?>
        <div class="dw-empty">Nenhuma demanda priorizada neste ciclo.</div>
      <?php else: ?>
      <?php $tot_m1 = $dw_tot_m1; $tot_m2 = $dw_tot_m2; $tot_ex = $dw_tot_ex; $tot_all = $dw_tot_all; ?>
      <div style="overflow-x:auto">
        <table class="dw-table">
          <thead><tr>
            <th style="width:36px"></th>
            <th>Demanda</th>
            <th>Tipo</th>
            <th>Evolução</th>
            <th>Resp.</th>
            <th class="r">PF</th>
            <th class="r">Total h</th>
            <th class="r"><?= htmlspecialchars($c['mes1']) ?></th>
            <th class="r"><?= htmlspecialchars($c['mes2']) ?></th>
            <th class="r">Excedente</th>
          </tr></thead>
          <tbody>
          <?php foreach ($c['priorizadas'] as $p):
            $u   = $upstream_map_pub[$p['upstream_id']] ?? null;
            if (!$u) continue;
            $pf  = (float)($u['pf'] ?? 0);
            $m1  = (float)$p['horas_mes1'];
            $m2  = (float)$p['horas_mes2'];
            $ex  = (float)$p['horas_excedente'];
            $tot = $m1 + $m2;
            $cid = 'dw_' . preg_replace('/[^a-z0-9]/i','_',$c['id'].'_'.$p['upstream_id']);
          ?>
          <tr>
            <td style="padding:6px 8px">
              <?php if (!empty($u['details'])): ?>
              <button class="comment-toggle" onclick="toggleComment('<?= $cid ?>', this)" style="white-space:nowrap">+ detalhes</button>
              <?php endif; ?>
            </td>
            <td class="td-title">
              <?php if ($u['url']): ?><a href="<?= htmlspecialchars($u['url']) ?>" target="_blank"><?= htmlspecialchars($u['title']) ?></a>
              <?php else: ?><?= htmlspecialchars($u['title']) ?><?php endif; ?>
            </td>
            <td><span class="badge <?= $u['tipo'] ?>"><?= htmlspecialchars(tipo_label($u['tipo'])) ?></span></td>
            <td><?php if ($u['tipo_evolucao']): ?><span class="badge <?= $u['tipo_evolucao'] ?>"><?= htmlspecialchars(tipo_evolucao_label($u['tipo_evolucao'])) ?></span><?php else: ?>—<?php endif; ?></td>
            <td style="font-family:'IBM Plex Mono',monospace;font-size:12px;color:<?= $color_map[$u['responsible']] ?? '#888' ?>"><?= htmlspecialchars($u['responsible']) ?></td>
            <td class="r"><?= $pf > 0 ? fmt_pf($pf) : '—' ?></td>
            <td class="r" style="font-weight:600"><?= $tot > 0 ? number_format($tot, 0, ',', '.') . 'h' : '—' ?></td>
            <td class="r"><?= $m1 > 0 ? number_format($m1, 0, ',', '.') . 'h' : '—' ?></td>
            <td class="r"><?= $m2 > 0 ? number_format($m2, 0, ',', '.') . 'h' : '—' ?></td>
            <td class="r" style="color:var(--accent)"><?= $ex > 0 ? number_format($ex, 0, ',', '.') . 'h' : '—' ?></td>
          </tr>
          <?php if (!empty($u['details'])): ?>
          <tr class="comment-row" id="<?= $cid ?>">
            <td colspan="10" class="comment-cell">
              <div class="comment-inner">
                <div class="comment-label">Observações</div>
                <p style="font-size:14px;color:#94a3b8;line-height:1.6"><?= nl2br(htmlspecialchars($u['details'])) ?></p>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="border-top:2px solid var(--border)">
              <td></td>
              <td colspan="5" style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:1px">Totais</td>
              <td class="r" style="font-weight:600"><?= $tot_all > 0 ? number_format($tot_all, 0, ',', '.') . 'h' : '—' ?></td>
              <td class="r"><?= $tot_m1 > 0 ? number_format($tot_m1, 0, ',', '.') . 'h' : '—' ?></td>
              <td class="r"><?= $tot_m2 > 0 ? number_format($tot_m2, 0, ',', '.') . 'h' : '—' ?></td>
              <td class="r" style="color:var(--accent)"><?= $tot_ex > 0 ? number_format($tot_ex, 0, ',', '.') . 'h' : '—' ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- Candidatas -->
    <div class="dw-section">
      <div class="dw-section-header">
        <div class="dw-section-dot cand"></div>
        <div>
          <span class="dw-section-title cand">Candidatas <span class="dw-section-count cand"><?= count($c['candidatas']) ?></span></span>
          <div class="dw-section-desc">Demandas aprovadas concorrendo à priorização na reunião de SOS.</div>
        </div>
      </div>
      <?php if (empty($c['candidatas'])): ?>
        <div class="dw-empty">Nenhuma candidata neste ciclo.</div>
      <?php else: ?>
      <div style="overflow-x:auto">
        <table class="dw-table">
          <thead><tr>
            <th style="width:36px"></th>
            <th>Demanda</th>
            <th>Tipo</th>
            <th>Evolução</th>
            <th>Resp.</th>
            <th class="r">PF</th>
            <th class="r">Horas</th>
          </tr></thead>
          <tbody>
          <?php foreach ($c['candidatas'] as $uid):
            $u = $upstream_map_pub[$uid] ?? null;
            if (!$u) continue;
            $pf  = (float)($u['pf'] ?? 0);
            $hr  = upstream_horas($u);
            $cid = 'dw_' . preg_replace('/[^a-z0-9]/i','_',$c['id'].'_'.$uid);
          ?>
          <tr>
            <td style="padding:6px 8px">
              <?php if (!empty($u['details'])): ?>
              <button class="comment-toggle" onclick="toggleComment('<?= $cid ?>', this)" style="white-space:nowrap">+ detalhes</button>
              <?php endif; ?>
            </td>
            <td class="td-title">
              <?php if ($u['url']): ?><a href="<?= htmlspecialchars($u['url']) ?>" target="_blank"><?= htmlspecialchars($u['title']) ?></a>
              <?php else: ?><?= htmlspecialchars($u['title']) ?><?php endif; ?>
            </td>
            <td><span class="badge <?= $u['tipo'] ?>"><?= htmlspecialchars(tipo_label($u['tipo'])) ?></span></td>
            <td><?php if ($u['tipo_evolucao']): ?><span class="badge <?= $u['tipo_evolucao'] ?>"><?= htmlspecialchars(tipo_evolucao_label($u['tipo_evolucao'])) ?></span><?php else: ?>—<?php endif; ?></td>
            <td style="font-family:'IBM Plex Mono',monospace;font-size:12px;color:<?= $color_map[$u['responsible']] ?? '#888' ?>"><?= htmlspecialchars($u['responsible']) ?></td>
            <td class="r"><?= $pf > 0 ? fmt_pf($pf) : '—' ?></td>
            <td class="r"><?= $hr > 0 ? number_format($hr, 0, ',', '.') . 'h' : '—' ?></td>
          </tr>
          <?php if (!empty($u['details'])): ?>
          <tr class="comment-row" id="<?= $cid ?>">
            <td colspan="7" class="comment-cell">
              <div class="comment-inner">
                <div class="comment-label">Observações</div>
                <p style="font-size:14px;color:#94a3b8;line-height:1.6"><?= nl2br(htmlspecialchars($u['details'])) ?></p>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <footer style="margin-top:40px;padding-top:16px;border-top:1px solid var(--border);font-size:12px;color:var(--muted);font-family:'IBM Plex Mono',monospace;display:flex;justify-content:space-between;">
      <span>Solar BPM · Produto · Roadmap</span><span><?= htmlspecialchars($c['period']) ?></span>
    </footer>
  </div>
  <?php endforeach; ?>

</div><!-- /main-content -->
</div><!-- /page-wrap -->

<!-- ── Login modal ── -->
<div class="modal-overlay" id="loginModal">
  <div class="modal-login">
    <div class="login-head">
      <div>
        <div class="login-logo"><img src="logo_solarBPM.png" alt="Solar BPM"></div>
        <div class="login-sub">Área Administrativa</div>
      </div>
      <button class="modal-x" onclick="closeLoginModal()">✕</button>
    </div>
    <div id="loginError" class="login-error"></div>
    <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?action=login">
      <div class="login-field">
        <label for="login_user">Usuário</label>
        <input type="text" id="login_user" name="username" autocomplete="username" required>
      </div>
      <div class="login-field">
        <label for="login_pass">Senha</label>
        <input type="password" id="login_pass" name="password" autocomplete="current-password" required>
      </div>
      <button type="submit" class="login-submit">Entrar</button>
    </form>
  </div>
</div>

<!-- ── Form modal (Add / Edit) ── -->
<div class="modal-overlay" id="formModal">
  <div class="modal-form">
    <div class="modal-form-hd">
      <span id="formModalTitle" class="modal-form-title">Novo item</span>
      <button class="modal-x" onclick="closeFormModal()">✕</button>
    </div>
    <div class="modal-form-bd">
      <div id="formError" class="form-error"></div>
      <form method="post" id="itemForm" action="">
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
              <?php foreach ($epd_etapas as $e): ?>
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
            <label for="f_responsible">Responsável</label>
            <select id="f_responsible" name="responsible">
              <option value="">— Selecione —</option>
              <?php foreach ($resps as $r): ?>
                <option value="<?= $r['name'] ?>"><?= htmlspecialchars($r['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="f_pf">PF (Pontos de Função)</label>
            <input type="text" inputmode="decimal" id="f_pf" name="pf" placeholder="—" autocomplete="off">
          </div>
          <div class="form-group">
            <label for="f_horas">Horas <span style="font-weight:300;color:var(--muted);font-size:11px">(PF×12h — editável)</span></label>
            <input type="text" inputmode="decimal" id="f_horas" name="horas" placeholder="—" autocomplete="off">
            <span id="f_horas_hint" class="form-hint-small"></span>
          </div>
          <div class="form-group full">
            <label for="f_details">Detalhes / Comentário</label>
            <textarea id="f_details" name="details" placeholder="Observações, contexto, links adicionais..."></textarea>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn-save">Salvar item</button>
          <button type="button" class="btn-cancel" onclick="closeFormModal()">Cancelar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Delete confirm modal ── -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal-confirm">
    <div class="modal-confirm-title">Excluir item</div>
    <div class="modal-confirm-body" id="deleteModalBody">Tem certeza?</div>
    <div class="modal-confirm-actions">
      <button class="btn-outline-sm" onclick="closeDeleteModal()">Cancelar</button>
      <form method="post" id="deleteForm" style="display:inline">
        <button type="submit" class="btn-danger">Excluir</button>
      </form>
    </div>
  </div>
</div>

<script>
const etapasByPhase = <?= json_encode(['EPD' => $epd_etapas, 'ERS' => $ers_etapas]) ?>;
const itemsMap     = <?= json_encode($items_map) ?>;
const bounce       = <?= json_encode($bounce) ?>;
const defaultResp  = <?= json_encode($resps[0]['name'] ?? 'Naim') ?>;
const baseUrl      = <?= json_encode($_SERVER['PHP_SELF']) ?>;

// ── Sidebar ──────────────────────────────────────────────────────────────────
function navigateToCycle(id) {
  const panel = document.getElementById(id);
  const item  = document.querySelector('.sidebar-item[onclick*="' + id + '"]');
  if (panel && item) showCycle(id, item);
}
function filterDwAll(q) {
  const term  = q.trim().toLowerCase();
  const rows  = document.querySelectorAll('#dwAllTable tbody tr');
  const clear = document.getElementById('dwAllClear');
  const count = document.getElementById('dwAllCount');
  let visible = 0;
  rows.forEach(function(r) {
    const match = !term || r.dataset.search.includes(term);
    r.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  if (clear)  clear.style.display = term ? 'block' : 'none';
  if (count)  count.textContent   = visible;
}
function clearDwAll() {
  const inp = document.getElementById('dwAllSearch');
  if (inp) { inp.value = ''; inp.focus(); }
  filterDwAll('');
}
function toggleSidebar() {
  const nav = document.getElementById('sidebar-nav');
  const btn = document.getElementById('sidebar-toggle');
  nav.classList.toggle('collapsed');
  btn.textContent = nav.classList.contains('collapsed') ? '▶' : '◀';
}
function showCycle(id, el) {
  document.querySelectorAll('.cycle-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  el.classList.add('active');
}

// ── Views ─────────────────────────────────────────────────────────────────────
function setUpstreamView(view, btn) {
  document.querySelectorAll('.upstream-view').forEach(v => v.classList.remove('active'));
  document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('pip-' + view).classList.add('active');
  btn.classList.add('active');
}
function filterResp(btn) {
  document.querySelectorAll('.resp-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  applyUpstreamFilters();
}
function applyUpstreamFilters() {
  const activeBtn = document.querySelector('.resp-btn.active');
  const resp = activeBtn ? activeBtn.dataset.resp : 'todos';
  const inp  = document.getElementById('upstreamNameSearch');
  const term = inp ? inp.value.trim().toLowerCase() : '';
  const clear = document.getElementById('upstreamNameClear');
  if (clear) clear.style.display = term ? 'block' : 'none';
  document.querySelectorAll('.comment-toggle.open').forEach(b => { b.classList.remove('open'); b.textContent = '+ detalhes'; });
  document.querySelectorAll('.comment-row').forEach(r => r.classList.remove('visible'));
  document.querySelectorAll('tr.item-row').forEach(row => {
    const respMatch  = resp === 'todos' || row.dataset.resp === resp;
    const titleMatch = !term || (row.dataset.title || '').includes(term);
    row.style.display = (respMatch && titleMatch) ? '' : 'none';
  });
  document.querySelectorAll('.kanban-card').forEach(card => {
    const respMatch  = resp === 'todos' || card.dataset.resp === resp;
    const titleMatch = !term || (card.dataset.title || '').includes(term);
    card.style.display = (respMatch && titleMatch) ? '' : 'none';
  });
}
function clearUpstreamSearch() {
  const inp = document.getElementById('upstreamNameSearch');
  if (inp) { inp.value = ''; inp.focus(); }
  applyUpstreamFilters();
}
function toggleCardDetail(id, btn) {
  const panel = document.getElementById('cdp_' + id);
  if (!panel) return;
  const open = panel.classList.contains('open');
  panel.classList.toggle('open', !open);
  btn.classList.toggle('open', !open);
  btn.textContent = open ? '+ detalhes' : '− detalhes';
}
function toggleComment(id, btn) {
  const row = document.getElementById(id);
  if (!row) return;
  const open = row.classList.contains('visible');
  row.classList.toggle('visible', !open);
  btn.classList.toggle('open', !open);
  btn.textContent = open ? '+ detalhes' : '− detalhes';
}
function toggleEtapa(header) {
  const cards  = header.nextElementSibling;
  const toggle = header.querySelector('.etapa-toggle');
  cards.classList.toggle('collapsed');
  toggle.classList.toggle('closed');
}

// ── Login modal ───────────────────────────────────────────────────────────────
function openLoginModal() {
  document.getElementById('loginModal').classList.add('open');
  setTimeout(() => document.getElementById('login_user').focus(), 80);
}
function closeLoginModal() {
  document.getElementById('loginModal').classList.remove('open');
}

// ── Form modal ────────────────────────────────────────────────────────────────
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
function updateHorasHint() {
  const pf   = parseDec(document.getElementById('f_pf').value);
  const h    = parseDec(document.getElementById('f_horas').value);
  const hint = document.getElementById('f_horas_hint');
  if (horasManual && pf > 0 && h !== pf * 12) {
    hint.textContent = 'Manual · auto seria ' + (pf * 12) + 'h';
  } else { hint.textContent = ''; }
}
document.getElementById('f_pf').addEventListener('input', function() {
  if (horasManual) return;
  const pf = parseDec(this.value);
  document.getElementById('f_horas').value = pf > 0 ? (pf * 12) : '';
  updateHorasHint();
});
document.getElementById('f_horas').addEventListener('input', function() {
  horasManual = true;
  updateHorasHint();
});
function populateForm(data) {
  if (!data) return;
  horasManual = false;
  document.getElementById('f_title').value         = data.title         || '';
  document.getElementById('f_url').value           = data.url           || '';
  document.getElementById('f_phase').value         = data.phase         || 'EPD';
  updateEtapas(data.phase || 'EPD', data.etapa     || '');
  document.getElementById('f_tipo').value          = data.tipo          || '';
  document.getElementById('f_tipo_evolucao').value = data.tipo_evolucao || '';
  document.getElementById('f_responsible').value   = data.responsible   || '';
  document.getElementById('f_details').value       = data.details       || '';
  const pf = parseFloat(data.pf)    || 0;
  const hr = parseFloat(data.horas) || 0;
  document.getElementById('f_pf').value    = pf > 0 ? pf : '';
  document.getElementById('f_horas').value = hr > 0 ? hr : (pf > 0 ? pf * 12 : '');
  if (hr > 0 && pf > 0 && hr !== pf * 12) horasManual = true;
  updateHorasHint();
}
function openAddModal() {
  document.getElementById('formModalTitle').textContent = 'Novo item';
  document.getElementById('itemForm').action = baseUrl + '?action=add';
  populateForm({ phase: 'EPD', tipo: '', responsible: '' });
  document.getElementById('formError').style.display = 'none';
  document.getElementById('formModal').classList.add('open');
  setTimeout(() => document.getElementById('f_title').focus(), 80);
}
function openEditModal(id) {
  const item = itemsMap[id];
  if (!item) return;
  document.getElementById('formModalTitle').textContent = 'Editar item';
  document.getElementById('itemForm').action = `${baseUrl}?action=edit&id=${encodeURIComponent(id)}`;
  populateForm(item);
  document.getElementById('formError').style.display = 'none';
  document.getElementById('formModal').classList.add('open');
  setTimeout(() => document.getElementById('f_title').focus(), 80);
}
function closeFormModal() {
  document.getElementById('formModal').classList.remove('open');
}

// ── Delete modal ──────────────────────────────────────────────────────────────
function confirmDelete(id, title) {
  document.getElementById('deleteModalBody').textContent =
    `Excluir "${title}"? Esta ação não pode ser desfeita.`;
  document.getElementById('deleteForm').action =
    `${baseUrl}?action=delete&id=${encodeURIComponent(id)}`;
  document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteModal() {
  document.getElementById('deleteModal').classList.remove('open');
}

// ── Close on backdrop / Escape ────────────────────────────────────────────────
['loginModal','formModal','deleteModal'].forEach(id => {
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    ['loginModal','formModal','deleteModal'].forEach(id =>
      document.getElementById(id).classList.remove('open')
    );
  }
});

// ── Notify auto-hide ──────────────────────────────────────────────────────────
const notifyBar = document.getElementById('notifyBar');
if (notifyBar) setTimeout(() => {
  notifyBar.style.transition = 'opacity 0.5s';
  notifyBar.style.opacity = '0';
  setTimeout(() => notifyBar.remove(), 500);
}, 3000);

// ── Reopen form modal after validation bounce ─────────────────────────────────
if (bounce) {
  const errEl = document.getElementById('formError');
  errEl.textContent = bounce.error;
  errEl.style.display = 'block';
  if (bounce.modal === 'edit' && bounce.id) {
    document.getElementById('formModalTitle').textContent = 'Editar item';
    document.getElementById('itemForm').action = `${baseUrl}?action=edit&id=${encodeURIComponent(bounce.id)}`;
  } else {
    document.getElementById('formModalTitle').textContent = 'Novo item';
    document.getElementById('itemForm').action = baseUrl + '?action=add';
  }
  populateForm(bounce.data);
  document.getElementById('formModal').classList.add('open');
}

// ── Reopen login modal after failed login ────────────────────────────────────
<?php if ($login_error): ?>
document.getElementById('loginError').textContent = <?= json_encode($login_error) ?>;
document.getElementById('loginError').style.display = 'block';
document.getElementById('loginModal').classList.add('open');
<?php endif; ?>
</script>
</body>
</html>
