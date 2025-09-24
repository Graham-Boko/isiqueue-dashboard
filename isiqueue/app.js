// ===== API endpoints =====
const API_BASE = new URL('./api/', location.href).href;
const ENDPOINTS = {
  stats:           new URL('stats.php',           API_BASE).href,
  waiting_list:    new URL('waiting_list.php',    API_BASE).href,
  current_serving: new URL('current_serving.php', API_BASE).href,
  call_next:       new URL('call_next.php',       API_BASE).href,
  set_serving:     new URL('set_serving.php',     API_BASE).href,   // NEW
  complete_ticket: new URL('complete_ticket.php', API_BASE).href,
  register:        new URL('register.php',        API_BASE).href,
  hold_ticket:     new URL('hold_ticket.php',     API_BASE).href,
  cancel_ticket:   new URL('cancel_ticket.php',   API_BASE).href,   // NEW
  // skip_ticket / recall_ticket are no longer used by the top controls
};
const REFRESH_MS = 5000;

// ===== Counter labels (front-end only) =====
const COUNTER_LABELS = {
  1: 'Withdrawal Counter',
  2: 'Deposit Counter',
  3: 'Customer Service Counter',
  4: 'Enquiry Counter',
  5: 'Loan Counter'
};
const counterLabelFromId = (id) => COUNTER_LABELS[Number(id)] || (id ? `Counter ${id}` : '—');

// ===== Elements (with backward-compat IDs) =====
const els = {
  // KPIs
  kpiWaiting: document.getElementById('kpiWaiting'),
  kpiNow: document.getElementById('kpiNow'),
  kpiNowDetail: document.getElementById('kpiNowDetail'),
  kpiDone: document.getElementById('kpiDone'),
  kpiAvg: document.getElementById('kpiAvg'),

  // Table
  queueTable: document.getElementById('queueTable')?.querySelector('tbody'),

  // Top controls (support old IDs too)
  callNext: document.getElementById('callNextBtn'),
  serving:  document.getElementById('servingBtn') || document.getElementById('completeBtn'), // old "Served" → Serving
  served:   document.getElementById('servedBtn')  || document.getElementById('recallBtn'),   // old "Recall" → Served
  hold:     document.getElementById('holdBtn')    || document.getElementById('skipBtn'),     // old "Skip"  → Hold

  // Filters / search
  counterSelect: document.getElementById('counterSelect'),
  serviceFilter: document.getElementById('serviceFilter'),
  searchBox:     document.getElementById('searchBox'),
  filterPill:    document.getElementById('filterPill'),

  // Routing / misc
  profileBtn:  document.getElementById('profileBtn'),
  profileMenu: document.getElementById('profileMenu'),
  panels: {
    queues:    document.getElementById('tab-queues'),
    register:  document.getElementById('tab-register'),
    analytics: document.getElementById('tab-analytics')
  },
  clock: document.getElementById('clock'),

  // Register form fields
  registerForm: document.getElementById('registerForm'),
  firstName: document.getElementById('firstName'),
  lastName: document.getElementById('lastName'),
  email: document.getElementById('email'),
  dob: document.getElementById('dob'),
  address: document.getElementById('address'),
  regStatus: document.getElementById('regStatus')
};

