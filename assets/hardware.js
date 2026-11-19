/* Customer status only. No coin, motor, or sensor events originate here. */
const HardwareFlow = (() => {
  // Device simulation uses demo orders; real hardware never does.
  const wrongInventory = order => order.isDemo !== (machineRuntime.mode === 'device_simulation');
  const storageKey = 'medivend.hardware-order.v1';
  let key = null, busy = false, blocked = false, error = '', timer = null;
  // Unsupported coins (not ₱5/₱10/₱20) are returned by the acceptor and credit nothing.
  // rejectSeen is the last rejection time already known; undefined until the first status.
  let rejectSeen, rejectAlert = false, rejectTimer = null;
  function noteRejectedCoin(at) {
    const value = Number.isInteger(at) ? at : null;
    if (rejectSeen !== undefined && value !== null && (rejectSeen === null || value > rejectSeen)) {
      rejectAlert = true; clearTimeout(rejectTimer);
      rejectTimer = setTimeout(() => { rejectAlert = false; render(); }, 6000);
    }
    if (value !== null || rejectSeen === undefined) rejectSeen = value;
  }
  function attach(orderKey) {
    key = orderKey; blocked = false; rejectSeen = undefined; rejectAlert = false;
    const stored = JSON.stringify({ key });
    sessionStorage.setItem(storageKey, stored); localStorage.setItem(storageKey, stored);
    clearTimeout(timer); timer = setTimeout(refresh, 500);
  }
  function restore() {
    try {
      const value = JSON.parse(localStorage.getItem(storageKey) || sessionStorage.getItem(storageKey)
        || sessionStorage.getItem('medivend.current-demo-order.v1') || localStorage.getItem('medivend.protected-demo-credit.v1')
        || sessionStorage.getItem('medivend.pending-checkout.v1') || 'null');
      if (!value || !/^[a-f0-9]{64}$/.test(value.key)) return false;
      key = value.key; blocked = true; screen = 'pending'; return true;
    } catch { return false; }
  }
  function clear() {
    key = null; blocked = false; lastOrder = null; clearTimeout(timer);
    rejectSeen = undefined; rejectAlert = false; clearTimeout(rejectTimer);
    sessionStorage.removeItem(storageKey); localStorage.removeItem(storageKey);
  }
  function staff() {
    document.querySelector('#staff-reference').textContent = lastOrder?.reference || 'Ask staff to check the saved machine session';
    document.querySelector('#staff-amount').textContent = lastOrder ? money(lastOrder.paid) : 'Recorded amount not yet confirmed';
    const dialog = document.querySelector('#staff-dialog'); if (!dialog.open) dialog.showModal();
  }
  async function refresh() {
    if (!key || busy) return;
    clearTimeout(timer); busy = true; refreshing = true; render();
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch('api/order.php', { cache: 'no-store', headers: { 'X-Order-Key': key }, signal: controller.signal });
      const payload = await response.json();
      if (!response.ok || !payload.order) throw new Error('status_unavailable');
      lastOrder = savedOrderFromResponse(payload, canonicalCheckoutItems(payload.order.items));
      blocked = false; error = '';
      noteRejectedCoin(payload.last_rejected_coin_ms);
    } catch { blocked = true; error = 'Machine unavailable. Your recorded payment and delivery records are preserved. Please contact clinic staff.'; }
    finally {
      clearTimeout(timeout); busy = false; refreshing = false; render();
      if (key) timer = setTimeout(refresh, 2000);
      // A Done tap (or the auto-return) that arrived mid-check finishes now.
      if (finishQueued) { finishQueued = false; finish(); }
    }
  }
  let finishQueued = false, refreshing = false;
  async function finish() {
    if (refreshing) { finishQueued = true; return; } // wait for the status check, never fail early
    if (busy) return; // already closing
    if (!key || blocked || !lastOrder || wrongInventory(lastOrder)) { staff(); return; }
    if (!lastOrder.resolution && lastOrder.orderStatus !== 'completed' && (lastOrder.paid > 0 || lastOrder.orderStatus !== 'pending')) { staff(); return; }
    busy = true; render();
    const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 8000);
    try {
      const response = await fetch('api/session.php', { method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-Order-Key': key }, body: '{}', signal: controller.signal });
      if (!response.ok || (await response.json()).closed !== true) throw new Error('session_protected');
      clear(); cart.clear(); forgetCheckoutAttempt(); screen = 'welcome'; await loadMachine();
    } catch { error = 'Please contact clinic staff. The order and its credit are preserved. No refund has been issued.'; staff(); }
    finally { clearTimeout(timeout); busy = false; render(); }
  }
  function markup() {
    if (!lastOrder) return '<section class="catalog-state"><h1>Machine unavailable</h1><p>' + escapeHtml(error || 'Checking your saved order. Please contact clinic staff if it cannot continue.') + '</p><button class="secondary" data-action="hardware-refresh" ' + (busy ? 'disabled' : '') + '>Refresh status</button><button class="secondary" data-action="hardware-staff">Staff assistance</button></section>';
    const order = lastOrder, resolvedByStaff = !!order.resolution && !wrongInventory(order);
    const review = !resolvedByStaff && (wrongInventory(order) || order.orderStatus === 'needs_review');
    const complete = order.orderStatus === 'completed' && !review && !resolvedByStaff;
    if (complete) return thankYouMarkup(order, 'hardware-finish', machineRuntime.mode === 'device_simulation' ? 'Device simulator: no real medicine was dispensed.' : '');
    const heading = resolvedByStaff ? 'Resolved by clinic staff.' : review ? 'Please contact clinic staff' : !machineRuntime.available ? 'Machine unavailable' : complete ? 'Delivery complete.' : order.orderStatus === 'processing' ? 'Dispensing…' : 'Payment';
    const excess = Math.max(0, order.paid - order.total);
    return '<section class="demo-flow"><div class="page-head"><div><p class="eyebrow">Your saved order</p><h1 class="demo-heading ' + (review ? 'review-heading' : '') + '">' + heading + '</h1><p class="sub">Exact payment only. No change.</p></div><button class="back" data-action="hardware-refresh" ' + (busy ? 'disabled' : '') + '>Refresh status ' + icon('clock') + '</button></div><div class="checkout-grid demo-grid"><div class="panel demo-summary"><div class="order-reference"><span>Saved reference</span><strong>' + escapeHtml(order.reference) + '</strong></div><div class="demo-items">' + order.items.map(item => '<div class="demo-delivery-row">' + summaryLine(item, item.qty) + '<p>' + item.delivered + ' / ' + item.qty + ' units confirmed</p></div>').join('') + '</div><div class="payment-amounts"><div><span>Total due</span><strong>' + money(order.total) + '</strong></div><div><span>Amount inserted</span><strong>' + money(order.paid) + '</strong></div><div><span>Remaining balance</span><strong>' + money(Math.max(0, order.total - order.paid)) + '</strong></div></div></div><div class="panel demo-controls">' + (error ? '<p class="checkout-error" role="alert">' + escapeHtml(error) + '</p>' : '') + (rejectAlert && !complete ? '<div class="coin-rejected" role="alert"><strong>Coin not accepted</strong><p>This machine accepts ₱5, ₱10 and ₱20 coins only. Please take your coin from the coin return. Nothing was added to your payment.</p></div>' : '') + '<p class="demo-denominations">Accepted coins: ₱5 · ₱10 · ₱20</p>' + (resolvedByStaff ? resolutionMarkup(order) : review || !machineRuntime.available ? '<div class="staff-message"><strong>Please contact clinic staff.</strong><p>Recorded credit and confirmed deliveries are preserved.' + (excess ? ' Excess credit: ' + money(excess) + '.' : '') + ' No change or refund has been returned.</p></div>' : '<p class="demo-help">' + (order.orderStatus === 'processing' ? 'Waiting for confirmed delivery. Please wait.' : 'Insert coins only when the machine is available. Payment updates after confirmation.') + '</p>') + '</div></div><div class="demo-bottom"><button class="secondary" data-action="hardware-finish" ' + (busy ? 'disabled' : '') + '>' + (complete || resolvedByStaff ? 'Finish session' : order.paid || review ? 'Staff assistance' : 'Start over') + '</button><p class="notice">Your saved order and recorded credit are preserved.</p></div></section>';
  }
  return { attach, restore, clear, refresh, markup, guardReset() { if (key) { finish(); return true; } return false; },
    action(button) { if (button.dataset.action === 'hardware-refresh') refresh(); else if (button.dataset.action === 'hardware-finish') finish(); else staff(); },
    get protectsCredit() { return !!key; }, get locked() { return busy || blocked; } };
})();
