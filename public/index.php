<?php
// index.php — serves the admin SPA. Auth gate is enforced in JS by the
// admin token; the API is the real gate (require_auth on every endpoint).
require_once __DIR__ . '/../lib.php';
$token = effective_admin_token();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>mailforge — smart SMTP studio</title>
<link rel="stylesheet" href="/static/style.css">
</head>
<body>
<div id="app">
  <aside class="side">
    <div class="brand">
      <div class="logo">✉</div>
      <div><div class="brand-name">mailforge</div><div class="brand-sub">smart SMTP studio</div></div>
    </div>
    <nav id="nav">
      <a data-view="dashboard" class="active">Dashboard</a>
      <a data-view="campaigns">Campaigns</a>
      <a data-view="compose">Compose</a>
      <a data-view="verify">Verifier</a>
      <a data-view="smtp">SMTP Pool</a>
      <a data-view="links">Link URLs</a>
      <a data-view="logs">Logs</a>
      <a data-view="settings">Settings</a>
    </nav>
    <div class="side-foot" id="conn">…</div>
  </aside>

  <main id="main">
    <!-- token gate -->
    <div id="tokenGate" class="gate hidden">
      <div class="gate-card">
        <div class="logo big">✉</div>
        <h1>mailforge</h1>
        <p>Enter your admin token to open the studio.</p>
        <input id="tokenInput" type="password" placeholder="admin token" autocomplete="off">
        <button id="tokenBtn" class="primary">Unlock</button>
        <div id="tokenErr" class="err"></div>
      </div>
    </div>

    <div id="views" class="hidden">
      <header class="topbar">
        <h2 id="viewTitle">Dashboard</h2>
        <div class="topbar-right" id="topbarRight"></div>
      </header>
      <section id="view"></section>
    </div>
  </main>
</div>
<script>window.MF_TOKEN = <?= json_encode($token) ?>;</script>
<script src="/static/app.js"></script>
</body>
</html>
