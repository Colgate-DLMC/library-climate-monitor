/* Library Climate dashboard. No build step: Chart.js (+ date-fns adapter) from jsDelivr, plain JS.
   All server-supplied text (device names etc.) is inserted with textContent, never innerHTML. */
(() => {
  'use strict';

  const API = 'api.php';
  const REFRESH_MS = 60_000;
  const LOW_BATT_V = 3.55;               // Li-ion: roughly 10-15% left; ESP32 browns out near 3.0-3.3 V
  const RANGES = { '24h': 24, '7d': 168, '30d': 720, '90d': 2160 };

  // ---------- state ----------
  const store = {
    get(k, d) { try { return localStorage.getItem('lcm.' + k) ?? d; } catch { return d; } },
    set(k, v) { try { localStorage.setItem('lcm.' + k, v); } catch { /* private mode etc. */ } },
  };
  const state = {
    range: RANGES[store.get('range', '24h')] ? store.get('range', '24h') : '24h',
    unit: store.get('unit', 'F') === 'C' ? 'C' : 'F',
    view: store.get('view', 'chart') === 'table' ? 'table' : 'chart',
    devices: [],            // from api.php?action=devices (sorted; index = colour slot)
    data: null,             // from api.php?action=readings
    hidden: new Set(),      // device ids toggled off in the legend
    loading: false,
  };

  // ---------- helpers ----------
  const $ = (id) => document.getElementById(id);
  const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  const slotColor = (i) => css(`--s${(i % 8) + 1}`);          // colour follows the device, never its rank in a filtered view
  const cToF = (c) => (c * 9) / 5 + 32;
  const temp = (c) => (c == null ? null : state.unit === 'F' ? cToF(c) : c);
  const fmtT = (c, d = 1) => (c == null ? '–' : temp(c).toFixed(d));
  const fmtRh = (v) => (v == null ? '–' : v.toFixed(0));
  const unitSym = () => '°' + state.unit;

  function el(tag, attrs = {}, ...kids) {
    const n = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') n.className = v;
      else if (k === 'style') n.setAttribute('style', v);
      else if (v !== false && v != null) n.setAttribute(k, v);
    }
    for (const kid of kids) n.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
    return n;
  }

  function ago(sec) {
    if (sec == null) return 'never';
    if (sec < 90) return 'just now';
    if (sec < 5400) return Math.round(sec / 60) + ' min ago';
    if (sec < 172800) return Math.round(sec / 3600) + ' h ago';
    return Math.round(sec / 86400) + ' days ago';
  }

  const STATUS = {
    ok: { icon: '✓', label: 'Reporting' },
    late: { icon: '▲', label: 'Late' },
    offline: { icon: '✕', label: 'Offline' },
  };

  // ---------- controls ----------
  function bindSeg(id, key, onChange) {
    const group = $(id);
    const sync = () => group.querySelectorAll('button').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.v === state[key])));
    group.addEventListener('click', (e) => {
      const b = e.target.closest('button');
      if (!b || b.dataset.v === state[key]) return;
      state[key] = b.dataset.v;
      store.set(key, state[key]);
      sync();
      onChange();
    });
    sync();
  }

  // ---------- data ----------
  async function getJSON(url) {
    const r = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
    if (!r.ok) throw new Error(`${url} -> HTTP ${r.status}`);
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'API error');
    return j;
  }

  async function refresh() {
    if (state.loading) return;
    state.loading = true;
    document.querySelectorAll('.plot').forEach((p) => p.classList.add('loading'));
    try {
      const hours = RANGES[state.range];
      const [devs, reads] = await Promise.all([
        getJSON(`${API}?action=devices`),
        getJSON(`${API}?action=readings&hours=${hours}`),
      ]);
      state.devices = devs.devices;
      state.data = reads;
      state.serverTime = devs.server_time;
      $('notice').hidden = true;
      renderAll();
    } catch (err) {
      console.error(err);
      const n = $('notice');
      n.textContent = 'Could not load the latest data. Showing what was loaded previously; will retry.';
      n.hidden = false;
    } finally {
      state.loading = false;
      document.querySelectorAll('.plot').forEach((p) => p.classList.remove('loading'));
    }
  }

  // ---------- rendering: cards, legend, table, footer ----------
  function renderAll() {
    $('csv').href = `${API}?action=csv&hours=${RANGES[state.range]}`;
    $('unit-t').textContent = unitSym();
    renderCards();
    renderLegend();
    renderTable();
    renderFooter();
    $('chart-view').hidden = state.view !== 'chart';
    $('table-view').hidden = state.view !== 'table';
    if (state.view === 'chart') renderCharts();

    if (!state.devices.length) {
      const n = $('notice');
      n.textContent = 'No sensors are registered yet. See docs/DEPLOY.md to add your first device.';
      n.hidden = false;
    }
  }

  function renderCards() {
    const root = $('cards');
    root.replaceChildren();
    const now = state.serverTime;
    state.devices.forEach((d, i) => {
      const st = STATUS[d.status] || STATUS.offline;
      const stats = state.data?.stats?.[d.id];
      const low = d.batt_v != null && d.batt_v < LOW_BATT_V;
      const card = el('article', { class: 'card', style: `--c:${slotColor(i)}` },
        el('span', { class: 'key', 'aria-hidden': 'true' }),
        el('h3', {}, d.name),
        el('div', { class: 'hero' }, fmtT(d.temp_c), el('small', {}, d.temp_c == null ? '' : unitSym())),
        el('div', { class: 'sub' },
          el('span', {}, `${fmtRh(d.rh)}% RH`),
          d.pressure_hpa != null ? el('span', {}, `${d.pressure_hpa.toFixed(0)} hPa`) : '',
          d.batt_v != null ? el('span', {}, `${d.batt_v.toFixed(2)} V`) : '',
        ),
        stats && stats.temp_c.min != null
          ? el('div', { class: 'range-line' }, `${state.range}: ${fmtT(stats.temp_c.min)}–${fmtT(stats.temp_c.max)}${unitSym()} (avg ${fmtT(stats.temp_c.avg)})`)
          : '',
        el('div', { class: `status ${d.status}` },
          el('span', { class: 'ic', 'aria-hidden': 'true' }, st.icon),
          el('span', {}, `${st.label} · last report ${ago(d.last_seen == null ? null : now - d.last_seen)}`)),
        low ? el('div', { class: 'status' }, el('span', { class: 'ic flag', 'aria-hidden': 'true' }, '▲'), el('span', { class: 'flag' }, 'Low battery')) : '',
      );
      root.append(card);
    });
  }

  function renderLegend() {
    const root = $('legend');
    root.replaceChildren();
    if (state.devices.length < 2) return;       // one series: the title names it, no legend box
    state.devices.forEach((d, i) => {
      const b = el('button', { type: 'button', 'aria-pressed': String(!state.hidden.has(d.id)), style: `--c:${slotColor(i)}` },
        el('span', { class: 'line', 'aria-hidden': 'true' }), d.name);
      b.addEventListener('click', () => {
        state.hidden.has(d.id) ? state.hidden.delete(d.id) : state.hidden.add(d.id);
        renderLegend();
        renderCharts();
      });
      root.append(b);
    });
  }

  function renderTable() {
    const t = $('table');
    t.replaceChildren();
    const head = ['Location', `Now (${unitSym()})`, `Min`, 'Avg', 'Max', 'RH now', 'RH min–max', 'Battery', 'Last report', 'Status'];
    t.append(el('thead', {}, el('tr', {}, ...head.map((h) => el('th', { scope: 'col' }, h)))));
    const body = el('tbody');
    state.devices.forEach((d, i) => {
      const s = state.data?.stats?.[d.id];
      const st = STATUS[d.status] || STATUS.offline;
      const rh = s && s.rh.min != null ? `${fmtRh(s.rh.min)}–${fmtRh(s.rh.max)}%` : '–';
      body.append(el('tr', {},
        el('td', { style: `--c:${slotColor(i)}` }, el('span', { class: 'key', 'aria-hidden': 'true' }), d.name),
        el('td', {}, fmtT(d.temp_c)),
        el('td', {}, s ? fmtT(s.temp_c.min) : '–'),
        el('td', {}, s ? fmtT(s.temp_c.avg) : '–'),
        el('td', {}, s ? fmtT(s.temp_c.max) : '–'),
        el('td', {}, d.rh == null ? '–' : fmtRh(d.rh) + '%'),
        el('td', {}, rh),
        el('td', {}, d.batt_v == null ? '–' : d.batt_v.toFixed(2) + ' V'),
        el('td', {}, ago(d.last_seen == null ? null : state.serverTime - d.last_seen)),
        el('td', {}, `${st.icon} ${st.label}`),
      ));
    });
    t.append(body);
    t.append(el('caption', { class: 'sr-only', style: 'position:absolute;left:-9999px' }, `Summary for the last ${state.range}`));
  }

  function renderFooter() {
    const n = state.data ? Object.values(state.data.stats).reduce((a, s) => a + s.n, 0) : 0;
    $('foot').textContent =
      `Updated ${new Date().toLocaleTimeString()} · refreshes every minute · ${n.toLocaleString()} readings in range · ` +
      `chart points are ${Math.round((state.data?.bucket_s || 300) / 60)}-minute averages; min/max use raw readings · times in your local time zone`;
  }

  // ---------- charts ----------
  const charts = {};     // key -> Chart

  const hoverPlugin = {
    id: 'hover',
    afterDatasetsDraw(chart) {
      const h = chart.$hover;
      if (!h) return;
      const { ctx, chartArea: a } = chart;
      ctx.save();
      ctx.strokeStyle = css('--axis');
      ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(h.x, a.top); ctx.lineTo(h.x, a.bottom); ctx.stroke();
      for (const r of h.rows) {
        ctx.beginPath(); ctx.arc(h.x, r.y, 4.5, 0, Math.PI * 2);
        ctx.fillStyle = r.color; ctx.fill();
        ctx.lineWidth = 2; ctx.strokeStyle = css('--surface-1'); ctx.stroke();   // 2px surface ring
      }
      ctx.restore();
    },
  };

  // Direct labels at the right edge when four or fewer series are visible.
  const endLabelPlugin = {
    id: 'endLabels',
    afterDatasetsDraw(chart) {
      if (!chart.$labelPad) return;
      const { ctx, chartArea: a, scales } = chart;
      const items = [];
      chart.data.datasets.forEach((ds, i) => {
        if (!chart.isDatasetVisible(i)) return;
        const last = [...ds.data].reverse().find((p) => p.y != null);
        if (!last || last.x < scales.x.max - ds.$gapMs * 1.5) return;     // line ended long ago: legend covers it
        items.push({ y: scales.y.getPixelForValue(last.y), text: ds.label, color: ds.borderColor });
      });
      items.sort((p, q) => p.y - q.y);
      for (let i = 1; i < items.length; i++) items[i].y = Math.max(items[i].y, items[i - 1].y + 15);   // nudge apart
      ctx.save();
      ctx.font = '12px system-ui, sans-serif';
      ctx.textBaseline = 'middle';
      for (const it of items) {
        let t = it.text;
        while (t.length > 3 && ctx.measureText(t).width > chart.$labelPad - 18) t = t.slice(0, -2) + '…';
        ctx.fillStyle = it.color; ctx.fillRect(a.right + 4, it.y - 1, 8, 2);      // colour key
        ctx.fillStyle = css('--text-secondary'); ctx.fillText(t, a.right + 16, it.y);
      }
      ctx.restore();
    },
  };

  function makeChart(key, canvasId, tipId, yFmt) {
    const canvas = $(canvasId);
    const chart = new Chart(canvas, {
      type: 'line',
      data: { datasets: [] },
      plugins: [hoverPlugin, endLabelPlugin],
      options: {
        responsive: true, maintainAspectRatio: false, animation: false, parsing: false, normalized: true,
        events: [],                                           // we run our own pointer/keyboard layer
        layout: { padding: { right: 0, top: 6 } },
        plugins: { legend: { display: false }, tooltip: { enabled: false } },
        scales: {
          x: { type: 'time', time: { displayFormats: { minute: 'h:mm a', hour: 'h a', day: 'MMM d', week: 'MMM d', month: 'MMM yyyy' } },
               grid: { drawTicks: false }, ticks: { maxRotation: 0, autoSkipPadding: 18 }, border: {} },
          y: { grace: '8%', grid: {}, border: { display: false }, ticks: { callback: yFmt, maxTicksLimit: 6 } },
        },
      },
    });
    chart.$key = key;
    chart.$tip = $(tipId);
    chart.$maps = [];
    attachHover(chart);
    return chart;
  }

  function applyTheme(chart) {
    const o = chart.options.scales;
    o.x.grid.color = css('--grid'); o.y.grid.color = css('--grid');
    o.x.border.color = css('--axis');
    o.x.ticks.color = css('--text-muted'); o.y.ticks.color = css('--text-muted');
  }

  function buildDatasets(chart, field) {
    const data = state.data;
    const bucket = data.bucket_s;
    chart.$maps = [];
    const sets = [];
    state.devices.forEach((d, i) => {
      const s = data.series[d.id];
      const color = slotColor(i);
      const gap = Math.max(bucket * 2.5, d.interval_s * 3);
      const pts = [];
      const map = new Map();
      let prev = null;
      if (s) {
        for (let k = 0; k < s.t.length; k++) {
          const raw = s[field][k];
          if (raw == null) continue;
          const v = field === 'temp_c' ? temp(raw) : raw;
          if (prev !== null && s.t[k] - prev > gap) pts.push({ x: (prev + 1) * 1000, y: null });   // break the line across outages
          pts.push({ x: s.t[k] * 1000, y: v });
          map.set(s.t[k] * 1000, v);
          prev = s.t[k];
        }
      }
      chart.$maps.push(map);
      sets.push({
        label: d.name, data: pts, borderColor: color, backgroundColor: color, borderWidth: 2,
        pointRadius: 0, pointHoverRadius: 0, tension: 0, spanGaps: false,
        hidden: state.hidden.has(d.id), $gapMs: gap * 1000,
      });
    });
    chart.data.datasets = sets;
  }

  function renderCharts() {
    if (!state.data || typeof Chart === 'undefined') return;
    if (!charts.temp) {
      charts.temp = makeChart('temp', 'c-temp', 'tip-temp', (v) => `${Math.round(v * 10) / 10}°`);
      charts.rh = makeChart('rh', 'c-rh', 'tip-rh', (v) => `${Math.round(v)}%`);
    }
    const visible = state.devices.filter((d) => !state.hidden.has(d.id)).length;
    const labelPad = visible > 1 && visible <= 4 ? 112 : 0;
    for (const [key, field] of [['temp', 'temp_c'], ['rh', 'rh']]) {
      const c = charts[key];
      applyTheme(c);
      buildDatasets(c, field);
      c.options.scales.x.min = state.data.from * 1000;
      c.options.scales.x.max = state.data.to * 1000;
      c.options.layout.padding.right = labelPad;
      c.$labelPad = labelPad;
      c.$hover = null;
      c.$tip.hidden = true;
      c.update('none');
    }
  }

  // ---------- hover / keyboard layer ----------
  function attachHover(chart) {
    const canvas = chart.canvas;
    canvas.tabIndex = 0;

    const clear = () => { chart.$hover = null; chart.$tip.hidden = true; chart.draw(); };

    function showAt(bucketTime) {                              // bucketTime in ms
      const rows = [];
      chart.data.datasets.forEach((ds, i) => {
        if (!chart.isDatasetVisible(i)) return;
        const v = chart.$maps[i].get(bucketTime);
        if (v != null) rows.push({ ds, v });
      });
      if (!rows.length) return clear();
      const { x: xs, y: ys } = chart.scales;
      chart.$hover = { x: xs.getPixelForValue(bucketTime), t: bucketTime,
                       rows: rows.map((r) => ({ y: ys.getPixelForValue(r.v), color: r.ds.borderColor })) };
      chart.draw();

      const isT = chart.$key === 'temp';
      const tip = chart.$tip;
      tip.replaceChildren(
        el('div', { class: 'when' }, new Date(bucketTime).toLocaleString([], { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })),
        ...rows.map((r) => el('div', { class: 'row', style: `--c:${r.ds.borderColor}` },
          el('span', { class: 'k', 'aria-hidden': 'true' }),
          el('span', { class: 'v' }, isT ? `${r.v.toFixed(1)}${unitSym()}` : `${r.v.toFixed(0)}%`),
          el('span', { class: 'n' }, r.ds.label))));
      tip.hidden = false;
      const w = tip.offsetWidth, hpx = canvas.clientWidth;
      let left = chart.$hover.x + 14;
      if (left + w > hpx) left = chart.$hover.x - w - 14;
      tip.style.left = Math.max(4, left) + 'px';
      tip.style.top = '8px';
    }

    const snap = (ms) => {
      const b = state.data.bucket_s * 1000;
      return Math.round(ms / b) * b;
    };

    function onPointer(ev) {
      if (!state.data) return;
      const rect = canvas.getBoundingClientRect();
      const x = ev.clientX - rect.left;
      const a = chart.chartArea;
      if (x < a.left || x > a.right) return clear();
      showAt(snap(chart.scales.x.getValueForPixel(x)));
    }
    canvas.addEventListener('pointermove', onPointer);
    canvas.addEventListener('pointerdown', onPointer);
    canvas.addEventListener('pointerleave', clear);
    canvas.addEventListener('blur', clear);

    canvas.addEventListener('keydown', (ev) => {
      if (!state.data) return;
      const step = state.data.bucket_s * 1000;
      const cur = chart.$hover ? chart.$hover.t : snap(chart.scales.x.max);
      let next = null;
      if (ev.key === 'ArrowLeft') next = cur - step;
      else if (ev.key === 'ArrowRight') next = cur + step;
      else if (ev.key === 'Home') next = snap(chart.scales.x.min);
      else if (ev.key === 'End') next = snap(chart.scales.x.max);
      else if (ev.key === 'Escape') return clear();
      if (next == null) return;
      ev.preventDefault();
      showAt(Math.min(Math.max(next, chart.scales.x.min), chart.scales.x.max));
    });
  }

  // ---------- boot ----------
  bindSeg('range', 'range', refresh);
  bindSeg('unit', 'unit', () => { renderAll(); });
  bindSeg('view', 'view', () => { renderAll(); });

  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (state.data) renderAll(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  setInterval(() => { if (!document.hidden) refresh(); }, REFRESH_MS);
  window.addEventListener('load', refresh);
})();
