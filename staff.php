<?php
declare(strict_types=1);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#174e28">
  <meta name="robots" content="noindex, nofollow">
  <title>Stock room · MediVend</title>
  <link rel="icon" type="image/png" href="assets/MediVendWebLogo.png">
  <link rel="stylesheet" href="assets/fonts.css">
  <link rel="stylesheet" href="assets/staff.css?v=20261111-1">
  <script src="assets/staff.js?v=20261111-1" defer></script>
</head>
<body>
  <header class="staff-header">
    <a class="staff-brand" href="./" aria-label="Open MediVend customer screen"><img src="assets/MediVendWebLogo.png" width="38" height="38" alt=""><span>MediVend<span class="brand-divider">/</span><small>Stock room</small></span></a>
    <div class="header-actions"><span id="staff-identity" hidden></span><button class="button button-quiet" id="staff-logout" hidden>Sign out</button></div>
  </header>
  <main>
    <p id="page-message" class="page-message" role="status" aria-live="polite">Checking staff access…</p>
    <section id="login-panel" class="login-panel" hidden aria-labelledby="login-title">
      <div class="login-intro"><span class="eyebrow">For clinic staff</span><h1 id="login-title">A little care.<br>A well-stocked shelf.</h1><p>Check medicine quantities and record new stock in one place.</p><a class="text-link" href="./">Open customer screen <span aria-hidden="true">↗</span></a></div>
      <form id="login-form" class="login-card">
        <span class="eyebrow">Staff access</span><h2>Welcome back.</h2><p>Sign in to manage stock.</p>
        <label for="username">Username</label><input id="username" name="username" autocomplete="username" required maxlength="80" spellcheck="false" autocapitalize="none">
        <label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="200">
        <p id="login-error" class="form-error" role="alert" hidden></p>
        <button class="button button-primary" type="submit" id="login-submit">Sign in <span aria-hidden="true">→</span></button>
        <p class="login-footnote">Staff accounts are managed by your administrator. Sign out when you finish.</p>
      </form>
    </section>
    <section id="stock-panel" hidden aria-labelledby="stock-title">
      <div class="page-heading"><div><span class="eyebrow">Inventory</span><h1 id="stock-title">Ready for the next person.</h1><p>Record units added to each medicine compartment.</p></div><button class="button button-outline" id="refresh-stock">↻ <span>Refresh stock</span></button></div>
      <div id="mode-note" class="mode-note"></div>
      <div id="pending-panel" class="pending-panel" hidden role="status"><div><strong>One restock needs confirmation.</strong><p id="pending-description"></p></div><button class="button button-primary" id="retry-restock">Retry same restock</button></div>
      <div class="inventory-summary" aria-label="Inventory summary"><div><span>Medicines</span><strong id="summary-medicines">—</strong></div><div><span>Units in stock</span><strong id="summary-units">—</strong></div><div><span>Out of stock</span><strong id="summary-empty">—</strong></div><p id="updated-at"></p></div>
      <div class="section-heading"><h2>Medicine stock</h2><span>One unit = one dispensing unit</span></div>
      <div id="products" class="product-grid"></div>
      <section class="history-panel" aria-labelledby="history-title"><div class="section-heading"><h2 id="history-title">Recent restocks</h2><span>Latest 20 records</span></div><p class="history-help">Restocks record additions. Purchases can reduce the current stock afterwards.</p><div id="history"></div></section>
    </section>
    <noscript><p class="page-message">Enable JavaScript to sign in and manage stock.</p></noscript>
  </main>
  <footer>For clinic staff <span aria-hidden="true">·</span> MediVend</footer>
  <dialog id="restock-dialog" aria-labelledby="restock-title" aria-describedby="restock-description">
    <form id="restock-form">
      <div class="dialog-heading"><span class="eyebrow">Record an addition</span><button type="button" class="close-button" id="close-restock" aria-label="Close restock form">×</button></div>
      <h2 id="restock-title">Add stock</h2><p id="restock-description"></p>
      <div class="current-stock"><span>Current recorded stock</span><strong id="dialog-stock"></strong></div>
      <label for="quantity">Units to add</label><input id="quantity" name="quantity" type="number" min="1" max="10000" step="1" inputmode="numeric" required placeholder="e.g. 10">
      <label for="restock-note">Restock note</label><textarea id="restock-note" name="note" minlength="3" maxlength="240" rows="2" required placeholder="e.g. Weekly refill · batch ABC123"></textarea>
      <p class="field-help">Enter only the units added. This adds to the existing quantity.</p>
      <p id="restock-error" class="form-error" role="alert" hidden></p>
      <button type="submit" class="button button-primary" id="record-restock">Record restock</button>
    </form>
  </dialog>
</body>
</html>
