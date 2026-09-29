// Live weight polling: any element with [data-live] gets updated.
(function () {
  const disp = document.querySelector('[data-live="weight"]');
  if (!disp) return;
  const st = document.querySelector('[data-live="stable"]'), msg = document.querySelector('[data-live="msg"]');
  const raw = document.querySelector('[data-live="raw"]');
  async function tick() {
    try {
      const r = await (await fetch('api/live.php', {cache: 'no-store'})).json();
      disp.firstChild.nodeValue = r.fresh ? Number(r.weight).toLocaleString('en-IN', {minimumFractionDigits: 0, maximumFractionDigits: 2}) : '---';
      if (st) {
        st.className = 'badge ' + (!r.fresh ? 'bad' : r.stable ? 'ok' : 'warn');
        st.textContent = !r.fresh ? 'NO DATA' : r.stable ? 'STABLE' : 'MOTION';
      }
      if (msg) msg.textContent = r.daemon_alive ? (r.message || r.status) : 'Scale daemon is not running';
      if (raw) raw.textContent = r.raw || '';
      document.querySelectorAll('[data-needs-stable]').forEach(b => b.disabled = !(r.fresh && r.stable));
    } catch (e) { if (msg) msg.textContent = 'Server unreachable'; }
  }
  tick(); setInterval(tick, 700);
})();