// ===== Clock =====
function tickClock(){
  const d = new Date();
  const p = n => String(n).padStart(2,'0');
  if (els.clock) els.clock.textContent = `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
}
setInterval(tickClock, 500); tickClock();

// ===== State =====
let CURRENT_SERVING = null;     // the ticket shown on the Now Serving card (may be on_call or serving)
let WAITING_CACHE = [];         // last waiting_list payload items
const ticketEnds = new Map();   // key -> timestamp(ms) when ETA hits 0
const ticketMeta = new Map();   // key -> { pos, status } seen last (to decide when to reset ETA)

// New: next Call Next should recall HOLD first?
let NEXT_CALL_PREFER_HOLD = false;

// ===== Helpers =====
async function fetchJson(url, options={}){
  const init = {
    ...options,
    headers: {
      ...(options.headers || {}),
      ...(options.body && !('Content-Type' in (options.headers || {})) && typeof options.body === 'string'
        ? { 'Content-Type': 'application/json' }
        : {})
    }
  };
  const res = await fetch(url, init);
  const text = await res.text();
  let json; try { json = JSON.parse(text); } catch { throw new Error(`Non-JSON response (HTTP ${res.status})`); }
  if (!res.ok || json.ok === false) throw new Error(json.error || `HTTP ${res.status}`);
  return json;
}

function ticketDisplay(raw, rec){
  const s = String(raw ?? '').trim();
  const iso = s.match(/^([A-Za-z]+)-\d{8}-(\d+)$/);
  if (iso){ return `${iso[1].toUpperCase()}${parseInt(iso[2],10)}`; }
  const m1 = s.match(/[A-Za-z]+[0-9]{1,4}/); if (m1) return m1[0].toUpperCase();
  const m2 = s.match(/([A-Za-z]+)\W+([0-9]{1,4})/); if (m2) return (m2[1]+m2[2]).toUpperCase();
  const prefixGuess = (typeof rec?.service_code==='string' && /^[A-Za-z]+$/.test(rec.service_code) ? rec.service_code : (s.match(/[A-Za-z]+/)?.[0] || ''));
  const groups = s.match(/\d+/g) || [];
  return groups.length ? `${prefixGuess.toUpperCase()}${parseInt(groups[groups.length-1],10)}` : (prefixGuess || s).toUpperCase();
}
function ticketKey(rec){
  const id = rec.id || rec.queue_id || rec.ticket_id;
  if (id != null) return `id:${id}`;
  const rawTicket = rec.ticket_no ?? rec.ticket ?? rec.ticket_number ?? rec.code ?? rec.tno ?? '';
  const n = ticketDisplay(rawTicket, rec);
  const svc = rec.service_code || rec.service || rec.service_name || '';
  const cust = rec.customer_id || rec.email || rec.name || rec.customer_name || '';
  return `tk:${n}|svc:${svc}|c:${String(cust).toLowerCase()}`;
}
function setText(el, v){ if (el) el.textContent = String(v); }
function escapeHtml(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

function formatMMSS(totalSeconds){
  const s = Math.max(0, Math.floor(totalSeconds));
  const mm = String(Math.floor(s/60)).padStart(2,'0');
  const ss = String(s%60).padStart(2,'0');
  return `${mm}:${ss}`;
}
function formatHMS(totalSeconds){
  const s = Math.max(0, Math.floor(totalSeconds));
  const h = Math.floor(s/3600);
  const m = Math.floor((s%3600)/60);
  const sec = s%60;
  return h>0 ? `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}` : `${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
}
function selectedCounterId(){
  const v = Number(els.counterSelect?.value || 0);
  return Number.isFinite(v) && v > 0 ? v : undefined;
}

// ===== Hold/Cancel button mode =====
function setHoldButtonMode(mode){
  if (!els.hold) return;
  if (mode === 'cancel'){
    els.hold.textContent = 'Cancel';
    els.hold.dataset.mode = 'cancel';
  } else {
    els.hold.textContent = 'Hold';
    els.hold.dataset.mode = 'hold';
  }
}

// ===== Buttons state =====
function updateButtons(){
  const hasCurrent = !!CURRENT_SERVING;
  const status = (CURRENT_SERVING?.status || '').toLowerCase();
  const holdCount = Number(CURRENT_SERVING?.hold_count ?? CURRENT_SERVING?.hold_times ?? 0);

  // Serving allowed when we have an on_call (idempotent if already serving)
  els.serving?.toggleAttribute('disabled', !(hasCurrent && (status === 'on_call' || status === 'serving')));

  // Served allowed only when truly serving
  els.served?.toggleAttribute('disabled', !(hasCurrent && status === 'serving'));

  // Hold/Cancel allowed only when on_call
  els.hold?.toggleAttribute('disabled', !(hasCurrent && status === 'on_call'));

  // Switch Hold → Cancel once hold_count >= 3 on an on_call ticket
  if (hasCurrent && status === 'on_call' && holdCount >= 3){
    setHoldButtonMode('cancel');
  } else {
    setHoldButtonMode('hold');
  }
}

