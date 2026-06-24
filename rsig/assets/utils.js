import { showToast } from './state.js';

// ── Spinner — compteur de références ─────────────────────
const _spinner = document.getElementById('map-spinner');
let _spinnerCount = 0;
let _spinnerTimer = null;

export function showSpinner() {
    _spinnerCount++;
    if (_spinnerCount === 1) {
        clearTimeout(_spinnerTimer);
        _spinnerTimer = setTimeout(() => {
            if (_spinnerCount > 0 && _spinner) _spinner.style.display = 'flex';
        }, 200);
    }
}
export function hideSpinner() {
    _spinnerCount = Math.max(0, _spinnerCount - 1);
    if (_spinnerCount === 0) {
        clearTimeout(_spinnerTimer);
        if (_spinner) _spinner.style.display = 'none';
    }
}

// ── Fetch avec timeout ────────────────────────────────────
const API_TIMEOUT_MS = 12000;

export async function apiFetch(url, opts = {}) {
    const { signal: callerSignal, ...rest } = opts;
    const timeout = AbortSignal.timeout(API_TIMEOUT_MS);
    const signal  = callerSignal
        ? AbortSignal.any([callerSignal, timeout])
        : timeout;
    try {
        const r = await fetch(url, { signal, ...rest });
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r;
    } catch (e) {
        if (e.name === 'AbortError' || e.name === 'TimeoutError') throw e;
        console.error(`[rsig] fetch ${url}`, e);
        showToast('Erreur réseau — réessayez');
        throw e;
    }
}

// ── Debounce ──────────────────────────────────────────────
export function debounce(fn, delay) {
    let t;
    return function (...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), delay); };
}

// ── Choroplèthe ───────────────────────────────────────────
export function computeBreaks(values, n) {
    const sorted = values.filter(v => v != null && isFinite(v)).sort((a, b) => a - b);
    if (!sorted.length) return Array(n).fill(0);
    return Array.from({ length: n }, (_, i) => sorted[Math.floor(i * sorted.length / n)] ?? 0);
}

export function stepExpr(prop, breaks, palette, fallback = '#cccccc') {
    if (!breaks.length) return fallback;
    const stops = [];
    for (let i = 1; i < breaks.length; i++) {
        const prev = stops.length ? stops[stops.length - 1][0] : breaks[0];
        if (breaks[i] > prev) stops.push([breaks[i], palette[i] ?? palette[palette.length - 1]]);
    }
    if (!stops.length) return palette[0] ?? fallback;
    const expr = ['step', ['to-number', ['get', prop], 0], palette[0]];
    stops.forEach(([val, col]) => expr.push(val, col));
    return expr;
}

export function fmtVal(v, suffix) {
    const n = +v;
    if (suffix === ' %') return n.toFixed(2);
    if (Math.abs(n) < 100 && n % 1 !== 0) return n.toFixed(2);
    return Math.round(n).toLocaleString('fr-FR');
}

// ── Palettes ──────────────────────────────────────────────
export const PAL = {
    taux:    ['#c7e9c0', '#74c476', '#238b45', '#fd8d3c', '#bd0026'],
    coeff:   ['#ffffff', '#fee2e2', '#fca5a5', '#f87171', '#ef4444', '#b91c1c'],
    coeffEv: ['#b91c1c', '#f97316', '#facc15', '#86efac', '#16a34a'],
    tarifs:  ['#a5d6a7', '#c5e1a5', '#dce775', '#ffcc80', '#ffa726', '#fb8c00', '#e65100'],
    cfe:     ['#ffffb2', '#fed976', '#feb24c', '#fd8d3c', '#f03b20', '#bd0026'],
    tf:      ['#ffffcc', '#c7e9b4', '#7fcdbb', '#41b6c4', '#1d91c0', '#225ea8'],
};

// ── Ordre des couches — RAF-debounced (1 seul passage/frame) ─
let _bddPending = false;
let _bddMap = null;

export function bddOnTop(map) {
    _bddMap = map;
    if (_bddPending) return;
    _bddPending = true;
    requestAnimationFrame(() => {
        _bddPending = false;
        const m = _bddMap;
        if (!m) return;
        // 1. Couches de base (remplissage + contour)
        ['taux-fill','taux-line','tarifs-fill','tarifs-line','sections-fill',
         'cfe-fill','cfe-line','tf-fill','tf-line',
         'ta-fill','ta-line',
         'tsb-idf-fill','tsb-idf-line','tsb-paca-fill','tsb-paca-line',
         'tass-fill','tass-line','zfu-fill','zfu-line',
        ].forEach(id => { if (m.getLayer(id)) m.moveLayer(id); });
        // 2. Hachures coeff
        for (let i = 0; i < 10; i++) {
            if (m.getLayer(`coeff-hatch-${i}`)) m.moveLayer(`coeff-hatch-${i}`);
        }
        ['coeff-fill','coeff-line'].forEach(id => { if (m.getLayer(id)) m.moveLayer(id); });
        // 3. Clusters coeff
        ['coeff-cluster-circle','coeff-cluster-cluster','coeff-cluster-count'].forEach(id => {
            if (m.getLayer(id)) m.moveLayer(id);
        });
        // 4. TA majorée
        ['ta-maj-fill','ta-maj-line'].forEach(id => { if (m.getLayer(id)) m.moveLayer(id); });
        for (let i = 0; i < 5; i++) {
            if (m.getLayer(`ta-maj-hatch-${i}`)) m.moveLayer(`ta-maj-hatch-${i}`);
        }
        ['ta-maj-cluster','ta-maj-cluster-count','ta-maj-point'].forEach(id => {
            if (m.getLayer(id)) m.moveLayer(id);
        });
        // 5. Dossiers — tout en haut
        ['dossiers-circle','dossiers-cluster','dossiers-cluster-count'].forEach(id => {
            if (m.getLayer(id)) m.moveLayer(id);
        });
    });
}
