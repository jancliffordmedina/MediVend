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
  <title>Staff · MediVend</title>
  <link rel="icon" type="image/png" href="assets/MediVendWebLogo.png">
  <link rel="stylesheet" href="assets/fonts.css">
  <link rel="stylesheet" href="assets/staff.css?v=20261119-2">
  <script src="assets/staff.js?v=20261118-1" defer></script>
  <script src="assets/staff-admin.js?v=20261119-1" defer></script>
</head>
<body>
  <header class="staff-header">
    <a class="staff-brand" href="./" aria-label="Open MediVend customer screen"><img src="assets/MediVendWebLogo.png" width="38" height="38" alt=""><span>MediVend<span class="brand-divider">/</span><small>Staff</small></span></a>
    <div class="header-actions"><span id="staff-identity" hidden></span><button class="button button-quiet" id="staff-logout" hidden>Sign out</button><button class="button button-outline" id="back-to-kiosk">Back to kiosk</button></div>
  </header>
  <nav id="staff-tabs" class="staff-tabs" hidden aria-label="Staff sections">
    <button class="tab" data-tab="reviews" aria-selected="false">Needs attention <span id="review-count" class="tab-count" hidden></span></button>
    <button class="tab" data-tab="stock" aria-selected="true">Stock</button>
    <button class="tab" data-tab="users" aria-selected="false" hidden>Users</button>
    <button class="tab" data-tab="simulator" aria-selected="false" hidden>Simulator</button>
  </nav>
  <main>
    <p id="page-message" class="page-message" role="status" aria-live="polite">Checking staff access…</p>
    <section id="login-panel" class="login-panel" hidden aria-labelledby="login-title">
      <div class="login-intro"><span class="eyebrow">For clinic staff</span><h1 id="login-title">A little care.<br>A well-stocked shelf.</h1><p>Check medicine quantities and record new stock in one place.</p><a class="text-link" href="./">Open customer screen <span aria-hidden="true">↗</span></a></div>
      <form id="login-form" class="login-card">
        <span class="eyebrow">Staff access</span><h2>Welcome back.</h2><p>Sign in to continue.</p>
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
    <section id="reviews-panel" hidden aria-labelledby="reviews-title">
      <div class="page-heading"><div><span class="eyebrow">Needs attention</span><h1 id="reviews-title">Help the customer, then record it.</h1><p>The machine stays blocked until every stopped order and stray coin below is resolved. Record only what you actually did.</p></div><button class="button button-outline" id="refresh-reviews">↻ <span>Refresh</span></button></div>
      <div id="review-mode" class="mode-note"></div>
      <div class="section-heading"><h2>Stopped orders</h2><span>Oldest first</span></div>
      <div id="review-orders" class="review-list"></div>
      <div class="section-heading"><h2>Coins without an order</h2><span>Accepted by the coin slot while no order was open</span></div>
      <div id="review-coins" class="review-list"></div>
      <section class="history-panel" aria-labelledby="resolved-title"><div class="section-heading"><h2 id="resolved-title">Recently resolved</h2><span>Latest 20 records</span></div><div id="review-history"></div></section>
    </section>
    <section id="users-panel" hidden aria-labelledby="users-title">
      <div class="page-heading"><div><span class="eyebrow">Administrator</span><h1 id="users-title">User accounts</h1><p>Everyone signs in on this same page. A new sign-in ends that user’s session on other devices.</p></div></div>
      <form id="user-form" class="user-form">
        <h2>Add a user</h2>
        <div class="form-grid">
          <label>Username<input id="new-username" required minlength="3" maxlength="80" pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{2,79}" autocomplete="off" spellcheck="false" autocapitalize="none"></label>
          <label>Full name<input id="new-name" required maxlength="120" autocomplete="off"></label>
          <label>Role<select id="new-role"><option value="staff">Staff: stock and stopped orders</option><option value="admin">Administrator: also manages users</option></select></label>
          <label>Password<input id="new-password" type="password" required minlength="12" maxlength="72" autocomplete="new-password"></label>
        </div>
        <p class="field-help">Passwords need 12 to 72 characters. Share them privately; staff can't see each other's passwords.</p>
        <p id="user-error" class="form-error" role="alert" hidden></p>
        <button class="button button-primary" type="submit" id="create-user">Add user</button>
      </form>
      <div id="users-list"></div>
    </section>
    <section id="simulator-panel" hidden aria-labelledby="simulator-title">
      <div class="page-heading"><div><span class="eyebrow">Device simulator · testing only</span><h1 id="simulator-title">Simulate the coin slot.</h1><p>Use this on a second device (e.g. a laptop) while the kiosk shows the Payment screen. Nothing here moves real hardware or takes real money.</p></div></div>
      <div class="mode-note"><strong>How to test a purchase</strong>1. On the kiosk, save an order. 2. Optionally choose how the next dispense ends. 3. Tap coins here until the total is paid. The kiosk updates by itself within a few seconds.</div>
      <div class="section-heading"><h2>Insert a coin</h2><span>One tap = one coin</span></div>
      <div class="sim-grid">
        <button class="button button-primary sim-coin" data-sim-coin="500">₱5</button>
        <button class="button button-primary sim-coin" data-sim-coin="1000">₱10</button>
        <button class="button button-primary sim-coin" data-sim-coin="2000">₱20</button>
      </div>
      <div class="section-heading"><h2>Next dispense result</h2><span>Applies to the next unit only, then returns to normal</span></div>
      <div class="sim-grid sim-beam">
        <button class="button button-outline" data-sim-beam="pass"><strong>Normal</strong><small>Item detected once</small></button>
        <button class="button button-outline" data-sim-beam="jam"><strong>Jam</strong><small>Chute stays blocked</small></button>
        <button class="button button-outline" data-sim-beam="none"><strong>Not detected</strong><small>Nothing falls</small></button>
        <button class="button button-outline" data-sim-beam="double"><strong>Double drop</strong><small>Two items fall</small></button>
      </div>
      <div class="section-heading"><h2>Activity</h2><span>This page only</span></div>
      <ol id="sim-log" class="sim-log"><li>No simulated events yet.</li></ol>
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
  <dialog id="resolve-dialog" aria-labelledby="resolve-title">
    <form id="resolve-form">
      <div class="dialog-heading"><span class="eyebrow">Resolve stopped order</span><button type="button" class="close-button" id="close-resolve" aria-label="Close">×</button></div>
      <h2 id="resolve-title">Order</h2>
      <div id="resolve-summary" class="resolve-summary"></div>
      <fieldset class="resolve-options"><legend>What did you do?</legend>
        <label><input type="radio" name="resolution" value="refunded"><span><strong>Returned the money by hand</strong><small id="refund-help">Give back the full recorded amount.</small></span></label>
        <label><input type="radio" name="resolution" value="handed_over"><span><strong>Handed over the medicine by hand</strong><small id="handover-help">Take the remaining units from the machine. Stock goes down by that amount.</small></span></label>
        <label><input type="radio" name="resolution" value="closed"><span><strong>Closed without refund or medicine</strong><small>For example, a test order, or when no money was taken.</small></span></label>
      </fieldset>
      <label for="resolve-note">Note</label><textarea id="resolve-note" minlength="3" maxlength="240" rows="2" required placeholder="e.g. Returned ₱30 cash to student; jam cleared"></textarea>
      <p id="resolve-error" class="form-error" role="alert" hidden></p>
      <button type="submit" class="button button-primary" id="submit-resolve">Record resolution</button>
    </form>
  </dialog>
  <dialog id="edit-user-dialog" aria-labelledby="edit-user-title">
    <form id="edit-user-form">
      <div class="dialog-heading"><span class="eyebrow">Edit user</span><button type="button" class="close-button" id="close-edit-user" aria-label="Close">×</button></div>
      <h2 id="edit-user-title">User</h2>
      <label for="edit-name">Full name</label><input id="edit-name" required maxlength="120">
      <label for="edit-role">Role</label><select id="edit-role"><option value="staff">Staff</option><option value="admin">Administrator</option></select>
      <label class="check-row"><input type="checkbox" id="edit-active"> Account active</label>
      <label for="edit-password">New password (optional)</label><input id="edit-password" type="password" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Leave blank to keep the current password">
      <p class="field-help">Changing the role or password, or deactivating, signs this user out.</p>
      <p id="edit-user-error" class="form-error" role="alert" hidden></p>
      <button type="submit" class="button button-primary" id="save-user">Save changes</button>
    </form>
  </dialog>
</body>
</html>