// ===== Now Serving card =====
function updateNowServingUI(ticket){
  CURRENT_SERVING = ticket || null;

  if (!ticket){
    setText(els.kpiNow, '—');
    setText(els.kpiNowDetail, 'Select “Call Next”');
    setHoldButtonMode('hold');
    updateButtons();
    return;
  }

  const rawTicket = ticket?.ticket_no ?? ticket?.ticket ?? ticket?.ticket_number ?? ticket?.code ?? ticket?.tno ?? '';
  const t = ticketDisplay(rawTicket, ticket);
  const label = counterLabelFromId(ticket?.counter_id);
  const svc = ticket?.service_name || ticket?.service || '—';
  const status = (ticket?.status || '').toLowerCase();
  const statusText = status === 'serving' ? ' • Serving' : (status === 'on_call' ? ' • On call' : '');

  setText(els.kpiNow, t || '—');
  setText(els.kpiNowDetail, `${svc} • ${label}${statusText}`);

  // If we know hold_count, flip the Hold button when needed
  const holdCount = Number(ticket?.hold_count ?? ticket?.hold_times ?? 0);
  if (status === 'on_call' && holdCount >= 3) {
    setHoldButtonMode('cancel');
  } else {
    setHoldButtonMode('hold');
  }

  updateButtons();
}

// ===== KPI: total remaining =====
function updateTotalWaitKpi(nowMs = Date.now()){
  if (!els.kpiAvg) return;
  const keys = new Set(WAITING_CACHE
    .filter(r => ['waiting','hold'].includes((r.status ?? 'waiting').toLowerCase()))
    .map(ticketKey));
  let totalMs = 0;
  for (const k of keys){
    const end = ticketEnds.get(k);
    if (typeof end === 'number') totalMs += Math.max(0, end - nowMs);
  }
  setText(els.kpiAvg, formatHMS(totalMs/1000));
}

// ===== ETA loop =====
let __etaLoopStarted = false;
function updateEtasLoop(){
  const now = Date.now();
  document.querySelectorAll('.eta[data-key]').forEach(el=>{
    const k = el.getAttribute('data-key');
    const end = ticketEnds.get(k) ?? 0;
    const remain = Math.max(0, end - now);
    el.textContent = formatMMSS(remain/1000);
    el.classList.toggle('due', remain<=0);
  });
  updateTotalWaitKpi(now);
}
function ensureEtaLoop(){
  if (__etaLoopStarted) return;
  __etaLoopStarted = true;
  updateEtasLoop();
  setInterval(updateEtasLoop, 1000);
}

// ===== Routing & bootstrap =====
bindRouting();
bindControls();
bindRegister();
refreshAll();
setInterval(refreshAll, REFRESH_MS);

function bindRouting(){
  const btn  = els.profileBtn;
  const menu = els.profileMenu;
  if (btn && menu){
    const toggle = (show)=>{ menu.style.display = show ? 'block' : 'none'; btn.setAttribute('aria-expanded', String(!!show)); };
    btn.addEventListener('click',(e)=>{ e.stopPropagation(); toggle(menu.style.display!=='block'); });
    document.addEventListener('click', ()=>toggle(false));
    menu.querySelectorAll('[data-route]').forEach(a=>{
      a.addEventListener('click', (e)=>{ e.preventDefault(); go(a.getAttribute('data-route')); toggle(false); });
    });
  }
  go('queues');
}
function go(view){
  for (const k in els.panels){
    const p = els.panels[k];
    const on = k === view;
    p.classList.toggle('hidden', !on);
    p.setAttribute('aria-hidden', String(!on));
  }
}

