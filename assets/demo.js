/* Simulation only: balances change only after a PHP-confirmed ledger event. */
const DemoFlow = (() => {
  const denominations = [100, 500, 1000, 2000];
  const storageKey = 'medivend.current-demo-order.v1';
  const creditStorageKey = 'medivend.protected-demo-credit.v1';
  let key = null, busy = false, blocked = false, uncertainDelivery = false, error = '', progress = '';
  let pendingCoin = null;
  let resetApproved = false;
  const resolved = () => !!lastOrder?.resolution;
  const hasCredit = () => !!lastOrder && lastOrder.paid > 0 && lastOrder.orderStatus !== 'completed' && !resolved();
  function persist() {
    const stored = JSON.stringify({ key, uncertainDelivery, pendingCoin });
    sessionStorage.setItem(storageKey, stored);
    if (hasCredit() || pendingCoin) localStorage.setItem(creditStorageKey, stored);
    else {
      const protectedOrder = JSON.parse(localStorage.getItem(creditStorageKey) || 'null');
      if (protectedOrder?.key === key) localStorage.removeItem(creditStorageKey);
    }
  }
  function attach(orderKey) { key = orderKey; blocked = false; uncertainDelivery = false; pendingCoin = null; error = ''; persist(); }
  function clear() {
    const previousKey = key;
    key = null; busy = false; blocked = false; uncertainDelivery = false; pendingCoin = null; resetApproved = false; error = '';
    try {
      sessionStorage.removeItem(storageKey);
      if (JSON.parse(localStorage.getItem(creditStorageKey) || 'null')?.key === previousKey) localStorage.removeItem(creditStorageKey);
    } catch { /* Saved database rows are never cleared here. */ }
  }
  function restore() {
    try {
      const session = JSON.parse(sessionStorage.getItem(storageKey));
      const protectedOrder = JSON.parse(localStorage.getItem(creditStorageKey));
      const value = protectedOrder && protectedOrder.key !== session?.key ? protectedOrder : session || protectedOrder;
      if (!value || !/^[a-f0-9]{64}$/.test(value.key)) return false;
      key = value.key; uncertainDelivery = value.uncertainDelivery === true;
      if (value.pendingCoin && /^[a-f0-9]{64}$/.test(value.pendingCoin.event_id)
        && denominations.includes(value.pendingCoin.coin_centavos)) pendingCoin = value.pendingCoin;
      blocked = true; screen = 'pending'; return true;
    } catch { return false; }
  }
  function apply(payload) {
    lastOrder = savedOrderFromResponse(payload, canonicalCheckoutItems(payload.order?.items));
    if (['completed', 'needs_review'].includes(lastOrder.orderStatus)) uncertainDelivery = false;
    if (pendingCoin && payload.coin_event?.event_id === pendingCoin.event_id) {
      if (payload.coin_event.coin_centavos !== pendingCoin.coin_centavos
        || !['accepted', 'rejected'].includes(payload.coin_event.outcome)) throw new Error('invalid_coin_confirmation');
      pendingCoin = null;
    }
    persist();
  }
  function staff() {
    document.querySelector('#staff-reference').textContent = lastOrder?.reference || 'Check the current saved order';
    document.querySelector('#staff-amount').textContent = lastOrder ? money(lastOrder.paid) : 'Status not yet confirmed';
    const dialog = document.querySelector('#staff-dialog');
    if (!dialog.open) dialog.showModal();
  }
  function guardReset() {
    if (resetApproved) return false;
    if (hasCredit() || pendingCoin) { staff(); return true; }
    // Close an unpaid saved session under the payment lock. A read followed
    // by a browser-only reset could otherwise race with a simultaneous coin.
    if (key && lastOrder?.orderStatus !== 'completed' && !resolved()) { verifyReset(); return true; }
    return false;
  }
  async function verifyReset() {
    if (busy || blocked) return;
    busy = true; progress = 'Checking saved credit…'; error = ''; render();
    let canReset = false;
    try {
      const success = await request('demo-session', {});
      canReset = success && lastOrder.paid === 0 && !pendingCoin && lastOrder.orderStatus === 'cancelled';
      if (!canReset && !error) error = 'Your saved order cannot be cleared here. Please contact clinic staff. No refund has been issued.';
    } catch { blocked = true; error = 'Credit status could not be checked. The saved order is kept. Refresh status or contact clinic staff.'; }
    finally { busy = false; render(); }
    if (canReset) { resetApproved = true; resetSession(); }
    else staff();
  }
  async function request(endpoint, body = null) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 10000);
    try {
      const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Demo-Order-Key': key };
      if (endpoint === 'demo-order' && pendingCoin) headers['X-Demo-Event-ID'] = pendingCoin.event_id;
      const response = await fetch('api/' + endpoint + '.php', {
        method: body ? 'POST' : 'GET', cache: 'no-store', signal: controller.signal, headers,
        ...(body ? { body: JSON.stringify(body) } : {})
      });
      const payload = await response.json();
      if (payload.order) apply(payload);
      if (!response.ok) {
        error = payload.error?.message || 'Test controls unavailable. Refresh status or contact clinic staff.';
        blocked = !payload.order;
        return false;
      }
      if (!payload.order) throw new Error('missing_order');
      if (payload.coin_event?.outcome === 'rejected') error = payload.coin_event.message;
      blocked = false; return true;
    } finally { clearTimeout(timer); }
  }
  async function refresh() {
    if (!key || busy) return;
    busy = true; progress = 'Checking saved status…'; error = ''; render();
    try {
      if (await request('demo-order')) {
        if (pendingCoin) error = 'This coin event is not yet confirmed. Retry the same event safely, or contact clinic staff. No credit has been cleared.';
        if (uncertainDelivery && lastOrder && !['completed', 'needs_review', 'cancelled'].includes(lastOrder.orderStatus)) {
          // Recovery may flag an uncertain run for review, but never repeats the
          // delivery or infers another confirmed unit from a network failure.
          if (await request('demo-dispense', { state_token: lastOrder.token, action: 'no_confirmation', product_id: null })) {
            error = 'Please contact clinic staff. The uncertain delivery test has stopped. Confirmed units are preserved; no delivery was retried and no refund was issued.';
          }
        }
      }
    } catch { blocked = true; error = 'Saved status could not be checked. Your order and recorded credit are preserved. Retry the same coin event if shown, or refresh status / contact clinic staff.'; }
    finally { busy = false; render(); }
  }
  async function event(kind, fields = {}) {
    if (busy || !lastOrder?.isDemo || !key) return;
    if (kind === 'dispense' && (blocked || pendingCoin)) return;
    if (kind === 'payment' && blocked && !pendingCoin) return;
    const wasUncertain = uncertainDelivery;
    if (kind === 'dispense') uncertainDelivery = true;
    if (kind === 'payment' && !pendingCoin) {
      if (!denominations.includes(fields.coin_centavos)) return;
      const eventId = [...crypto.getRandomValues(new Uint8Array(32))].map(byte => byte.toString(16).padStart(2, '0')).join('');
      pendingCoin = { event_id: eventId, coin_centavos: fields.coin_centavos };
    }
    try { persist(); } catch {
      error = 'Browser storage is unavailable. No event was sent. Contact clinic staff; the saved order and credit remain in the database.';
      uncertainDelivery = wasUncertain; blocked = true; render(); return;
    }
    const body = kind === 'payment' ? { ...pendingCoin } : { state_token: lastOrder.token, ...fields };
    busy = true; progress = kind === 'dispense' ? 'Recording delivery test…' : 'Confirming coin…'; error = ''; render();
    const showProcessing = new Promise(resolve => setTimeout(resolve, 250));
    try {
      const success = await request('demo-' + kind, body);
      if (success || !blocked) {
        uncertainDelivery = wasUncertain;
        if (['completed', 'needs_review'].includes(lastOrder.orderStatus)) uncertainDelivery = false;
        persist();
      }
    } catch {
      blocked = true;
      error = kind === 'dispense'
        ? 'Please contact clinic staff. Delivery could not be confirmed. Refresh status to check saved records; no delivery event will be retried.'
        : 'Coin result is unconfirmed. Retry this same event safely or refresh status. Your order and credit are preserved.';
    } finally { await showProcessing; busy = false; render(); }
  }
  function action(button) {
    const action = button.dataset.action;
    if (action === 'demo-refresh') { refresh(); return; }
    if (action === 'demo-staff') { staff(); return; }
    if (action === 'demo-retry-coin' && pendingCoin) { event('payment'); return; }
    if (action === 'demo-coin' && !pendingCoin) event('payment', { coin_centavos: Number(button.dataset.coin) });
    if (action === 'demo-start') event('dispense', { action: 'start', product_id: null });
    if (action === 'demo-delivered' && !uncertainDelivery) event('dispense', { action: 'delivered', product_id: Number(button.dataset.id) });
    if (action === 'demo-jam') event('dispense', { action: 'jam', product_id: null });
    if (action === 'demo-no-confirmation') event('dispense', { action: 'no_confirmation', product_id: null });
  }
  function markup() {
    if (!lastOrder) return '<section class="demo-flow"><div class="catalog-state" role="status"><span class="catalog-state-icon ' + (busy ? 'catalog-spinner' : '') + '">' + (busy ? '' : '!') + '</span><h2>' + (busy ? 'Loading saved order…' : 'Unable to load order') + '</h2><p>' + escapeHtml(error || 'Checking your saved order.') + '</p><button class="secondary" data-action="demo-refresh" ' + (busy ? 'disabled' : '') + '>Refresh status</button><button class="text-button" data-action="demo-staff">Staff assistance</button></div></section>';
    const order = lastOrder;
    const staffResolved = !!order.resolution;
    const review = order.orderStatus === 'needs_review' && !staffResolved, completed = order.orderStatus === 'completed' && !staffResolved, cancelled = order.orderStatus === 'cancelled';
    const paid = order.paymentStatus === 'paid', running = order.orderStatus === 'processing';
    const remaining = Math.max(0, order.total - order.paid);
    const excess = Math.max(0, order.paid - order.total);
    const delivered = order.items.reduce((sum, item) => sum + item.delivered, 0);
    const count = order.items.reduce((sum, item) => sum + item.qty, 0);
    const disabled = busy || blocked || !!pendingCoin || !order.isDemo;
    const heading = staffResolved ? 'Resolved by clinic staff.' : review ? 'Please contact clinic staff' : completed ? 'Delivery test complete.' : cancelled ? 'Unpaid session closed.' : running ? 'Confirm delivery' : paid ? 'Payment complete.' : 'Payment';
    const usable = denominations.filter(coin => coin <= remaining);
    const guidance = usable.length ? 'For ' + money(remaining) + ' remaining, use ' + usable.map(coin => money(coin).replace('.00', '')).join(' or ') + ' coins.'
      : 'No supported coin fits. Please contact clinic staff.';
    const coinButtons = denominations.map(coin => '<button class="secondary demo-coin" data-action="demo-coin" data-coin="' + coin + '" ' + (disabled || coin > remaining ? 'disabled' : '') + ' title="' + escapeHtml(coin > remaining ? guidance : 'Record one test coin') + '">Insert ' + money(coin).replace('.00', '') + '</button>').join('');
    const coinPanel = '<p class="demo-help"><strong>Exact payment only. This machine cannot return change.</strong></p><p class="demo-denominations">Coins: ₱1 · ₱5 · ₱10 · ₱20</p><div class="demo-coins">' + coinButtons + '</div><p class="notice demo-guidance">' + escapeHtml(guidance) + '</p>';
    let controls = '';
    if (staffResolved) controls = resolutionMarkup(order);
    else if (pendingCoin) controls = coinPanel + '<p class="demo-help">An unconfirmed ' + money(pendingCoin.coin_centavos) + ' coin event is kept for recovery. New coin controls are paused.</p>' + (busy ? '' : '<button class="primary" data-action="demo-retry-coin">Retry same coin event ' + icon('arrow') + '</button>');
    else if (review) controls = '<div class="staff-message" role="alert"><strong>Please contact clinic staff.</strong><p>' + (excess ? 'Full inserted amount ' + money(order.paid) + ' is preserved, including ' + money(excess) + ' above the order total. Automatic dispensing is stopped. No change or refund has been returned.' : 'The test has stopped. Recorded credit and confirmed deliveries are preserved. No automatic retry or refund has occurred.') + '</p></div>';
    else if (completed) controls = '<div class="demo-complete">' + icon('check') + '<strong>Every test unit is confirmed.</strong><p>No physical hardware was activated.</p></div>';
    else if (cancelled) controls = '<div class="demo-complete"><strong>This unpaid session is closed.</strong><p>No credit was accepted or refunded. Start a new session to choose medicines.</p></div>';
    else if (!paid) controls = coinPanel;
    else if (!running) controls = uncertainDelivery
      ? '<p class="demo-help">Please contact clinic staff. The previous delivery request was uncertain.</p><button class="secondary" data-action="demo-no-confirmation" ' + (disabled ? 'disabled' : '') + '>Stop: no confirmation</button>'
      : '<p class="demo-help">Payment complete. Recheck stock and start the delivery test.</p><button class="primary" data-action="demo-start" ' + (disabled ? 'disabled' : '') + '>Dispense ' + icon('arrow') + '</button>';
    else controls = '<p class="demo-help">Staff test controls: confirm one unit, or stop with a test fault.</p><div class="delivery-buttons">' + order.items.filter(item => item.delivered < item.qty).map(item => '<button class="secondary" data-action="demo-delivered" data-id="' + item.id + '" ' + (disabled || uncertainDelivery ? 'disabled' : '') + '>Confirm 1 unit · ' + escapeHtml(item.name) + '</button>').join('') + '</div><div class="demo-faults"><button class="secondary" data-action="demo-jam" ' + (disabled ? 'disabled' : '') + '>Jam</button><button class="secondary" data-action="demo-no-confirmation" ' + (disabled ? 'disabled' : '') + '>No confirmation</button></div>';
    const protectedCredit = hasCredit() || !!pendingCoin || blocked;
    return '<section class="demo-flow"><div class="page-head"><div><p class="eyebrow">Your saved order</p><h1 class="demo-heading ' + (review ? 'review-heading' : '') + '">' + heading + '</h1><p class="sub">Buttons record test coins and deliveries; no hardware connected.</p></div><button class="back" data-action="demo-refresh" ' + (busy ? 'disabled' : '') + '>Refresh status ' + icon('clock') + '</button></div><div class="checkout-grid demo-grid"><div class="panel demo-summary"><div class="order-reference"><span>Saved reference</span><strong>' + escapeHtml(order.reference) + '</strong></div><div class="demo-items">' + order.items.map(item => '<div class="demo-delivery-row">' + summaryLine(item, item.qty) + '<p>' + item.delivered + ' / ' + item.qty + ' units confirmed</p></div>').join('') + '</div><div class="payment-amounts"><div><span>Total due</span><strong>' + money(order.total) + '</strong></div><div><span>Amount inserted</span><strong>' + money(order.paid) + '</strong></div><div><span>Remaining balance</span><strong>' + money(remaining) + '</strong></div></div><div class="order-statuses"><span>' + (staffResolved ? (order.paymentStatus === 'refunded' ? 'Refunded by staff' : paid ? 'Paid' : 'No payment kept') : cancelled ? 'Unpaid session closed' : paid ? 'Paid' : order.paymentStatus === 'partial' ? 'Partial payment' : 'Payment pending') + '</span><span>' + (staffResolved ? 'Resolved by staff' : review ? 'Needs review' : completed ? 'Delivery test recorded' : running ? delivered + ' / ' + count + ' confirmed' : 'Dispensing not started') + '</span></div></div><div class="panel demo-controls" aria-busy="' + busy + '">' + (busy ? '<div class="demo-processing" role="status"><span class="button-spinner"></span>' + progress + '</div>' : '') + (error ? '<p class="checkout-error" role="alert">' + escapeHtml(error) + '</p>' : '') + controls + '<p class="notice demo-stock-note">Each confirmed test unit reduces test stock by one.</p></div></div><div class="demo-bottom"><button class="' + (staffResolved ? 'primary' : 'secondary') + '" data-action="' + (protectedCredit ? 'demo-staff' : 'new') + '" ' + (busy ? 'disabled' : '') + '>' + (protectedCredit ? 'Staff assistance' : completed || staffResolved ? 'Finish session' : 'Start a new session') + '</button><p class="notice">' + (protectedCredit ? 'Your saved order and recorded credit are preserved. No refund has been issued.' : 'Saved orders and confirmed delivery records remain in the database.') + '</p></div></section>';
  }
  return { attach, clear, restore, refresh, action, markup, guardReset,
    get protectsCredit() { return hasCredit() || !!pendingCoin; },
    get locked() { return busy || blocked || !!pendingCoin; } };
})();
