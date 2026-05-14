<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/data.php';
require_login();

$items   = upstream_all();
$epd     = array_filter($items, fn($i) => $i['phase'] === 'EPD');
$ers     = array_filter($items, fn($i) => $i['phase'] === 'ERS');
$total   = count($items);

$by_resp = [];
foreach ($items as $i) {
    $by_resp[$i['responsible']] = ($by_resp[$i['responsible']] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard — Admin Solar BPM</title>
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
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'IBM Plex Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
  .topbar {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 28px;
    height: 52px;
  }
  .topbar-brand {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 12px;
    color: var(--muted);
    letter-spacing: 2px;
    text-transform: uppercase;
  }
  .topbar-brand span { color: var(--accent); }
  .topbar-right { display: flex; gap: 20px; align-items: center; }
  .topbar-right a {
    color: var(--muted);
    text-decoration: none;
    font-size: 13px;
    transition: color 0.15s;
  }
  .topbar-right a:hover { color: var(--text); }
  .topbar-right a.active { color: var(--accent); }
  .main { padding: 36px 28px; max-width: 1100px; margin: 0 auto; }
  h1 { font-size: 22px; font-weight: 600; margin-bottom: 6px; }
  .subtitle { color: var(--muted); font-size: 13px; margin-bottom: 32px; }
  .stat-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; margin-bottom: 40px; }
  .stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 20px 22px;
  }
  .stat-card .num { font-family: 'IBM Plex Mono', monospace; font-size: 32px; font-weight: 500; color: var(--accent); }
  .stat-card .lbl { font-size: 12px; color: var(--muted); margin-top: 4px; text-transform: uppercase; letter-spacing: 1px; }
  .section-title { font-size: 14px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 16px; }
  .quick-actions { display: flex; gap: 12px; flex-wrap: wrap; }
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 5px;
    border: 1px solid transparent;
    font-family: 'IBM Plex Sans', sans-serif;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    transition: opacity 0.15s;
  }
  .btn:hover { opacity: 0.8; }
  .btn-primary { background: var(--accent); color: #0d0d14; }
  .btn-outline { background: transparent; border-color: var(--border); color: var(--text); }
  .resp-list { margin-top: 32px; }
  .resp-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
  }
  .resp-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
  .resp-name { flex: 1; }
  .resp-count { font-family: 'IBM Plex Mono', monospace; color: var(--muted); }
  .resp-bar-wrap { flex: 2; background: var(--bg); border-radius: 3px; height: 6px; overflow: hidden; }
  .resp-bar { height: 100%; border-radius: 3px; }
</style>
</head>
<body>
<?php include __DIR__ . '/partials/topbar.php'; ?>
<div class="main">
  <h1>Dashboard</h1>
  <p class="subtitle">Visão geral do roadmap — Solar BPM</p>

  <div class="stat-grid">
    <div class="stat-card"><div class="num"><?= $total ?></div><div class="lbl">Total Upstream</div></div>
    <div class="stat-card"><div class="num"><?= count($epd) ?></div><div class="lbl">Em EPD</div></div>
    <div class="stat-card"><div class="num"><?= count($ers) ?></div><div class="lbl">Em ERS</div></div>
    <div class="stat-card">
      <div class="num"><?= count(array_filter($items, fn($i) => $i['etapa'] === 'Especificação Aprovada')) ?></div>
      <div class="lbl">Aprovados</div>
    </div>
  </div>

  <div class="section-title">Ações rápidas</div>
  <div class="quick-actions">
    <a href="upstream.php" class="btn btn-primary">Gerenciar Upstream</a>
    <a href="upstream.php?action=add" class="btn btn-outline">+ Novo item</a>
    <a href="../index.php" class="btn btn-outline" target="_blank">Ver roadmap</a>
  </div>

  <?php if ($total > 0): ?>
  <div class="resp-list">
    <div class="section-title" style="margin-top:32px;">Itens por responsável</div>
    <?php foreach (config_get('responsibles') as $r): ?>
      <?php $cnt = $by_resp[$r['name']] ?? 0; $pct = $total > 0 ? ($cnt / $total * 100) : 0; ?>
      <div class="resp-row">
        <div class="resp-dot" style="background:<?= $r['color'] ?>"></div>
        <div class="resp-name"><?= htmlspecialchars($r['name']) ?></div>
        <div class="resp-bar-wrap"><div class="resp-bar" style="width:<?= $pct ?>%;background:<?= $r['color'] ?>"></div></div>
        <div class="resp-count"><?= $cnt ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