// ===== Refreshers =====
async function refreshAll(){
  await Promise.all([ refreshKPIs(), refreshCurrentServing(), refreshWaitingList() ]);
  updateButtons();
}
async function refreshKPIs(){
  try{
    const data = await fetchJson(ENDPOINTS.stats);
    setText(els.kpiWaiting, Number(data.kpi?.waiting_all ?? 0));
    setText(els.kpiDone,    Number(data.kpi?.served_today ?? 0));

    const anServing   = document.getElementById('anServing');
    const anWaiting   = document.getElementById('anWaiting');
    const anCompleted = document.getElementById('anCompleted');
    anServing && (anServing.textContent = Number(data.kpi?.serving_now ?? 0));
    anWaiting && (anWaiting.textContent = Number(data.kpi?.waiting_all ?? 0));
    anCompleted && (anCompleted.textContent = Number(data.kpi?.served_today ?? 0));
  }catch(e){ console.error('KPIs error:', e); }
}
async function refreshCurrentServing(){
  try{
    const cid = selectedCounterId();

    // IMPORTANT: If no counter is selected, don't fetch a global "current serving".
    // Leave the card blank so "Hold" doesn't look like it auto-called someone.
    if (!cid){
      updateNowServingUI(null);
      return;
    }

    let url = ENDPOINTS.current_serving + `?counter_id=${encodeURIComponent(cid)}`;
    const data = await fetchJson(url);
    let item = data.item || data.ticket || null;

    // If server fell back to global, hide it when a counter is selected
    if (item && Number(item.counter_id || 0) !== Number(cid)) {
      item = null;
    }

    updateNowServingUI(item || null);
  }catch(e){ console.error('Current serving error:', e); }
}
async function refreshWaitingList(){
  if (!els.queueTable) return;
  try{
    const data = await fetchJson(ENDPOINTS.waiting_list);
    const baseNow = (typeof data.server_now === 'number') ? data.server_now : Date.now();
    WAITING_CACHE = Array.isArray(data.items) ? data.items : [];

    const keysThisRefresh = new Set();

    for (const r of WAITING_CACHE){
      const k = ticketKey(r);
      keysThisRefresh.add(k);

      const status = (r.status || 'waiting').toLowerCase();
      const pos = Number(r.position_ordinal) || 0;
      const etaMin = Math.max(0, Number(r.eta_minutes) || 0);

      const prev = ticketMeta.get(k);
      const shouldReset =
        !prev || prev.pos !== pos || prev.status !== status;

      // Reset ETA when first seen OR when position/status changes
      if (shouldReset){
        ticketEnds.set(k, baseNow + etaMin * 60 * 1000);
        ticketMeta.set(k, { pos, status });
      }
    }

    // Remove ends/meta for tickets no longer present
    for (const k of Array.from(ticketEnds.keys())){
      if (!keysThisRefresh.has(k)) {
        ticketEnds.delete(k);
        ticketMeta.delete(k);
      }
    }

    renderWaitingTable();
    updateTotalWaitKpi(baseNow);
    ensureEtaLoop();

  }catch(e){
    console.error('Waiting list error:', e);
    els.queueTable.innerHTML = `<tr><td colspan="7" style="color:#b65c5c;background:rgba(182,92,92,.15);border:1px solid rgba(182,92,92,.35)">Failed to load waiting list</td></tr>`;
  }
}

// Refresh current-serving immediately when counter changes
els.counterSelect?.addEventListener('change', () => {
  updateNowServingUI(null); // clear while fetching
  refreshCurrentServing();
});

