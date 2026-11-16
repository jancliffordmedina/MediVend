'use strict';

(() => {
  const $ = selector => document.querySelector(selector);
  const pendingKey = 'medivend.staff-restock.v1';
  let session = null, inventory = null, busy = false, selected = null, pending = readPending();
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const number = value => Number(value).toLocaleString('en-PH');

  function readPending() {
    try {
      const saved = JSON.parse(sessionStorage.getItem(pendingKey));
      return saved && /^[a-f0-9]{64}$/.test(saved.body?.event_id) && Number.isInteger(saved.body?.product_id)
        && Number.isInteger(saved.body?.quantity) && saved.body.quantity > 0 && saved.body.quantity <= 10000
        && typeof saved.body.note === 'string' && typeof saved.username === 'string' ? saved : null;
    } catch (_) { return null; }
  }

  function forgetPending() {
    try {
      sessionStorage.removeItem(pendingKey);
      pending = null;
      return true;
    } catch (_) { return false; }
  }

  function message(text = '', error = false) {
    $('#page-message').textContent = text;
    $('#page-message').hidden = !text;
    $('#page-message').classList.toggle('error', error);
  }

  function formError(selector, text = '') {
    $(selector).textContent = text;
    $(selector).hidden = !text;
  }

  function setBusy(value) {
    busy = value;
    $('#login-submit').disabled = value || !session?.configured;
    $('#staff-logout').disabled = value;
    $('#refresh-stock').disabled = value;
    $('#retry-restock').disabled = value || pending?.username !== session?.username;
    $('#record-restock').disabled = value || Boolean(pending);
    $('#close-restock').disabled = value;
    $('#stock-panel').setAttribute('aria-busy', String(value));
    document.querySelectorAll('[data-restock]').forEach(button => {
      const product = inventory?.products.find(item => item.id === Number(button.dataset.restock));
      button.disabled = value || Boolean(pending) || !product?.is_active;
    });
  }

  async function request(path, body) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch('api/' + path + '.php', {
        method: body === undefined ? 'GET' : 'POST', cache: 'no-store', credentials: 'same-origin', signal: controller.signal,
        headers: body === undefined ? { Accept: 'application/json' } : { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': session?.csrf_token || '' },
        ...(body === undefined ? {} : { body: JSON.stringify(body) })
      });
      const data = await response.json();
      if (!response.ok) {
        const error = new Error(data.error?.message || 'The request could not be completed.');
        error.status = response.status;
        error.code = data.error?.code;
        throw error;
      }
      return data;
    } catch (error) {
      if (!error.status) error.message = 'Connection interrupted. Please try again.';
      throw error;
    } finally { clearTimeout(timer); }
  }

  function showSession(next) {
    session = next;
    $('#login-panel').hidden = next.authenticated;
    $('#stock-panel').hidden = !next.authenticated;
    $('#staff-logout').hidden = !next.authenticated;
    $('#staff-identity').hidden = !next.authenticated;
    $('#staff-identity').textContent = next.username || '';
    $('#login-submit').disabled = !next.configured;
    if (!next.configured) message('Staff access has not been configured. Ask your administrator to set up a staff account.', true);
  }

  function date(value) {
    const parsed = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }

  function renderStock(data) {
    inventory = data;
    if (data.csrf_token) session.csrf_token = data.csrf_token;
    const active = data.products.filter(product => product.is_active);
    $('#summary-medicines').textContent = number(active.length);
    $('#summary-units').textContent = number(active.reduce((sum, product) => sum + product.stock_quantity, 0));
    $('#summary-empty').textContent = number(active.filter(product => !product.stock_quantity).length);
    $('#updated-at').textContent = 'Updated ' + new Date().toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
    $('#mode-note').innerHTML = data.mode === 'simulation'
      ? '<strong>Test inventory</strong>These quantities belong to the prototype. Record only test stock here.'
      : '<strong>Machine inventory</strong>Count the units physically loaded into the named compartment before recording a restock.';
    $('#products').innerHTML = data.products.length ? data.products.map(product => `<article class="stock-card ${product.stock_quantity ? '' : 'empty'}"><div class="product-meta"><span>Compartment ${number(product.compartment_number)}</span><span>${product.is_active ? 'Active' : 'Inactive'}</span></div><h3>${escape(product.name)}</h3><p class="product-sku">${escape(product.sku)}</p><div class="stock-count"><strong>${number(product.stock_quantity)}</strong><span>units</span><span class="stock-status">${product.stock_quantity ? 'In stock' : 'Out of stock'}</span></div><button class="button button-outline" data-restock="${product.id}" ${!product.is_active || pending ? 'disabled' : ''}>Add stock <span aria-hidden="true">＋</span></button></article>`).join('')
      : '<p class="empty-state">No medicines are configured for this mode. Ask your administrator to configure the catalog.</p>';
    $('#history').innerHTML = data.history.length ? `<table class="history-table"><thead><tr><th>Medicine</th><th>Added</th><th>Recorded by</th><th>Note</th></tr></thead><tbody>${data.history.map(event => `<tr><td><strong>${escape(event.product_name)}</strong><small>${escape(event.sku)} · Compartment ${number(event.compartment_number)}</small></td><td><span class="quantity-added">+${number(event.quantity)}</span><small>${number(event.stock_before)} → ${number(event.stock_after)}</small></td><td>${escape(event.staff_user)}<small>${escape(date(event.created_at))}</small></td><td class="history-note">${escape(event.note)}</td></tr>`).join('')}</tbody></table>`
      : '<p class="empty-state">No restocks recorded yet. New additions will appear here.</p>';
    renderPending();
    setBusy(busy);
  }

  function renderPending() {
    $('#pending-panel').hidden = !pending;
    if (!pending) return;
    $('#pending-description').textContent = pending.username !== session?.username
      ? `Sign in as ${pending.username} to confirm the saved restock. Its result has not been discarded.`
      : `${pending.productName}: +${number(pending.body.quantity)} units. Retry checks the same record and cannot add it twice.`;
  }

  async function loadStock() {
    renderStock(await request('staff-stock'));
  }

  async function recoverSession(error) {
    if (error.code === 'staff_login_failed') return false;
    if (error.status !== 401 && error.status !== 403) return false;
    try {
      showSession(await request('staff-session'));
      if (session.authenticated) {
        message('Your session changed. Please retry the same request.', true);
      } else {
        if ($('#restock-dialog').open) $('#restock-dialog').close();
        $('#password').value = '';
        message('Your session expired. Sign in again; any unconfirmed restock is saved.', true);
      }
    } catch (_) { message('Staff access could not be checked. Refresh this page to try again.', true); }
    return true;
  }

  $('#login-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !session?.configured) return;
    setBusy(true);
    formError('#login-error');
    const password = $('#password').value;
    $('#password').value = '';
    try {
      showSession(await request('staff-session', { action: 'login', username: $('#username').value.trim(), password }));
      message();
      await loadStock();
    } catch (error) {
      await recoverSession(error);
      formError('#login-error', error.message);
      if (session?.authenticated) message(error.message, true);
    } finally { setBusy(false); }
  });

  $('#staff-logout').addEventListener('click', async () => {
    if (busy) return;
    setBusy(true);
    try {
      showSession(await request('staff-session', { action: 'logout' }));
      inventory = null;
      $('#products').replaceChildren();
      $('#history').replaceChildren();
      if ($('#restock-dialog').open) $('#restock-dialog').close();
      message(pending ? 'Signed out. The unconfirmed restock is saved for your next sign-in.' : 'You are signed out.');
    } catch (error) { if (!await recoverSession(error)) message(error.message, true); }
    finally { setBusy(false); }
  });

  $('#refresh-stock').addEventListener('click', async () => {
    if (busy) return;
    setBusy(true);
    try { await loadStock(); message(); }
    catch (error) { if (!await recoverSession(error)) message(error.message, true); }
    finally { setBusy(false); }
  });

  $('#products').addEventListener('click', event => {
    const button = event.target.closest('[data-restock]');
    if (!button || busy || pending) return;
    selected = inventory.products.find(product => product.id === Number(button.dataset.restock));
    if (!selected?.is_active) return;
    $('#restock-title').textContent = selected.name;
    $('#restock-description').textContent = `Compartment ${selected.compartment_number} · ${selected.sku}`;
    $('#dialog-stock').textContent = number(selected.stock_quantity);
    $('#quantity').value = '';
    $('#restock-note').value = '';
    formError('#restock-error');
    $('#record-restock').textContent = 'Record restock';
    $('#restock-dialog').showModal();
    $('#quantity').focus();
  });
  $('#close-restock').addEventListener('click', () => { if (!busy) $('#restock-dialog').close(); });
  $('#restock-dialog').addEventListener('cancel', event => { if (busy) event.preventDefault(); });
  $('#quantity').addEventListener('input', () => {
    const quantity = Number($('#quantity').value);
    $('#record-restock').textContent = Number.isInteger(quantity) && quantity > 0 && quantity <= 10000 ? `Add ${number(quantity)} units` : 'Record restock';
  });

  async function submitPending() {
    if (!pending || busy || pending.username !== session?.username) return;
    setBusy(true);
    message('Recording restock…');
    try {
      const data = await request('staff-stock', pending.body);
      const cleared = forgetPending();
      renderStock(data);
      if ($('#restock-dialog').open) $('#restock-dialog').close();
      message(`${data.restock.product_name}: ${number(data.restock.quantity)} units added${data.replayed ? ' (saved record confirmed)' : ''}.${cleared ? '' : ' Browser storage could not be cleared. Restore storage access, then retry this saved record before adding more stock.'}`, !cleared);
    } catch (error) {
      // Keep the same event ID when a response may have been lost after commit.
      const definitive = [400, 404, 409, 413, 415, 422].includes(error.status);
      const cleared = definitive && forgetPending();
      if ($('#restock-dialog').open) $('#restock-dialog').close();
      if (!await recoverSession(error)) message(definitive ? error.message + (cleared ? '' : ' Restore browser storage access, then retry the saved record to clear it.') : 'Restock result not confirmed. Retry the saved request below; it will not add the same stock twice.', true);
      renderPending();
    } finally { setBusy(false); }
  }

  $('#restock-form').addEventListener('submit', event => {
    event.preventDefault();
    if (busy || pending || !selected) return;
    const quantity = Number($('#quantity').value), note = $('#restock-note').value.trim();
    const noteBytes = new TextEncoder().encode(note).length;
    if (!Number.isInteger(quantity) || quantity < 1 || quantity > 10000 || noteBytes < 3 || noteBytes > 240 || /[\x00-\x1f\x7f]/.test(note)) {
      formError('#restock-error', 'Enter a whole quantity from 1 to 10,000 and a short note without line breaks (up to 240 bytes).');
      return;
    }
    try {
      const bytes = new Uint8Array(32);
      crypto.getRandomValues(bytes);
      pending = { username: session.username, productName: selected.name, body: { event_id: [...bytes].map(byte => byte.toString(16).padStart(2, '0')).join(''), product_id: selected.id, quantity, note } };
      sessionStorage.setItem(pendingKey, JSON.stringify(pending));
    } catch (_) { pending = null; formError('#restock-error', 'Browser storage is unavailable. Enable it before recording stock.'); return; }
    renderPending();
    submitPending();
  });
  $('#retry-restock').addEventListener('click', submitPending);

  (async () => {
    try { showSession(await request('staff-session')); if (session.configured) message(); if (session.authenticated) await loadStock(); }
    catch (error) { message(error.message + ' Refresh this page to try again.', true); }
    finally { setBusy(false); }
  })();
})();
