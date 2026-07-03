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

// ── Debounce — trailing + leading edge optionnel ──────────
export function debounce(fn, delay, { leading = false } = {}) {
    let t;
    return function (...args) {
        const callNow = leading && !t;
        clearTimeout(t);
        t = setTimeout(() => { t = null; if (!leading) fn.apply(this, args); }, delay);
        if (callNow) fn.apply(this, args);
    };
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

// ── Ordre des couches — RAF-debounced, 1 seul passage/frame ─
// On ne moveLayer que les couches dont l'ordre a changé depuis
// la dernière fois, évitant des repaints GL en cascade.
let _bddPending = false;
let _bddMap = null;

const _BDD_ORDER = [
    // 1. Remplissages/contours de fond (sous tout)
    'taux-fill','taux-line','tarifs-fill','tarifs-line','sections-fill',
    'cfe-fill','cfe-line','tf-fill','tf-line',
    'ta-fill','ta-line',
    'tsb-idf-fill','tsb-idf-line','tsb-paca-fill','tsb-paca-line',
    'tass-fill','tass-line','zfu-fill','zfu-line',
    // 2. Hachures + polygones coeff
    ...[...Array(10)].map((_, i) => `coeff-hatch-${i}`),
    'coeff-fill','coeff-line',
    'coeff-cluster-circle','coeff-cluster-cluster','coeff-cluster-count',
    // 3. TA majorée
    'ta-maj-fill','ta-maj-line',
    ...[...Array(5)].map((_, i) => `ta-maj-hatch-${i}`),
    'ta-maj-cluster','ta-maj-cluster-count','ta-maj-point',
    // 4. POI OSM
    'osm-point',
    // 5. Prospects
    'prospects-circle','prospects-cluster','prospects-cluster-count',
    // 6. Dossiers — tout en haut
    'dossiers-circle','dossiers-cluster','dossiers-cluster-count',
];

export function bddOnTop(map) {
    _bddMap = map;
    if (_bddPending) return;
    _bddPending = true;
    requestAnimationFrame(() => {
        _bddPending = false;
        const m = _bddMap;
        if (!m) return;
        // Récupère l'ordre actuel des layers présents dans notre liste
        const existing = _BDD_ORDER.filter(id => m.getLayer(id));
        if (!existing.length) return;
        // Ne faire des moveLayer que si l'ordre diffère de ce qu'on veut
        const allLayers = m.getStyle()?.layers?.map(l => l.id) ?? [];
        let lastIdx = -1;
        let needsReorder = false;
        for (const id of existing) {
            const idx = allLayers.indexOf(id);
            if (idx < lastIdx) { needsReorder = true; break; }
            lastIdx = idx;
        }
        if (needsReorder) {
            existing.forEach(id => m.moveLayer(id));
        }
    });
}