// ===== Filter helpers & table render =====
function codeFromService(rec){
  const explicit = (rec.service_code || '').toString().trim();
  if (explicit) return explicit.toUpperCase();
  const byName = (rec.service || rec.service_name || '').toString().trim();
  return byName ? byName.charAt(0).toUpperCase() : '';
}
function codeToPrettyName(code){
  switch((code||'').toUpperCase()){
    case 'E': return 'Enquiry';
    case 'W': return 'Withdrawal';
    case 'D': return 'Deposit';
    case 'C': return 'Customer Service';
    case 'L': return 'Loan';
    default:  return 'All Services';
  }
}
function badgeByService(code){
  switch((code || '').toUpperCase()){
    case 'E': return 'wait';
    case 'W': return 'serving';
    case 'D': return 'done';
    case 'C': return 'wait';
    case 'L': return 'serving';
    default:  return 'wait';
  }
}
function renderWaitingTable(){
  const q = (els.searchBox?.value || '').trim().toLowerCase();
  const filter = els.serviceFilter?.value || 'ALL';

  const rows = WAITING_CACHE
    .filter(r => {
      if (filter === 'ALL') return true;
      const sc = codeFromService(r);
      if (sc === filter) return true;
      const name = (r.service || r.service_name || '').toString().toLowerCase();
      return name.startsWith(codeToPrettyName(filter).toLowerCase());
    })
    .filter(r => {
      if (!q) return true;
      const rawTicket = (r.ticket_no??r.ticket??r.ticket_number??r.code??r.tno??'');
      const hay = `${rawTicket} ${r.name||''} ${r.customer_name||''} ${r.service||r.service_name||''}`.toLowerCase();
      return hay.includes(q);
    });

  if (els.filterPill){
    els.filterPill.textContent = (filter==='ALL') ? 'All Services' : codeToPrettyName(filter);
  }

  els.queueTable.innerHTML = rows.length ? rows.map((r,i)=>{
    const rawTicket = r.ticket_no ?? r.ticket ?? r.ticket_number ?? r.code ?? r.tno ?? '';
    const ticket = ticketDisplay(rawTicket, r);
    const serviceName = r.service || r.service_name || '—';
    const customer = r.name || r.customer_name || '—';
    const status = (r.status || 'waiting').toLowerCase();
    const svcCode = codeFromService(r) || '?';

    const k = ticketKey(r);
    const endTs = ticketEnds.get(k) ?? Date.now();
    const remainSec = Math.max(0, Math.floor((endTs - Date.now())/1000));

    const statusBadgeClass =
      status === 'hold' ? 'warn' :
      status === 'serving' ? 'serving' :
      status === 'on_call' ? 'serving' :
      'wait';

    return `
      <tr>
        <td>${i+1}</td>
        <td><strong>${ticket}</strong></td>
        <td><span class="badge ${badgeByService(svcCode)}">${escapeHtml(serviceName)}</span></td>
        <td>${escapeHtml(customer)}</td>
        <td><span class="badge ${statusBadgeClass}">${escapeHtml(status)}</span></td>
        <td>
          <div style="font-weight:800;">
            <span class="eta" data-key="${escapeHtml(k)}">${formatMMSS(remainSec)}</span>
          </div>
        </td>
        <td class="action-cell">
          <button class="btn xs primary" data-action="call" data-ticket="${escapeHtml(rawTicket)}">Call</button>
          <button class="btn xs warn" data-action="hold" data-ticket="${escapeHtml(rawTicket)}">Hold</button>
        </td>
      </tr>
    `;
  }).join('') : `<tr><td colspan="7" style="color:#93a0b4">No waiting tickets.</td></tr>`;
}

