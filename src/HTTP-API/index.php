<?php
// Paxcounter dashboard. Data comes from api.php (same session).
declare(strict_types=1);
require __DIR__ . '/lib.php';

session_begin();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        if (password_verify((string)($_POST['password'] ?? ''), DASHBOARD_PASSWORD_HASH)) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            header('Location: ' . $_SERVER['SCRIPT_NAME'], true, 303);
            exit;
        }
        sleep(2); // slow down guessing
        $error = 'Wrong password';
    } elseif ($action === 'logout'
              && hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . $_SERVER['SCRIPT_NAME'], true, 303);
        exit;
    }
}

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce'; "
     . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Paxcounter</title>
<style>
:root {
  color-scheme: light;
  --page: #f9f9f7; --surface-1: #fcfcfb; --surface-2: #f0efec;
  --text-primary: #0b0b0b; --text-secondary: #52514e; --text-muted: #898781;
  --grid: #e1e0d9; --axis: #c3c2b7; --border: rgba(11,11,11,0.10);
  --series-1: #2a78d6; --series-2: #eb6834; --series-3: #1baf7a;
  --good: #0ca30c; --critical: #d03b3b;
  --group-1: #6250d6; --group-2: #eda100; --group-3: #e87ba4;
  --seq-0: #cde2fb; --seq-1: #9ec5f4; --seq-2: #6da7ec; --seq-3: #3987e5;
  --seq-4: #256abf; --seq-5: #184f95; --seq-6: #0d366b;
}
@media (prefers-color-scheme: dark) {
  :root {
    color-scheme: dark;
    --page: #0d0d0d; --surface-1: #1a1a19; --surface-2: #262624;
    --text-primary: #f0efec; --text-secondary: #c3c2b7; --text-muted: #898781;
    --grid: #2c2c2a; --axis: #383835; --border: rgba(255,255,255,0.10);
    --series-1: #3987e5; --series-2: #d95926; --series-3: #199e70;
    --group-1: #9085e9; --group-2: #c98500; --group-3: #d55181;
    /* dark: low values recede toward the dark surface */
    --seq-0: #104281; --seq-1: #184f95; --seq-2: #256abf; --seq-3: #3987e5;
    --seq-4: #6da7ec; --seq-5: #9ec5f4; --seq-6: #cde2fb;
  }
}
* { box-sizing: border-box; }
body {
  margin: 0; background: var(--page); color: var(--text-primary);
  font: 14px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif;
}
main { max-width: 1200px; margin: 0 auto; padding: 20px 16px 48px; }
header { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
h1 { font-size: 20px; margin: 0 auto 0 0; font-weight: 600; }
h2 { font-size: 15px; margin: 0 0 2px; font-weight: 600; }
.sub { color: var(--text-secondary); font-size: 13px; margin: 0 0 12px; }
.card {
  background: var(--surface-1); border: 1px solid var(--border);
  border-radius: 12px; padding: 16px; margin-bottom: 16px;
}
.filters { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
select, button, input {
  font: inherit; color: var(--text-primary); background: var(--surface-1);
  border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px;
}
button { cursor: pointer; }
button:hover { background: var(--surface-2); }
.seg { display: inline-flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
.seg button { border: 0; border-radius: 0; }
.seg button + button { border-left: 1px solid var(--border); }
.seg button[aria-pressed="true"] { background: var(--surface-2); font-weight: 600; }
.status { display: inline-flex; align-items: center; gap: 6px; color: var(--text-secondary); font-size: 13px; }
.status svg { flex: none; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 16px; }
.tiles .card { margin: 0; }
.tile-label { color: var(--text-secondary); font-size: 13px; }
.tile-value { font-size: 32px; font-weight: 600; line-height: 1.2; margin-top: 4px; }
.tile-value.hero { font-size: 52px; }
.tile-note { color: var(--text-secondary); font-size: 13px; margin-top: 4px; }
.legend { display: flex; gap: 16px; flex-wrap: wrap; font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; }
.legend span { display: inline-flex; align-items: center; gap: 6px; }
.key { width: 14px; height: 2px; border-radius: 1px; display: inline-block; }
.chart { position: relative; }
.chart svg { display: block; width: 100%; overflow: visible; }
.chart svg:focus { outline: none; }
.chart svg:focus-visible { outline: 2px solid var(--series-1); outline-offset: 4px; border-radius: 4px; }
.axis-text { fill: var(--text-muted); font-size: 11px; font-variant-numeric: tabular-nums; }
.end-label { fill: var(--text-secondary); font-size: 12px; }
.tooltip {
  position: absolute; pointer-events: none; z-index: 2; display: none;
  background: var(--surface-1); border: 1px solid var(--border); border-radius: 8px;
  box-shadow: 0 4px 16px rgba(0,0,0,0.12); padding: 8px 10px; font-size: 13px; white-space: nowrap;
}
.tooltip .tt-head { color: var(--text-secondary); margin-bottom: 4px; }
.tooltip .tt-row { display: flex; align-items: center; gap: 8px; }
.tooltip .tt-row b { font-variant-numeric: tabular-nums; min-width: 3ch; }
.tooltip .tt-row span { color: var(--text-secondary); }
.heat-cell:hover, .heat-cell:focus { stroke: var(--text-primary); stroke-width: 1.5; outline: none; }
.scale { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted); margin-top: 8px; }
.scale i { width: 16px; height: 10px; border-radius: 2px; display: inline-block; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--grid); }
th { color: var(--text-secondary); font-weight: 600; }
td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
td.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.table-wrap { max-height: 420px; overflow: auto; }
details summary { cursor: pointer; color: var(--text-secondary); font-size: 13px; margin-top: 8px; }
.empty { color: var(--text-muted); padding: 32px 0; text-align: center; }
.loading .chart, .loading .tiles, .loading .table-wrap { opacity: 0.5; transition: opacity .2s; }
.login { max-width: 320px; margin: 15vh auto; }
.login input { width: 100%; margin: 8px 0 12px; }
.login .err { color: var(--critical); font-size: 13px; }
h3 { font-size: 14px; margin: 20px 0 2px; font-weight: 600; }
.groups { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin: 4px 0 8px; }
.group { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; }
.group-name { display: flex; align-items: center; gap: 8px; color: var(--text-secondary); font-size: 13px; }
.group-name i { width: 10px; height: 10px; border-radius: 3px; display: inline-block; flex: none; }
.group-value { font-size: 24px; font-weight: 600; margin-top: 2px; }
.group-note { color: var(--text-secondary); font-size: 12px; }
.muted { color: var(--text-muted); }
.status-cell { display: inline-flex; align-items: center; gap: 6px; }
.tl-label { fill: var(--text-secondary); font-size: 11px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.grid-2 { display: grid; grid-template-columns: 1fr; gap: 16px; }
</style>
</head>
<body>
<?php if (!logged_in()): ?>
<main>
  <form class="card login" method="post">
    <h1>Paxcounter</h1>
    <input type="hidden" name="action" value="login">
    <label for="pw" class="sub">Password</label>
    <input id="pw" type="password" name="password" autocomplete="current-password" autofocus required>
    <?php if ($error): ?><p class="err" role="alert"><?= $h($error) ?></p><?php endif ?>
    <button type="submit">Sign in</button>
  </form>
</main>
<?php else: ?>
<main id="app">
  <header>
    <h1>Paxcounter</h1>
    <span class="status" id="status"></span>
    <form method="post">
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="csrf" value="<?= $h(csrf_token()) ?>">
      <button type="submit">Sign out</button>
    </form>
  </header>

  <div class="filters">
    <div class="seg" role="group" aria-label="Time range" id="ranges">
      <button data-range="24h">24 hours</button>
      <button data-range="7d">7 days</button>
      <button data-range="30d">30 days</button>
      <button data-range="90d">90 days</button>
    </div>
    <label class="sub" for="device" style="margin:0 0 0 8px">Device</label>
    <select id="device"></select>
  </div>

  <div class="tiles">
    <div class="card"><div class="tile-label">Count now</div>
      <div class="tile-value hero" id="t-now">–</div>
      <div class="tile-note" id="t-now-note"></div></div>
    <div class="card"><div class="tile-label">Peak</div>
      <div class="tile-value" id="t-peak">–</div>
      <div class="tile-note" id="t-peak-note"></div></div>
    <div class="card"><div class="tile-label">Average</div>
      <div class="tile-value" id="t-avg">–</div>
      <div class="tile-note" id="t-avg-note"></div></div>
    <div class="card"><div class="tile-label">Unique devices seen</div>
      <div class="tile-value" id="t-macs">–</div>
      <div class="tile-note" id="t-macs-note"></div></div>
  </div>

  <section class="card">
    <h2>Count over time</h2>
    <p class="sub" id="series-sub"></p>
    <div class="legend">
      <span><i class="key" style="background:var(--series-1)"></i>Total</span>
      <span><i class="key" style="background:var(--series-2)"></i>WiFi</span>
      <span><i class="key" style="background:var(--series-3)"></i>Bluetooth</span>
    </div>
    <div class="chart" id="series"></div>
    <details><summary>Show data table</summary>
      <div class="table-wrap"><table id="series-table"></table></div>
    </details>
  </section>

  <section class="card">
    <h2>Typical week</h2>
    <p class="sub">Average count by weekday and hour, last 28 days (Swiss time)</p>
    <div class="chart" id="heatmap"></div>
  </section>

  <section class="card">
    <h2>Device groups</h2>
    <p class="sub" id="groups-sub"></p>
    <div class="groups" id="group-tiles"></div>
    <h3>When each group is present</h3>
    <p class="sub" id="groups-chart-sub"></p>
    <div class="legend">
      <span><i class="key" style="background:var(--group-1)"></i>Always present</span>
      <span><i class="key" style="background:var(--group-2)"></i>Recurring</span>
      <span><i class="key" style="background:var(--group-3)"></i>One-time</span>
    </div>
    <div class="chart" id="groups-chart"></div>
    <h3>Most present devices</h3>
    <p class="sub" id="timeline-sub"></p>
    <div class="chart" id="timeline"></div>
    <details><summary>Show data table</summary>
      <div class="table-wrap"><table id="timeline-table"></table></div>
    </details>
  </section>

  <section class="card">
    <h2>Nearby WiFi networks</h2>
    <p class="sub" id="nets-sub">Access points whose beacons the paxcounter received</p>
    <div class="table-wrap"><table id="nets-table"></table></div>
  </section>

  <section class="card">
    <h2>Detected devices</h2>
    <p class="sub" id="macs-sub">Most frequently seen MAC addresses in the selected range</p>
    <div class="table-wrap"><table id="macs-table"></table></div>
  </section>
</main>

<script nonce="<?= $nonce ?>">
'use strict';
const ONLINE_SEC = 300;          // device counts as online if it reported recently
const REFRESH_MS = 60000;
const SERIES = [
  { key: 'pax',  idx: 1, name: 'Total',     color: 'var(--series-1)' },
  { key: 'wifi', idx: 2, name: 'WiFi',      color: 'var(--series-2)' },
  { key: 'ble',  idx: 3, name: 'Bluetooth', color: 'var(--series-3)' },
];
const GROUPS = [
  { key: 'stationary', idx: 1, name: 'Always present', color: 'var(--group-1)',
    note: 'present most of the time, e.g. routers, TVs, neighbours' },
  { key: 'recurring',  idx: 2, name: 'Recurring',      color: 'var(--group-2)',
    note: 'came back on 2 or more separate visits' },
  { key: 'once',       idx: 3, name: 'One-time',       color: 'var(--group-3)',
    note: 'seen during a single visit only' },
];
const GROUP = Object.fromEntries(GROUPS.map(g => [g.key, g]));
const SVGNS = 'http://www.w3.org/2000/svg';
const $ = id => document.getElementById(id);
const nf = new Intl.NumberFormat(undefined, { maximumFractionDigits: 1 });
const fmt = v => v == null ? '–' : nf.format(v);

const state = {
  range: localStorage.getItem('pax.range') || '24h',
  device: localStorage.getItem('pax.device') || '',
  devices: [], series: null, heat: null, macs: null, groups: null, nets: null, now: 0,
};

// ---------- helpers ----------
function el(tag, attrs = {}, parent) {
  const n = tag.startsWith('svg:')
    ? document.createElementNS(SVGNS, tag.slice(4)) : document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'text') n.textContent = v; else n.setAttribute(k, v);
  }
  if (parent) parent.appendChild(n);
  return n;
}
async function api(params) {
  const r = await fetch('api.php?' + new URLSearchParams(params), { credentials: 'same-origin' });
  if (r.status === 401) { location.reload(); throw new Error('logged out'); }
  if (!r.ok) throw new Error((await r.json().catch(() => ({}))).error || r.statusText);
  return r.json();
}
function ago(sec) {
  if (sec < 90) return `${Math.max(0, Math.round(sec))} s ago`;
  if (sec < 5400) return `${Math.round(sec / 60)} min ago`;
  if (sec < 172800) return `${Math.round(sec / 3600)} h ago`;
  return `${Math.round(sec / 86400)} days ago`;
}
// Always Swiss time, independent of the browser's (possibly spoofed) timezone
const TZ = 'Europe/Zurich', LOC = 'en-GB';
const dtf = new Intl.DateTimeFormat(LOC, { timeZone: TZ, weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
const tf  = new Intl.DateTimeFormat(LOC, { timeZone: TZ, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
const df  = new Intl.DateTimeFormat(LOC, { timeZone: TZ, day: 'numeric', month: 'short' });
const pf  = new Intl.DateTimeFormat('en-US', { timeZone: TZ, hourCycle: 'h23', weekday: 'short',
  year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric' });
// Zurich wall-clock parts of a unix time: { wd (Mon=0), hour, off (seconds east of UTC) }
function zurich(t) {
  const p = {};
  for (const x of pf.formatToParts(new Date(t * 1000))) p[x.type] = x.value;
  const wall = Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour % 24, +p.minute, +p.second) / 1000;
  return { wd: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(p.weekday), hour: +p.hour % 24, off: wall - t };
}
const fullDate = t => dtf.format(new Date(t * 1000));
function niceStep(max, count) {
  const raw = max / count, p = 10 ** Math.floor(Math.log10(raw)), f = raw / p;
  return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p;
}

// ---------- status + tiles ----------
function renderStatus() {
  const s = $('status'); s.textContent = '';
  const d = state.devices.find(x => x.device === state.device);
  if (!d) return;
  const age = state.now - d.t, online = age < ONLINE_SEC;
  const svg = el('svg:svg', { width: 14, height: 14, viewBox: '0 0 14 14', 'aria-hidden': 'true' }, s);
  if (online) {
    el('svg:circle', { cx: 7, cy: 7, r: 6, fill: 'var(--good)' }, svg);
    el('svg:path', { d: 'M4 7.2l2 2 4-4.2', stroke: '#fff', 'stroke-width': 1.6, fill: 'none', 'stroke-linecap': 'round' }, svg);
  } else {
    el('svg:circle', { cx: 7, cy: 7, r: 6, fill: 'var(--critical)' }, svg);
    el('svg:path', { d: 'M7 3.8v4M7 10.2v0.1', stroke: '#fff', 'stroke-width': 1.8, 'stroke-linecap': 'round' }, svg);
  }
  el('span', { text: `${online ? 'Online' : 'Offline'} · last report ${ago(age)}` }, s);
}

function renderTiles() {
  const d = state.devices.find(x => x.device === state.device);
  const fresh = d && state.now - d.t < ONLINE_SEC;
  $('t-now').textContent = d ? fmt(d.pax) : '–';
  $('t-now-note').textContent = d
    ? `${fmt(d.wifi)} WiFi · ${fmt(d.ble)} Bluetooth${fresh ? '' : ' · ' + ago(state.now - d.t)}` : '';

  const s = state.series;
  if (s && s.peak) {
    $('t-peak').textContent = fmt(s.peak.pax);
    $('t-peak-note').textContent = fullDate(s.peak.t);
  } else { $('t-peak').textContent = '–'; $('t-peak-note').textContent = ''; }
  if (s && s.points.length) {
    let sum = 0, n = 0;
    for (const p of s.points) { sum += p[1]; n++; }
    $('t-avg').textContent = fmt(Math.round(sum / n * 10) / 10);
    $('t-avg-note').textContent = "over the selected range";
  } else { $('t-avg').textContent = '–'; $('t-avg-note').textContent = ''; }

  const m = state.macs && state.macs.summary;
  if (m) {
    $('t-macs').textContent = fmt(m.total);
    const pct = m.total ? Math.round(m.random / m.total * 100) : 0;
    $('t-macs-note').textContent = `${fmt(m.wifi)} WiFi · ${fmt(m.ble)} Bluetooth · ${pct}% randomized`;
  }
}
const bucketName = b => b >= 86400 ? 'day' : b >= 3600 ? (b / 3600 === 1 ? 'hour' : `${b / 3600} hours`) : `${b / 60} min`;

// ---------- line chart ----------
function renderSeries() {
  const box = $('series'); box.textContent = '';
  const s = state.series;
  $('series-sub').textContent = s ? `Average per ${bucketName(s.bucket)}, unique devices per send cycle` : '';
  if (!s || !s.points.length) { el('div', { class: 'empty', text: 'No data in this range' }, box); renderSeriesTable(); return; }
  lineChart(box, s, SERIES, { area: true, aria: 'Count over time',
    extra: p => [[p[4], 'Total, max']] });
  renderSeriesTable();
}

// x axis ticks aligned to Swiss midnight, shared by line chart and timeline
function xTicks(svg, x0, x1, left, width, yAxis, yText) {
  const span = x1 - x0;
  const tickStep = span <= 86400 ? 3 * 3600 : span <= 7 * 86400 ? 86400 : span <= 30 * 86400 ? 5 * 86400 : 14 * 86400;
  const off = zurich(x0).off;
  for (let t = Math.ceil((x0 + off) / tickStep) * tickStep - off; t <= x1; t += tickStep) {
    const px = left + (t - x0) / span * width;
    el('svg:line', { x1: px, x2: px, y1: yAxis, y2: yAxis + 4, stroke: 'var(--axis)' }, svg);
    el('svg:text', { x: px, y: yText, 'text-anchor': 'middle', class: 'axis-text',
      text: span <= 86400 ? tf.format(new Date(t * 1000)) : df.format(new Date(t * 1000)) }, svg);
  }
}

// s = { points: [[t, v1, v2, ...]], bucket, from, now }; series = [{ idx, name, color }]
function lineChart(box, s, SERIES, opts = {}) {
  const W = Math.max(box.clientWidth, 320), H = 280;
  const m = { t: 12, r: Math.max(64, 44 + 7 * Math.max(...SERIES.map(x => x.name.length))), b: 26, l: 40 };
  const iw = W - m.l - m.r, ih = H - m.t - m.b;
  const pts = s.points;
  const x0 = s.from, x1 = s.now;
  const x = t => m.l + (t + s.bucket / 2 - x0) / (x1 - x0) * iw;
  let ymax = 0;
  for (const p of pts) for (const ser of SERIES) ymax = Math.max(ymax, p[ser.idx]);
  const step = niceStep(Math.max(ymax, 4), 4);
  ymax = Math.ceil(Math.max(ymax, 4) / step) * step;
  const y = v => m.t + ih - v / ymax * ih;

  const svg = el('svg:svg', { viewBox: `0 0 ${W} ${H}`, height: H, tabindex: 0, role: 'img',
    'aria-label': `${opts.aria}. Use left and right arrow keys to inspect values.` }, box);

  // grid + y ticks
  for (let v = 0; v <= ymax + 1e-9; v += step) {
    el('svg:line', { x1: m.l, x2: m.l + iw, y1: y(v), y2: y(v),
      stroke: v === 0 ? 'var(--axis)' : 'var(--grid)', 'stroke-width': 1, 'shape-rendering': 'crispEdges' }, svg);
    el('svg:text', { x: m.l - 8, y: y(v) + 4, 'text-anchor': 'end', class: 'axis-text', text: nf.format(v) }, svg);
  }
  xTicks(svg, x0, x1, m.l, iw, m.t + ih, H - 6);

  // split into runs, breaking the line where buckets are missing
  const runs = [];
  let run = [];
  for (let i = 0; i < pts.length; i++) {
    if (i && pts[i][0] - pts[i - 1][0] > s.bucket * 2.5) { runs.push(run); run = []; }
    run.push(pts[i]);
  }
  runs.push(run);

  // area wash under the first series
  if (opts.area) for (const r of runs) {
    if (r.length < 2) continue;
    const d = r.map((p, i) => `${i ? 'L' : 'M'}${x(p[0]).toFixed(1)},${y(p[1]).toFixed(1)}`).join('')
      + `L${x(r[r.length - 1][0]).toFixed(1)},${y(0)}L${x(r[0][0]).toFixed(1)},${y(0)}Z`;
    el('svg:path', { d, fill: SERIES[0].color, 'fill-opacity': 0.1 }, svg);
  }
  // lines (draw the first series last so it sits on top)
  for (const ser of [...SERIES].reverse()) {
    for (const r of runs) {
      const d = r.map((p, i) => `${i ? 'L' : 'M'}${x(p[0]).toFixed(1)},${y(p[ser.idx]).toFixed(1)}`).join('');
      if (r.length === 1) {
        el('svg:circle', { cx: x(r[0][0]), cy: y(r[0][ser.idx]), r: 2, fill: ser.color }, svg);
      } else {
        el('svg:path', { d, fill: 'none', stroke: ser.color, 'stroke-width': 2,
          'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg);
      }
    }
  }

  // end labels; drop the ones that would collide, legend + tooltip carry them
  const last = pts[pts.length - 1];
  const lx = x(last[0]);
  const labels = SERIES.map(ser => ({ ser, v: last[ser.idx], y: y(last[ser.idx]) }));
  const shown = [];
  for (const l of labels) {
    if (shown.every(o => Math.abs(o.y - l.y) >= 14)) shown.push(l);
  }
  for (const l of shown) {
    el('svg:circle', { cx: lx, cy: l.y, r: 4, fill: l.ser.color, stroke: 'var(--surface-1)', 'stroke-width': 2 }, svg);
    el('svg:text', { x: lx + 8, y: l.y + 4, class: 'end-label', text: `${fmt(l.v)} ${l.ser.name}` }, svg);
  }

  // hover layer: crosshair + tooltip
  const hover = el('svg:g', { 'pointer-events': 'none', visibility: 'hidden' }, svg);
  const cross = el('svg:line', { y1: m.t, y2: m.t + ih, stroke: 'var(--text-muted)', 'stroke-width': 1 }, hover);
  const dots = SERIES.map(ser => el('svg:circle', { r: 4, fill: ser.color, stroke: 'var(--surface-1)', 'stroke-width': 2 }, hover));
  const tip = el('div', { class: 'tooltip' }, box);
  let cur = -1;
  function show(i) {
    cur = i;
    const p = pts[i], px = x(p[0]);
    hover.setAttribute('visibility', 'visible');
    cross.setAttribute('x1', px); cross.setAttribute('x2', px);
    SERIES.forEach((ser, k) => { dots[k].setAttribute('cx', px); dots[k].setAttribute('cy', y(p[ser.idx])); });
    tip.textContent = '';
    const end = p[0] + s.bucket;
    el('div', { class: 'tt-head', text: s.bucket >= 86400 ? df.format(new Date(p[0] * 1000))
      : `${fullDate(p[0])} – ${tf.format(new Date(end * 1000))}` }, tip);
    for (const ser of SERIES) {
      const row = el('div', { class: 'tt-row' }, tip);
      el('i', { class: 'key', style: `background:${ser.color}` }, row);
      el('b', { text: fmt(p[ser.idx]) }, row);
      el('span', { text: ser.name }, row);
    }
    for (const [v, label] of (opts.extra ? opts.extra(p) : [])) {
      const row = el('div', { class: 'tt-row' }, tip);
      el('i', { class: 'key' }, row);
      el('b', { text: fmt(v) }, row);
      el('span', { text: label }, row);
    }
    tip.style.display = 'block';
    const scale = box.clientWidth / W;
    const tw = tip.offsetWidth;
    let left = px * scale + 12;
    if (left + tw > box.clientWidth) left = px * scale - tw - 12;
    tip.style.left = Math.max(0, left) + 'px';
    tip.style.top = m.t + 'px';
  }
  function hide() { cur = -1; hover.setAttribute('visibility', 'hidden'); tip.style.display = 'none'; }
  function nearest(px) {
    let best = 0, bd = Infinity;
    for (let i = 0; i < pts.length; i++) {
      const d = Math.abs(x(pts[i][0]) - px);
      if (d < bd) { bd = d; best = i; }
    }
    return best;
  }
  svg.addEventListener('pointermove', e => {
    const r = svg.getBoundingClientRect();
    show(nearest((e.clientX - r.left) * W / r.width));
  });
  svg.addEventListener('pointerleave', hide);
  svg.addEventListener('blur', hide);
  svg.addEventListener('keydown', e => {
    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
      e.preventDefault();
      const i = cur < 0 ? pts.length - 1 : cur + (e.key === 'ArrowLeft' ? -1 : 1);
      show(Math.min(pts.length - 1, Math.max(0, i)));
    } else if (e.key === 'Escape') hide();
  });
}

function renderSeriesTable() {
  const t = $('series-table'); t.textContent = '';
  const s = state.series;
  if (!s) return;
  const hr = el('tr', {}, el('thead', {}, t));
  for (const [h, c] of [['Time', ''], ['Total', 'num'], ['WiFi', 'num'], ['Bluetooth', 'num'], ['Total, max', 'num']])
    el('th', { class: c, text: h }, hr);
  const tb = el('tbody', {}, t);
  for (const p of [...s.points].reverse()) {
    const tr = el('tr', {}, tb);
    el('td', { text: fullDate(p[0]) }, tr);
    for (let i = 1; i <= 4; i++) el('td', { class: 'num', text: fmt(p[i]) }, tr);
  }
}

// ---------- heatmap ----------
function renderHeatmap() {
  const box = $('heatmap'); box.textContent = '';
  const h = state.heat;
  if (!h || !h.hours.length) { el('div', { class: 'empty', text: 'No data in the last 28 days' }, box); return; }
  const sum = Array.from({ length: 7 }, () => new Array(24).fill(0));
  const cnt = Array.from({ length: 7 }, () => new Array(24).fill(0));
  for (const [t, v] of h.hours) {
    const { wd, hour: hr } = zurich(t);
    sum[wd][hr] += v; cnt[wd][hr]++;
  }
  let max = 0;
  const avg = sum.map((r, i) => r.map((v, j) => {
    if (!cnt[i][j]) return null;
    const a = v / cnt[i][j]; max = Math.max(max, a); return a;
  }));
  const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const STEPS = 7;
  const W = Math.max(box.clientWidth, 320), lw = 36, th = 18;
  const gap = 2, cw = (W - lw) / 24, chh = 24, H = th + 7 * chh;
  const svg = el('svg:svg', { viewBox: `0 0 ${W} ${H}`, height: H, role: 'img',
    'aria-label': 'Average count by weekday and hour' }, box);
  for (let j = 0; j < 24; j += 3)
    el('svg:text', { x: lw + j * cw + cw / 2, y: 12, 'text-anchor': 'middle', class: 'axis-text',
      text: String(j).padStart(2, '0') }, svg);
  const tip = el('div', { class: 'tooltip' }, box);
  for (let i = 0; i < 7; i++) {
    el('svg:text', { x: 0, y: th + i * chh + chh / 2 + 4, class: 'axis-text', text: days[i] }, svg);
    for (let j = 0; j < 24; j++) {
      const v = avg[i][j];
      const lvl = v == null ? -1 : max ? Math.min(STEPS - 1, Math.floor(v / max * STEPS)) : 0;
      const rect = el('svg:rect', {
        x: lw + j * cw + gap / 2, y: th + i * chh + gap / 2, width: cw - gap, height: chh - gap, rx: 3,
        fill: lvl < 0 ? 'var(--surface-2)' : `var(--seq-${lvl})`, class: 'heat-cell', tabindex: 0,
        'aria-label': `${days[i]} ${j}:00, ${v == null ? 'no data' : 'average ' + fmt(v)}`,
      }, svg);
      const showTip = () => {
        tip.textContent = '';
        el('div', { class: 'tt-head', text: `${days[i]} ${String(j).padStart(2, '0')}:00–${String(j + 1).padStart(2, '0')}:00` }, tip);
        const row = el('div', { class: 'tt-row' }, tip);
        el('b', { text: v == null ? '–' : fmt(Math.round(v * 10) / 10) }, row);
        el('span', { text: v == null ? 'no data' : `average · ${cnt[i][j]} days` }, row);
        tip.style.display = 'block';
        const scale = box.clientWidth / W;
        let left = (lw + (j + 1) * cw) * scale + 6;
        if (left + tip.offsetWidth > box.clientWidth) left = (lw + j * cw) * scale - tip.offsetWidth - 6;
        tip.style.left = left + 'px';
        tip.style.top = ((th + i * chh) * scale - 4) + 'px';
      };
      rect.addEventListener('pointerenter', showTip);
      rect.addEventListener('focus', showTip);
      rect.addEventListener('pointerleave', () => { tip.style.display = 'none'; });
      rect.addEventListener('blur', () => { tip.style.display = 'none'; });
    }
  }
  const sc = el('div', { class: 'scale' }, box);
  el('span', { text: '0' }, sc);
  for (let k = 0; k < STEPS; k++) el('i', { style: `background:var(--seq-${k})` }, sc);
  el('span', { text: fmt(Math.round(max * 10) / 10) }, sc);
  el('i', { style: 'background:var(--surface-2);margin-left:12px' }, sc);
  el('span', { text: 'no data' }, sc);
}

// ---------- WiFi networks ----------
const NET_ACTIVE_SEC = 1500; // network list is uploaded every 10 min
// network name for an access point MAC, or null
function ssidOf(mac, type) {
  if (type !== 'wifi' || !state.nets) return null;
  const n = state.nets.byBssid[mac];
  return n ? (n.hidden ? '(hidden network)' : n.ssid) : null;
}
// address kind as text; BLE kinds come from newer firmware, older rows only
// have the (for BLE unreliable) locally administered bit
const KIND = { p: 'public', s: 'random static', r: 'rotating (private)', n: 'rotating (non-resolvable)' };
const addrText = (type, random, kind) => type === 'ble' && kind ? KIND[kind]
  : random ? 'randomized' : type === 'ble' ? 'fixed?' : 'fixed';
const cap = t => t.charAt(0).toUpperCase() + t.slice(1);
const typeText = (mac, type, random, kind) => {
  const ssid = ssidOf(mac, type);
  return ssid != null ? 'WiFi access point' : `${type === 'ble' ? 'Bluetooth' : 'WiFi'}, ${addrText(type, random, kind)}`;
};
const signalText = dbm => `${dbm} dBm · ${dbm >= -60 ? 'strong' : dbm >= -70 ? 'good' : dbm >= -80 ? 'fair' : 'weak'}`;
function statusIcon(parent, ok) {
  const svg = el('svg:svg', { width: 12, height: 12, viewBox: '0 0 14 14', 'aria-hidden': 'true' }, parent);
  el('svg:circle', { cx: 7, cy: 7, r: 6, fill: ok ? 'var(--good)' : 'var(--text-muted)' }, svg);
  if (ok) el('svg:path', { d: 'M4 7.2l2 2 4-4.2', stroke: '#fff', 'stroke-width': 1.6, fill: 'none', 'stroke-linecap': 'round' }, svg);
  else el('svg:path', { d: 'M4.5 7h5', stroke: '#fff', 'stroke-width': 1.6, 'stroke-linecap': 'round' }, svg);
}

function renderNets() {
  const t = $('nets-table'); t.textContent = '';
  const list = state.nets ? state.nets.list : [];
  if (!list.length) {
    el('caption', { class: 'empty', text: 'No networks received yet. The paxcounter uploads its list every 10 minutes.' }, t);
    return;
  }
  const inRange = list.filter(n => state.now - n.last < NET_ACTIVE_SEC).length;
  $('nets-sub').textContent = `${fmt(list.length)} access points seen so far, ${fmt(inRange)} currently in range. `
    + 'One router often sends several networks (2.4 GHz only, the ESP32 cannot receive 5 GHz).';
  const hr = el('tr', {}, el('thead', {}, t));
  for (const [h, c] of [['Network', ''], ['Access point', ''], ['Channel', 'num'], ['Signal', ''], ['Status', ''], ['First seen', '']])
    el('th', { class: c, text: h }, hr);
  const tb = el('tbody', {}, t);
  for (const n of list) {
    const tr = el('tr', {}, tb);
    el('td', n.hidden ? { class: 'muted', text: '(hidden network)' } : { text: n.ssid }, tr);
    el('td', { class: 'mono', text: n.bssid }, tr);
    el('td', { class: 'num', text: n.channel || '–' }, tr);
    el('td', { text: signalText(n.rssi_max) }, tr);
    const active = state.now - n.last < NET_ACTIVE_SEC;
    const sc = el('span', { class: 'status-cell' }, el('td', {}, tr));
    statusIcon(sc, active);
    el('span', { text: active ? 'In range' : `Last seen ${ago(state.now - n.last)}` }, sc);
    el('td', { text: fullDate(n.first) }, tr);
  }
}

// ---------- device groups ----------
function renderGroups() {
  const g = state.groups;
  const tiles = $('group-tiles'); tiles.textContent = '';
  const chart = $('groups-chart'); chart.textContent = '';
  if (!g) return;
  $('groups-sub').textContent = `Every MAC address seen in the last ${g.class_days} days, grouped by how often it was there. `
    + (g.active_hours >= g.min_hours
      ? `"Always present" means seen in at least ${g.min_hours} of the ${g.active_hours} hours with data. `
      : `"Always present" needs at least ${g.min_hours} hours of data (${g.active_hours} so far). `)
    + 'Phones change randomized MACs regularly, so most of those count as one-time.';
  const total = GROUPS.reduce((a, x) => a + g.summary[x.key].total, 0);
  for (const grp of GROUPS) {
    const sm = g.summary[grp.key];
    const t = el('div', { class: 'group' }, tiles);
    const n = el('div', { class: 'group-name' }, t);
    el('i', { style: `background:${grp.color}` }, n);
    el('span', { text: grp.name }, n);
    el('div', { class: 'group-value', text: fmt(sm.total) }, t);
    el('div', { class: 'group-note', text: grp.note }, t);
    el('div', { class: 'group-note', text: `${total ? Math.round(sm.total / total * 100) : 0}% of all · `
      + `${sm.total ? Math.round(sm.random / sm.total * 100) : 0}% randomized` }, t);
  }
  $('groups-chart-sub').textContent = `Different devices per group seen in each ${bucketName(g.bucket)}`;
  if (!g.points.length) { el('div', { class: 'empty', text: 'No data in this range' }, chart); }
  else lineChart(chart, g, GROUPS, { aria: 'Devices per group over time' });
  renderTimeline();
}

function renderTimeline() {
  const box = $('timeline'); box.textContent = '';
  const tbl = $('timeline-table'); tbl.textContent = '';
  const g = state.groups;
  if (!g || !g.top.length) { el('div', { class: 'empty', text: 'No device sightings in this range' }, box); return; }
  $('timeline-sub').textContent = `The ${g.top.length} devices present for the most hours in the selected range. `
    + `Each mark is one ${bucketName(g.bucket)} in which the device was seen; the color is its group.`;

  const rows = g.top, rh = 18;
  const W = Math.max(box.clientWidth, 320);
  const m = { t: 4, r: 8, b: 26, l: 168 };
  const iw = W - m.l - m.r, H = m.t + rows.length * rh + m.b;
  const x0 = g.from, x1 = g.now, span = x1 - x0;
  const x = t => m.l + (Math.max(t, x0) - x0) / span * iw;
  const cw = g.bucket / span * iw;
  const svg = el('svg:svg', { viewBox: `0 0 ${W} ${H}`, height: H, role: 'img',
    'aria-label': 'Presence timeline of the most present devices; details in the data table below.' }, box);
  rows.forEach((d, i) => {
    const y = m.t + i * rh;
    if (i % 2) el('svg:rect', { x: m.l, y, width: iw, height: rh, fill: 'var(--surface-2)', opacity: 0.5 }, svg);
    el('svg:rect', { x: 0, y: y + 5, width: 8, height: 8, rx: 2, fill: GROUP[d.group].color }, svg);
    const ssid = ssidOf(d.mac, d.type);
    const label = ssid != null ? (ssid.length > 20 ? ssid.slice(0, 19) + '…' : ssid) + ' AP'
      : `${d.mac} ${d.type === 'ble' ? 'BT' : 'WiFi'}`;
    el('svg:text', { x: 14, y: y + 13, class: 'tl-label', text: label }, svg);
    for (const [t] of d.seen) {
      const px = x(t), w = Math.max(2, Math.min(x(t + g.bucket), m.l + iw) - px - (cw >= 4 ? 1 : 0));
      el('svg:rect', { x: px, y: y + 3, width: w, height: rh - 6, rx: Math.min(2, w / 2), fill: GROUP[d.group].color }, svg);
    }
  });
  el('svg:line', { x1: m.l, x2: m.l + iw, y1: m.t + rows.length * rh, y2: m.t + rows.length * rh, stroke: 'var(--axis)' }, svg);
  xTicks(svg, x0, x1, m.l, iw, m.t + rows.length * rh, H - 6);

  // one hover handler for the whole timeline: row from y, bucket from x
  const tip = el('div', { class: 'tooltip' }, box);
  const cross = el('svg:line', { y1: m.t, y2: m.t + rows.length * rh, stroke: 'var(--text-muted)', 'stroke-width': 1,
    visibility: 'hidden', 'pointer-events': 'none' }, svg);
  svg.addEventListener('pointermove', e => {
    const r = svg.getBoundingClientRect(), sx = W / r.width;
    const px = (e.clientX - r.left) * sx, py = (e.clientY - r.top) * sx;
    const i = Math.floor((py - m.t) / rh);
    if (i < 0 || i >= rows.length || px < m.l) { tip.style.display = 'none'; cross.setAttribute('visibility', 'hidden'); return; }
    const d = rows[i];
    const tAt = x0 + (px - m.l) / iw * span;
    const hit = d.seen.find(([t]) => tAt >= t && tAt < t + g.bucket);
    cross.setAttribute('x1', px); cross.setAttribute('x2', px); cross.setAttribute('visibility', 'visible');
    tip.textContent = '';
    const ssid = ssidOf(d.mac, d.type);
    el('div', { class: 'tt-head', text: `${ssid != null ? ssid + ' · ' : ''}${d.mac} · ${typeText(d.mac, d.type, d.random, d.kind)}` }, tip);
    const r1 = el('div', { class: 'tt-row' }, tip);
    el('i', { class: 'key', style: `background:${GROUP[d.group].color}` }, r1);
    el('b', { text: GROUP[d.group].name }, r1);
    el('span', { text: `${fmt(d.visits)} visit${d.visits === 1 ? '' : 's'} · ${fmt(d.hours)} h in range` }, r1);
    const r2 = el('div', { class: 'tt-row' }, tip);
    el('i', { class: 'key' }, r2);
    el('b', { text: hit ? 'seen' : '–' }, r2);
    el('span', { text: fullDate(Math.floor(tAt / 60) * 60) + (hit ? ` · ${fmt(hit[1])} send cycles` : '') }, r2);
    tip.style.display = 'block';
    let left = px / sx + 12;
    if (left + tip.offsetWidth > box.clientWidth) left = px / sx - tip.offsetWidth - 12;
    tip.style.left = left + 'px';
    tip.style.top = ((m.t + (i + 1) * rh) / sx + 4) + 'px';
  });
  svg.addEventListener('pointerleave', () => { tip.style.display = 'none'; cross.setAttribute('visibility', 'hidden'); });

  // table view
  const hr = el('tr', {}, el('thead', {}, tbl));
  for (const [h, c] of [['MAC', ''], ['Network', ''], ['Type', ''], ['Group', ''], ['Visits', 'num'], ['Hours in range', 'num'], ['First seen', ''], ['Last seen', '']])
    el('th', { class: c, text: h }, hr);
  const tb = el('tbody', {}, tbl);
  for (const d of rows) {
    const tr = el('tr', {}, tb);
    el('td', { class: 'mono', text: d.mac }, tr);
    el('td', { text: ssidOf(d.mac, d.type) ?? '' }, tr);
    el('td', { text: typeText(d.mac, d.type, d.random, d.kind) }, tr);
    el('td', { text: GROUP[d.group].name }, tr);
    el('td', { class: 'num', text: fmt(d.visits) }, tr);
    el('td', { class: 'num', text: fmt(d.hours) }, tr);
    el('td', { text: fullDate(d.first) }, tr);
    el('td', { text: fullDate(d.last) }, tr);
  }
}

// ---------- MAC table ----------
function renderMacs() {
  const t = $('macs-table'); t.textContent = '';
  const m = state.macs;
  if (!m || !m.macs.length) {
    el('caption', { class: 'empty', text: 'No device sightings in this range' }, t);
    return;
  }
  $('macs-sub').textContent = `Top ${m.macs.length} of ${fmt(m.summary.total)} MAC addresses in the selected range, by number of send cycles seen`;
  const hr = el('tr', {}, el('thead', {}, t));
  for (const [h, c] of [['MAC', ''], ['Network', ''], ['Type', ''], ['Address', ''], ['Cycles seen', 'num'], ['Packets', 'num'], ['First seen', ''], ['Last seen', '']])
    el('th', { class: c, text: h }, hr);
  const tb = el('tbody', {}, t);
  for (const r of m.macs) {
    const tr = el('tr', {}, tb);
    el('td', { class: 'mono', text: r.mac }, tr);
    el('td', { text: ssidOf(r.mac, r.type) ?? '' }, tr);
    el('td', { text: ssidOf(r.mac, r.type) != null ? 'WiFi access point' : r.type === 'ble' ? 'Bluetooth' : 'WiFi' }, tr);
    el('td', { text: cap(addrText(r.type, r.random, r.kind)) }, tr);
    el('td', { class: 'num', text: fmt(r.batches) }, tr);
    el('td', { class: 'num', text: fmt(r.packets) }, tr);
    el('td', { text: fullDate(r.first) }, tr);
    el('td', { text: fullDate(r.last) }, tr);
  }
}

// ---------- loading ----------
let loadSeq = 0;
async function load() {
  const seq = ++loadSeq;
  const app = $('app');
  app.classList.add('loading');
  try {
    const dv = await api({ q: 'devices' });
    if (seq !== loadSeq) return;
    state.devices = dv.devices; state.now = dv.now;
    const sel = $('device'); sel.textContent = '';
    for (const d of dv.devices) el('option', { value: d.device, text: d.device }, sel);
    if (!dv.devices.some(d => d.device === state.device)) state.device = dv.devices[0]?.device || '';
    sel.value = state.device;
    if (!state.device) {
      $('series').textContent = 'No data received yet.';
      return;
    }
    const p = { device: state.device, range: state.range };
    const [series, heat, macs, groups, netsRes] = await Promise.all([
      api({ q: 'series', ...p }), api({ q: 'heatmap', device: state.device }), api({ q: 'macs', ...p }),
      api({ q: 'groups', ...p }), api({ q: 'networks', device: state.device }),
    ]);
    if (seq !== loadSeq) return;
    const nets = { list: netsRes.networks, byBssid: Object.fromEntries(netsRes.networks.map(n => [n.bssid, n])) };
    Object.assign(state, { series, heat, macs, groups, nets });
    renderAll();
  } catch (e) {
    console.error(e);
    $('status').textContent = 'Could not load data: ' + e.message;
  } finally {
    if (seq === loadSeq) app.classList.remove('loading');
  }
}
function renderAll() { renderStatus(); renderTiles(); renderSeries(); renderHeatmap(); renderGroups(); renderNets(); renderMacs(); }

function syncRangeButtons() {
  for (const b of $('ranges').querySelectorAll('button'))
    b.setAttribute('aria-pressed', String(b.dataset.range === state.range));
}
$('ranges').addEventListener('click', e => {
  const b = e.target.closest('button'); if (!b) return;
  state.range = b.dataset.range; localStorage.setItem('pax.range', state.range);
  syncRangeButtons(); load();
});
$('device').addEventListener('change', e => {
  state.device = e.target.value; localStorage.setItem('pax.device', state.device); load();
});
let rt;
window.addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => state.series && renderAll(), 150); });
setInterval(() => { if (!document.hidden) load(); }, REFRESH_MS);
document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });

syncRangeButtons();
load();
</script>
<?php endif ?>
</body>
</html>
