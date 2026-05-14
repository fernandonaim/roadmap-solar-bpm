<?php
require_once dirname(__DIR__) . '/includes/auth.php';
auth_start();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    if (do_login($u, $p)) {
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Usuário ou senha incorretos.';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Solar BPM Roadmap</title>
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
    --danger:  #f87171;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'IBM Plex Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .login-box {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 40px 36px;
    width: 100%;
    max-width: 360px;
  }
  .login-logo {
    font-family: 'IBM Plex Mono', monospace;
    font-size: 11px;
    color: var(--muted);
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-bottom: 8px;
  }
  .login-title {
    font-size: 22px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 28px;
  }
  label {
    display: block;
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 1px;
  }
  input[type=text], input[type=password] {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 4px;
    color: var(--text);
    font-family: 'IBM Plex Mono', monospace;
    font-size: 14px;
    padding: 10px 12px;
    margin-bottom: 18px;
    outline: none;
    transition: border-color 0.15s;
  }
  input:focus { border-color: var(--accent); }
  .btn-login {
    width: 100%;
    background: var(--accent);
    color: #0d0d14;
    border: none;
    border-radius: 4px;
    font-family: 'IBM Plex Sans', sans-serif;
    font-size: 14px;
    font-weight: 600;
    padding: 11px;
    cursor: pointer;
    transition: opacity 0.15s;
  }
  .btn-login:hover { opacity: 0.85; }
  .error-msg {
    background: rgba(248,113,113,0.12);
    border: 1px solid rgba(248,113,113,0.3);
    border-radius: 4px;
    color: var(--danger);
    font-size: 13px;
    padding: 10px 12px;
    margin-bottom: 18px;
  }
  .back-link {
    display: block;
    text-align: center;
    margin-top: 20px;
    font-size: 12px;
    color: var(--muted);
    text-decoration: none;
  }
  .back-link:hover { color: var(--text); }
</style>
</head>
<body>
<div class="login-box">
  <div class="login-logo">Solar BPM</div>
  <div class="login-title">Área Administrativa</div>
  <?php if ($error): ?>
    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="post">
    <label for="username">Usuário</label>
    <input type="text" id="username" name="username" autocomplete="username" autofocus
           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
    <label for="password">Senha</label>
    <input type="password" id="password" name="password" autocomplete="current-password">
    <button type="submit" class="btn-login">Entrar</button>
  </form>
  <a class="back-link" href="../index.php">← Ver roadmap público</a>
</div>
</body>
</html>