// ===== Controls =====
function bindControls(){
  // Call Next — server sets top waiting to ON_CALL (or explicit ticket)
  els.callNext?.addEventListener('click', async ()=>{
    try{
      const bodyObj = { counter_id: selectedCounterId() };
      if (NEXT_CALL_PREFER_HOLD) bodyObj.prefer_hold = true; // recall HOLD first on next call after a Serve
      const data = await fetchJson(ENDPOINTS.call_next, { method:'POST', body: JSON.stringify(bodyObj) });
      const item = data.item || data.ticket || null;
      if (!item) throw new Error('No ticket returned');
      updateNowServingUI(item);
      NEXT_CALL_PREFER_HOLD = false; // reset after use
      await Promise.all([refreshKPIs(), refreshWaitingList(), refreshCurrentServing()]);
    }catch(err){
      alert('Call Next failed: ' + (err.message || err));
    }
  });

  // Serving — flip ON_CALL → SERVING (idempotent if already serving)
  els.serving?.addEventListener('click', async ()=>{
    try{
      if (!CURRENT_SERVING){ alert('No active ticket.'); return; }
      const ticket_no =
        CURRENT_SERVING.ticket_no || CURRENT_SERVING.ticket || CURRENT_SERVING.ticket_number || CURRENT_SERVING.code || CURRENT_SERVING.tno;
      const res = await fetchJson(ENDPOINTS.set_serving, {
        method: 'POST',
        headers: { 'Content-Type':'application/json' },
        body: JSON.stringify({ ticket_no })
      });
      const t = res.item || res.ticket || null;
      if (t) updateNowServingUI(t);
      await Promise.all([refreshKPIs(), refreshWaitingList(), refreshCurrentServing()]);
    }catch(err){
      alert('Set Serving failed: ' + (err.message || err));
    }
  });

  // Served — completes the current SERVING ticket (use ticket_id to avoid ambiguity)
  els.served?.addEventListener('click', async ()=>{
    if (!CURRENT_SERVING){ alert('No active ticket.'); return; }
    const status = (CURRENT_SERVING.status || '').toLowerCase();
    if (status !== 'serving'){
      alert('You can only complete a ticket that is in Serving.');
      return;
    }
    try{
      const payload = {
        ticket_id:  Number(CURRENT_SERVING.id),     // use ID instead of ticket_no
        counter_id: selectedCounterId() || 0
      };
      await fetchJson(ENDPOINTS.complete_ticket, {
        method:'POST',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify(payload)
      });

      // After completing, next "Call Next" should recall a held ticket first (if any)
      NEXT_CALL_PREFER_HOLD = true;

      updateNowServingUI(null);
      await Promise.all([refreshKPIs(), refreshWaitingList(), refreshCurrentServing()]);
    }catch(err){
      alert('Complete failed: ' + (err.message || err));
    }
  });

  // Hold / Cancel — top button behavior
  els.hold?.addEventListener('click', async ()=>{
    if (!CURRENT_SERVING){ alert('No active ticket to act on.'); return; }
    const status = (CURRENT_SERVING.status || '').toLowerCase();
    if (status !== 'on_call'){
      alert('This action is only available when the ticket is On call.');
      return;
    }

    const mode = els.hold?.dataset.mode || 'hold';
    const rawTicket =
      CURRENT_SERVING.ticket_no || CURRENT_SERVING.ticket || CURRENT_SERVING.ticket_number || CURRENT_SERVING.code || CURRENT_SERVING.tno;

    try{
      if (mode === 'cancel'){
        // Cancel after 3 prior holds
        const confirmCancel = confirm('This ticket has reached the hold limit. Cancel ticket?');
        if (!confirmCancel) return;

        await fetchJson(ENDPOINTS.cancel_ticket, {
          method:'POST',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ ticket_id: Number(CURRENT_SERVING.id), reason: 'no_show_3x' })
        });

        // After cancel, default next call is normal (prefer_hold=false)
        NEXT_CALL_PREFER_HOLD = false;

        updateNowServingUI(null);
        await Promise.all([refreshKPIs(), refreshWaitingList(), refreshCurrentServing()]);
        return;
      }

      // Normal HOLD flow
      await fetchJson(ENDPOINTS.hold_ticket, {
        method:'POST',
        headers:{ 'Content-Type':'application/json' },
        body: JSON.stringify({ ticket_no: rawTicket })
      });

      // Optimistic UI: put it to #2 with fixed ETA 3:00
      const heldRecord = { ...CURRENT_SERVING, status:'hold', eta_minutes:3, hold_count: (Number(CURRENT_SERVING?.hold_count||0)+1) };
      updateNowServingUI(null);
      applyHoldInsertAtTwo(heldRecord);

      // After a hold, next Call Next should pick the oldest WAITING first
      NEXT_CALL_PREFER_HOLD = false;

      // IMPORTANT: Do NOT refresh current_serving here; only KPIs + waiting list
      await Promise.all([refreshKPIs(), refreshWaitingList()]);
    }catch(err){
      // If API said hold limit reached, flip to Cancel immediately
      const msg = String(err?.message || '');
      if (/hold_limit_reached/i.test(msg)){
        setHoldButtonMode('cancel');
        alert('This ticket has reached the maximum number of holds (3). Please cancel the ticket.');
      } else {
        alert('Hold failed: ' + (err.message || err));
      }
    }
  });

  // Row actions (Call / Hold specific ticket)
  document.addEventListener('click', async (e)=>{
    const callBtn = e.target.closest('button[data-action="call"]');
    const holdBtn = e.target.closest('button[data-action="hold"]');

    // Call a specific ticket — sets ON_CALL
    if (callBtn){
      const rawTicket = callBtn.getAttribute('data-ticket') || '';
      if (!rawTicket) return;
      try{
        const body = JSON.stringify({ counter_id: selectedCounterId(), ticket_no: rawTicket });
        const data = await fetchJson(ENDPOINTS.call_next, { method:'POST', body });
        const item = data.item || data.ticket || null;
        if (!item) throw new Error('No ticket returned');
        updateNowServingUI(item);
        NEXT_CALL_PREFER_HOLD = false; // explicit call shouldn't set recall preference
        await Promise.all([refreshKPIs(), refreshWaitingList(), refreshCurrentServing()]);
      }catch(err){
        alert('Call failed: ' + (err.message || err));
      }
      return;
    }

    // Hold a row ticket (waiting or on_call if visible)
    if (holdBtn){
      const rawTicket = holdBtn.getAttribute('data-ticket') || '';
      if (!rawTicket) return;

      // Locate current record if present
      const found = WAITING_CACHE.find(r => {
        const rt = r.ticket_no ?? r.ticket ?? r.ticket_number ?? r.code ?? r.tno ?? '';
        return String(rt) === String(rawTicket);
      });

      try{
        await fetchJson(ENDPOINTS.hold_ticket, {
          method:'POST',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ ticket_no: rawTicket })
        });

        const heldRecord = found ? { ...found, status:'hold', eta_minutes:3 } : { ticket_no: rawTicket, status:'hold', eta_minutes:3 };
        // If we accidentally held the current on_call one, clear the card
        if (CURRENT_SERVING?.ticket_no === rawTicket) updateNowServingUI(null);

        applyHoldInsertAtTwo(heldRecord);

        NEXT_CALL_PREFER_HOLD = false; // after holding a row, next call should pick WAITING

        // IMPORTANT: Do NOT refresh current_serving here; only KPIs + waiting list
        await Promise.all([refreshKPIs(), refreshWaitingList()]);
      }catch(err){
        const msg = String(err?.message || '');
        if (/hold_limit_reached/i.test(msg)){
          alert('Hold limit reached for this ticket. It must be cancelled when recalled.');
        } else {
          alert('Hold failed: ' + (err.message || err));
        }
      }
      return;
    }
  });
}

