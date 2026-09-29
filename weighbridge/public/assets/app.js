// Weighing screen: live weight of every scale, scale picker (remembered per PC), ANPR button + auto-read.
(function () {
  const wrap = document.querySelector('[data-scales]');
  if (!wrap) return;
  const $$ = s => document.querySelectorAll(s);
  const auto = wrap.dataset.anprAuto === '1', csrf = wrap.dataset.csrf;
  let picked = null, last = {}, armed = true, busy = false;
  try { picked = parseInt(localStorage.getItem('wb_scale')) || null; } catch (e) {}

  function pick(id) {
    picked = id;
    try { localStorage.setItem('wb_scale', id); } catch (e) {}
    $$('.scale-card').forEach(c => c.classList.toggle('sel', +c.dataset.id === id));
    $$('input.scale_id').forEach(i => i.value = id);
    armed = true; render();
  }
  $$('.scale-card').forEach(c => c.addEventListener('click', () => pick(+c.dataset.id)));

  function render() {
    const s = last[picked];
    $$('[data-needs-stable]').forEach(b => b.disabled = !(s && s.fresh && s.stable));
    const anprBtn = document.getElementById('anpr_btn'); if (anprBtn) anprBtn.disabled = busy;
  }

  async function readPlate(manual) {
    if (busy) return; busy = true; render();
    const note = document.getElementById('anpr_note'), field = document.getElementById('vehicle_no');
    note.textContent = 'Reading plate...'; note.className = 'hint';
    try {
      const fd = new FormData(); fd.set('scale_id', picked); fd.set('csrf', csrf);
      const r = await (await fetch('api/anpr.php', {method: 'POST', body: fd})).json();
      if (r.ok) {
        field.value = r.plate;
        note.textContent = 'Plate ' + r.read + ' (' + Math.round((r.confidence ?? 1) * 100) + '%)' + (r.known ? ' - known vehicle' + (r.tare_kg ? ', stored tare ' + Math.round(r.tare_kg) + ' kg' : '') : ' - new vehicle')
          + (r.open_ticket_id ? ' - HAS AN OPEN TICKET: use "2nd weight" on the right' : '');
        note.className = 'hint ' + (r.open_ticket_id ? 'warnt' : 'okt');
      } else { note.textContent = (r.error || 'Not read') + (manual ? '' : ' (auto)'); note.className = 'hint badt'; }
    } catch (e) { note.textContent = 'ANPR request failed'; note.className = 'hint badt'; }
    busy = false; render();
  }
  const btn = document.getElementById('anpr_btn'); if (btn) btn.addEventListener('click', () => readPlate(true));

  async function tick() {
    try {
      const data = (await (await fetch('api/live.php', {cache: 'no-store'})).json()).scales || [];
      data.forEach(s => {
        last[s.id] = s;
        const c = document.querySelector('.scale-card[data-id="' + s.id + '"]'); if (!c) return;
        c.querySelector('[data-w]').firstChild.nodeValue = s.fresh ? Number(s.weight).toLocaleString('en-IN', {maximumFractionDigits: 2}) : '---';
        const b = c.querySelector('[data-st]');
        b.className = 'badge ' + (!s.fresh ? 'bad' : s.stable ? 'ok' : 'warn');
        b.textContent = !s.fresh ? 'NO DATA' : s.stable ? 'STABLE' : 'MOTION';
        c.querySelector('[data-msg]').textContent = s.daemon_alive ? (s.message || s.status) : 'Reader not running';
      });
      if (!picked || !last[picked]) { const f = data[0]; if (f) pick(f.id); }
      // Auto ANPR: once per truck, when it settles on the selected scale and the vehicle box is still empty.
      const s = last[picked], field = document.getElementById('vehicle_no');
      if (auto && s) {
        if (s.fresh && s.stable && s.weight >= s.min && armed && field && !field.value.trim()) { armed = false; readPlate(false); }
        if (s.fresh && s.weight < s.min) { armed = true; }
      }
      render();
    } catch (e) {}
  }
  tick(); setInterval(tick, 700);
})();

// Setup page: live daemon panel for one scale
(function () {
  const d = document.querySelector('[data-live="weight"]');
  if (!d) return;
  const id = d.dataset.scale;
  async function t() {
    try {
      const s = ((await (await fetch('api/live.php', {cache: 'no-store'})).json()).scales || []).find(x => String(x.id) === id);
      if (!s) return;
      d.firstChild.nodeValue = s.fresh ? Number(s.weight).toLocaleString('en-IN', {maximumFractionDigits: 2}) : '---';
      const st = document.querySelector('[data-live="stable"]'); st.className = 'badge ' + (!s.fresh ? 'bad' : s.stable ? 'ok' : 'warn'); st.textContent = !s.fresh ? 'NO DATA' : s.stable ? 'STABLE' : 'MOTION';
      document.querySelector('[data-live="msg"]').textContent = s.daemon_alive ? (s.message || s.status) : 'Reader not running (start bin/scale_supervisor.php)';
      document.querySelector('[data-live="raw"]').textContent = s.raw || '';
    } catch (e) {}
  }
  t(); setInterval(t, 800);
})();
