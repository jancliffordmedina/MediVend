'use strict';

/* Staff tabs: Needs attention (resolve stopped orders/coins) and Users (admin). */
(() => {
  const $ = selector => document.querySelector(selector);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const peso = centavos => '₱' + (Number(centavos) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const when = value => { const d = new Date(String(value).replace(' ', 'T')); return Number.isNaN(d.getTime()) ? String(value ?? '') : d.toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); };
  const randomId = () => [...crypto.getRandomValues(new Uint8Array(32))].map(b => b.toString(16).padStart(2, '0')).join('');
  const api = () => window.MedivendStaff;
  const pendingKey = 'medivend.staff-resolution.v1';
  let tab = 'stock', queue = null, users = null, selfId = null, selectedOrder = null, editing = null, working = false;

  function formError(selector, text = '') { $(selector).textContent = text; $(selector).hidden = !text; }

  function showTab(next) {
    const session = api().session;
    if (next === 'users' && session?.role !== 'admin') next = 'stock';
    if (next === 'simulator' && session?.machine_mode !== 'device_simulation') next = 'stock';
    tab = next;
    document.querySelectorAll('#staff-tabs .tab').forEach(button => button.setAttribute('aria-selected', String(button.dataset.tab === tab)));
    $('#stock-panel').hidden = tab !== 'stock';
    $('#reviews-panel').hidden = tab !== 'reviews';
    $('#users-panel').hidden = tab !== 'users';
    $('#simulator-panel').hidden = tab !== 'simulator';
    if (tab === 'reviews') loadReviews();
    if (tab === 'users') loadUsers();
  }

  async function call(path, body) {
    const data = await api().request(path, body);
    if (data.csrf_token) api().session.csrf_token = data.csrf_token;
    return data;
  }

  async function handle(error) {
    if (!await api().recoverSession(error)) api().message(error.message, true);
  }

  // ---- Needs attention ------------------------------------------------------
  function orderCard(order) {
    const delivered = order.items.reduce((s, i) => s + i.dispensed_quantity, 0), units = order.items.reduce((s, i) => s + i.quantity, 0);
    const why = order.excess_centavos ? 'Extra coin accepted (' + peso(order.excess_centavos) + ' over the total)'
      : order.last_delivery_outcome === 'jam' ? 'Jam detected' : order.last_delivery_outcome === 'timeout' ? 'Item not detected by the sensor'
      : order.last_delivery_outcome === 'uncertain' ? 'Delivery uncertain' : 'Stopped for staff review';
    return `<article class="review-card"><div class="review-main"><span class="review-why">${escape(why)}</span><h3>${escape(order.reference)}</h3>
      <p>${order.items.map(i => `${escape(i.product_name)} × ${i.quantity} <small>(${i.dispensed_quantity} delivered)</small>`).join(' · ')}</p>
      <dl><div><dt>Total</dt><dd>${peso(order.total_centavos)}</dd></div><div><dt>Paid</dt><dd>${peso(order.amount_paid_centavos)}</dd></div><div><dt>Delivered</dt><dd>${delivered} / ${units}</dd></div><div><dt>Saved</dt><dd>${escape(when(order.created_at))}</dd></div></dl></div>
      <button class="button button-primary" data-resolve="${escape(order.reference)}">Resolve</button></article>`;
  }

  function renderReviews(data) {
    queue = data;
    const open = data.orders.length + data.unassigned_coins.length;
    $('#review-count').textContent = String(open);
    $('#review-count').hidden = open === 0;
    $('#review-mode').innerHTML = data.mode === 'hardware'
      ? '<strong>Machine orders</strong>Real payments. Return money or medicine exactly as recorded here.'
      : '<strong>Test orders</strong>These orders belong to the ' + (data.mode === 'device_simulation' ? 'device simulator' : 'test mode') + '. No real money was taken.';
    $('#review-orders').innerHTML = data.orders.length ? data.orders.map(orderCard).join('') : '<p class="empty-state">No stopped orders. The machine is clear.</p>';
    $('#review-coins').innerHTML = data.unassigned_coins.length ? data.unassigned_coins.map(coin => `<article class="review-card"><div class="review-main"><span class="review-why">${coin.reason === 'before_order' ? 'Inserted before the order was saved' : 'Inserted with no open order'}</span><h3>${peso(coin.coin_centavos)} coin</h3><p>Accepted ${escape(when(coin.created_at))}</p></div>
      <div class="review-actions"><button class="button button-primary" data-coin="${escape(coin.event_id)}" data-coin-action="refunded">Returned coin</button><button class="button button-outline" data-coin="${escape(coin.event_id)}" data-coin-action="closed">Close</button></div></article>`).join('')
      : '<p class="empty-state">No stray coins.</p>';
    const labels = { refunded: 'Money returned', handed_over: 'Medicine handed over', closed: 'Closed' };
    $('#review-history').innerHTML = data.recent.length ? `<table class="history-table"><thead><tr><th>Order</th><th>Resolution</th><th>Recorded by</th><th>Note</th></tr></thead><tbody>${data.recent.map(r => `<tr><td><strong>${escape(r.reference)}</strong><small>Paid ${peso(r.amount_paid_centavos)}</small></td><td>${labels[r.action]}<small>${r.refunded_centavos ? 'Returned ' + peso(r.refunded_centavos) : ''}${r.units_handed_over ? ' ' + r.units_handed_over + ' unit(s) handed over' : ''}</small></td><td>${escape(r.staff_user)}<small>${escape(when(r.created_at))}</small></td><td class="history-note">${escape(r.note)}</td></tr>`).join('')}</tbody></table>`
      : '<p class="empty-state">Nothing resolved yet.</p>';
  }

  async function loadReviews() {
    try { renderReviews(await call('staff-reviews')); } catch (error) { await handle(error); }
  }

  function readPending() { try { return JSON.parse(sessionStorage.getItem(pendingKey) || 'null'); } catch (_) { return null; } }

  $('#review-orders').addEventListener('click', event => {
    const button = event.target.closest('[data-resolve]');
    if (!button || working) return;
    selectedOrder = queue.orders.find(order => order.reference === button.dataset.resolve);
    if (!selectedOrder) return;
    const o = selectedOrder, remaining = o.items.reduce((s, i) => s + i.quantity - i.dispensed_quantity, 0);
    $('#resolve-title').textContent = o.reference;
    $('#resolve-summary').innerHTML = `<p>${o.items.map(i => `${escape(i.product_name)} × ${i.quantity} (${i.dispensed_quantity} delivered)`).join('<br>')}</p><p><strong>Paid ${peso(o.amount_paid_centavos)}</strong> of ${peso(o.total_centavos)}</p>`;
    $('#refund-help').textContent = o.refund_due_centavos ? `Give back ${peso(o.refund_due_centavos)}` + (o.remaining_units ? ' (what the customer did not receive).' : ' excess. The medicine was already delivered.') : 'Nothing is owed back for this order.';
    $('#handover-help').textContent = !o.remaining_units ? 'Not needed: every unit was already delivered.' : o.amount_paid_centavos < o.total_centavos ? 'Not available: the order is not fully paid.'
      : `Hand over ${remaining} unit(s) from the machine; stock goes down by ${remaining}.` + (o.excess_centavos ? ` Also return the ${peso(o.excess_centavos)} excess.` : '');
    document.querySelectorAll('[name="resolution"]').forEach(input => {
      input.checked = false;
      input.disabled = (input.value === 'refunded' && !o.refund_due_centavos) || (input.value === 'handed_over' && (!o.remaining_units || o.amount_paid_centavos < o.total_centavos));
    });
    $('#resolve-note').value = '';
    formError('#resolve-error');
    $('#resolve-dialog').showModal();
  });
  $('#close-resolve').addEventListener('click', () => { if (!working) $('#resolve-dialog').close(); });

  async function submitResolution(body) {
    working = true; $('#submit-resolve').disabled = true;
    try {
      const data = await call('staff-reviews', body);
      sessionStorage.removeItem(pendingKey);
      renderReviews(data);
      if ($('#resolve-dialog').open) $('#resolve-dialog').close();
      api().message(`${body.reference || 'Coin'} resolved. ${data.orders.length + data.unassigned_coins.length ? 'Other items still need attention.' : 'The machine can serve the next customer. On the kiosk, tap Refresh status, then Finish session.'}`);
    } catch (error) {
      // Keep the same resolution ID if the response may have been lost after commit.
      if ([400, 404, 409, 413, 415, 422].includes(error.status)) sessionStorage.removeItem(pendingKey);
      formError('#resolve-error', error.status ? error.message : 'Result not confirmed. Submit again; it will not be recorded twice.');
      await handle(error);
      if (!$('#resolve-dialog').open && body.kind === 'coin') api().message(error.message, true);
    } finally { working = false; $('#submit-resolve').disabled = false; }
  }

  $('#resolve-form').addEventListener('submit', event => {
    event.preventDefault();
    if (working || !selectedOrder) return;
    const action = document.querySelector('[name="resolution"]:checked')?.value, note = $('#resolve-note').value.trim();
    if (!action) { formError('#resolve-error', 'Choose what you did.'); return; }
    if (note.length < 3) { formError('#resolve-error', 'Add a short note about what you did.'); return; }
    const saved = readPending();
    const body = saved && saved.reference === selectedOrder.reference && saved.action === action && saved.note === note ? saved
      : { kind: 'order', event_id: randomId(), reference: selectedOrder.reference, action, note };
    try { sessionStorage.setItem(pendingKey, JSON.stringify(body)); } catch (_) { /* resolution still idempotent per order */ }
    submitResolution(body);
  });

  $('#review-coins').addEventListener('click', event => {
    const button = event.target.closest('[data-coin]');
    if (!button || working) return;
    const note = window.prompt(button.dataset.coinAction === 'refunded' ? 'Note: who received the coin back?' : 'Note: why is this coin being closed?');
    if (note === null) return;
    if (note.trim().length < 3) { api().message('Add a note of at least 3 characters.', true); return; }
    submitResolution({ kind: 'coin', event_id: button.dataset.coin, action: button.dataset.coinAction, note: note.trim() });
  });
  $('#refresh-reviews').addEventListener('click', loadReviews);

  // ---- Users ----------------------------------------------------------------
  function renderUsers(data) {
    users = data.users; selfId = data.self_id;
    $('#users-list').innerHTML = `<table class="history-table users-table"><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead><tbody>${users.map(u => `<tr class="${u.is_active ? '' : 'inactive'}"><td><strong>${escape(u.display_name)}</strong><small>${escape(u.username)}${u.id === selfId ? ' · you' : ''}</small></td><td>${u.role === 'admin' ? 'Administrator' : 'Staff'}</td><td>${u.is_active ? (u.signed_in ? 'Signed in' : 'Active') : 'Deactivated'}</td><td>${u.last_login_at ? escape(when(u.last_login_at)) : 'Never'}</td><td><button class="button button-outline" data-edit-user="${u.id}">Edit</button></td></tr>`).join('')}</tbody></table>`;
  }

  async function loadUsers() {
    try { renderUsers(await call('staff-users')); } catch (error) { await handle(error); }
  }

  $('#user-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (working) return;
    working = true; $('#create-user').disabled = true; formError('#user-error');
    const password = $('#new-password').value;
    try {
      const data = await call('staff-users', { action: 'create', username: $('#new-username').value.trim(), display_name: $('#new-name').value.trim(), role: $('#new-role').value, password });
      renderUsers(data);
      $('#user-form').reset();
      api().message(`User ${data.created.username} added.` + (api().session.bootstrap ? ' If this is an administrator, sign out and sign in with it; the setup account then stops working.' : ''));
    } catch (error) { formError('#user-error', error.message); await handle(error); }
    finally { $('#new-password').value = ''; working = false; $('#create-user').disabled = false; }
  });

  $('#users-list').addEventListener('click', event => {
    const button = event.target.closest('[data-edit-user]');
    if (!button || working) return;
    editing = users.find(u => u.id === Number(button.dataset.editUser));
    if (!editing) return;
    $('#edit-user-title').textContent = editing.username;
    $('#edit-name').value = editing.display_name;
    $('#edit-role').value = editing.role;
    $('#edit-active').checked = editing.is_active;
    $('#edit-password').value = '';
    formError('#edit-user-error');
    $('#edit-user-dialog').showModal();
  });
  $('#close-edit-user').addEventListener('click', () => { if (!working) $('#edit-user-dialog').close(); });

  $('#edit-user-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (working || !editing) return;
    const body = { action: 'update', id: editing.id, display_name: $('#edit-name').value.trim(), role: $('#edit-role').value, is_active: $('#edit-active').checked };
    if ($('#edit-password').value) body.password = $('#edit-password').value;
    working = true; $('#save-user').disabled = true; formError('#edit-user-error');
    try {
      renderUsers(await call('staff-users', body));
      $('#edit-user-dialog').close();
      api().message(`${editing.username} updated.`);
    } catch (error) { formError('#edit-user-error', error.message); await handle(error); }
    finally { $('#edit-password').value = ''; working = false; $('#save-user').disabled = false; }
  });

  // ---- Simulator (device_simulation only) -------------------------------------
  const simLog = text => {
    const list = $('#sim-log');
    if (list.dataset.started !== '1') { list.replaceChildren(); list.dataset.started = '1'; }
    const item = document.createElement('li');
    item.textContent = new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', second: '2-digit' }) + ' · ' + text;
    list.prepend(item);
  };
  $('#simulator-panel').addEventListener('click', async event => {
    const button = event.target.closest('[data-sim-coin], [data-sim-beam]');
    if (!button || working) return;
    const body = button.dataset.simCoin ? { action: 'coin', centavos: Number(button.dataset.simCoin) } : { action: 'beam', scenario: button.dataset.simBeam };
    working = true;
    document.querySelectorAll('#simulator-panel button').forEach(b => { b.disabled = true; });
    try {
      const data = await call('staff-simulator', body);
      simLog(data.message);
      if (body.action === 'coin') await new Promise(resolve => setTimeout(resolve, 900)); // one coin at a time, like a real slot
    } catch (error) { simLog('Failed: ' + error.message); await handle(error); }
    finally { working = false; document.querySelectorAll('#simulator-panel button').forEach(b => { b.disabled = false; }); }
  });

  // ---- Wiring ---------------------------------------------------------------
  document.querySelectorAll('#staff-tabs .tab').forEach(button => button.addEventListener('click', () => showTab(button.dataset.tab)));

  $('#back-to-kiosk').addEventListener('click', async () => {
    // Shared touchscreen: never leave a staff session open behind the kiosk.
    try { if (api()?.session?.authenticated) await api().request('staff-session', { action: 'logout' }); } catch (_) { /* still leave */ }
    window.location.href = './';
  });

  window.addEventListener('staff:session', async event => {
    const session = event.detail;
    $('#staff-tabs').hidden = !session.authenticated;
    document.querySelector('#staff-tabs [data-tab="users"]').hidden = session.role !== 'admin';
    document.querySelector('#staff-tabs [data-tab="simulator"]').hidden = session.machine_mode !== 'device_simulation';
    if (!session.authenticated) {
      $('#reviews-panel').hidden = true; $('#users-panel').hidden = true; $('#simulator-panel').hidden = true;
      return;
    }
    try {
      const data = await call('staff-reviews');
      renderReviews(data);
      showTab(data.orders.length + data.unassigned_coins.length ? 'reviews' : tab);
    } catch (error) { showTab(tab); await handle(error); }
  });
})();