// ===== Hold insert-at-#2 (client optimistic) =====
function applyHoldInsertAtTwo(heldRecord){
  const keyHeld = ticketKey(heldRecord);

  // Remove if exists
  WAITING_CACHE = WAITING_CACHE.filter(r => ticketKey(r) !== keyHeld);

  // Insert at index 1 (second position) or 0 if list empty
  const insertIndex = WAITING_CACHE.length >= 1 ? 1 : 0;

  // Normalize fields used by renderer
  heldRecord.status = 'hold';
  if (heldRecord.service_name == null && heldRecord.service) heldRecord.service_name = heldRecord.service;

  WAITING_CACHE.splice(insertIndex, 0, heldRecord);

  // Reset countdown for this held ticket to 3:00 from NOW
  const now = Date.now();
  ticketEnds.set(keyHeld, now + 3*60*1000);
  ticketMeta.set(keyHeld, { pos: insertIndex+1, status:'hold' });

  renderWaitingTable();
  updateTotalWaitKpi(now);
  ensureEtaLoop();
}

// ===== Registration =====
let regStatusTimer = null;
function bindRegister(){
  if (!els.registerForm) return;

  const showStatus = (ok, message, duration = 2500) => {
    if (!els.regStatus) return;
    const iconOK  = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>`;
    const iconERR = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M18 6L6 18M6 6l12 12"/></svg>`;
    els.regStatus.className = `form-status ${ok ? 'ok' : 'err'}`;
    els.regStatus.innerHTML = `${ok ? iconOK : iconERR} <span>${message}</span>`;
    if (regStatusTimer) clearTimeout(regStatusTimer);
    regStatusTimer = setTimeout(() => {
      els.regStatus.className = 'form-status';
      els.regStatus.innerHTML = '';
    }, duration);
  };

  els.registerForm.addEventListener('submit', async (e)=>{
    e.preventDefault();

    const first   = els.firstName.value.trim();
    const last    = els.lastName.value.trim();
    const email   = els.email.value.trim();
    const dob     = els.dob.value.trim();
    const address = (els.address?.value || '').trim();

    if (!first || !last || !email || !dob){
      showStatus(false, 'Please fill all required fields.', 3000);
      return;
    }

    const fd = new FormData();
    fd.append('first_name', first);
    fd.append('last_name',  last);
    fd.append('email',      email);
    fd.append('dob',        dob);
    fd.append('address',    address);
    fd.append('password', 'BspMadang@123');
    fd.append('must_change_password', '1');

    try{
      const res  = await fetch(ENDPOINTS.register, { method:'POST', body: fd });
      const text = await res.text();

      let ok = res.ok;
      try { const j = JSON.parse(text); ok = ok && (j.ok !== false); } catch {}

      if (!ok) throw new Error(text || `HTTP ${res.status}`);

      showStatus(true, 'Customer registered.', 2500);
      els.registerForm.reset();
    }catch(err){
      console.error('Register failed:', err);
      showStatus(false, 'Register failed.', 3500);
    }
  });
}
