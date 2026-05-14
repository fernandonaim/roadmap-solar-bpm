<?php
$current = basename($_SERVER['PHP_SELF']);
?>
<div class="topbar">
  <div class="topbar-brand">Solar BPM <span>/ Admin</span></div>
  <div class="topbar-right">
    <a href="dashboard.php"   class="<?= $current === 'dashboard.php'   ? 'active' : '' ?>">Dashboard</a>
    <a href="upstream.php"   class="<?= $current === 'upstream.php'   ? 'active' : '' ?>">Upstream</a>
    <a href="downstream.php" class="<?= $current === 'downstream.php' ? 'active' : '' ?>">Downstream</a>
    <a href="../index.php" target="_blank">Roadmap</a>
    <a href="logout.php">Sair (<?= htmlspecialchars(current_user()) ?>)</a>
  </div>
</div>
