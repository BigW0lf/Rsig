// ── Spinner ───────────────────────────────────────────────
const _spinner = document.getElementById('map-spinner');
let _spinnerTimer = null;
export function showSpinner() { _spinnerTimer = setTimeout(() => { if (_spinner) _spinner.style.display = 'flex'; }, 300); }
export function hideSpinner() { clearTimeout(_spinnerTimer); if (_spinner) _spinner.style.display = 'none'; }

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
    taux:    ['#4a148c', '#7b1fa2', '#ab47bc', '#42a5f5', '#b3e5fc'],
    coeff:   ['#ffffff', '#fee2e2', '#fca5a5', '#f87171', '#ef4444', '#b91c1c'],
    coeffEv: ['#b91c1c', '#f97316', '#facc15', '#86efac', '#16a34a'],
    tarifs:  ['#e65100', '#fb8c00', '#ffa726', '#ffcc80', '#dce775', '#c5e1a5', '#a5d6a7'],
    tf:      ['#eff6ff', '#93c5fd', '#3b82f6', '#1d4ed8', '#1e3a6e'],
    cfe:     ['#ffffb2', '#fed976', '#feb24c', '#fd8d3c', '#f03b20', '#bd0026'],
};

// ── Ordre des couches (appelé après chaque rendu) ─────────
export function bddOnTop(map) {
    // 1. Taux/tarifs/sections/CFE/ZFU en bas
    ['taux-fill','taux-line','tarifs-fill','tarifs-line','sections-fill','cfe-fill','cfe-line','tf-fill','tf-line','ta-fill','ta-line','tsb-idf-fill','tsb-idf-line','tsb-paca-fill','tsb-paca-line','tass-fill','tass-line','zfu-fill','zfu-line'].forEach(id => {
        if (map.getLayer(id)) map.moveLayer(id);
    });
    // 2. Coeff polygones (hachures)
    for (let i = 0; i < 10; i++) {
        if (map.getLayer(`coeff-hatch-${i}`)) map.moveLayer(`coeff-hatch-${i}`);
    }
    ['coeff-fill','coeff-line'].forEach(id => { if (map.getLayer(id)) map.moveLayer(id); });
    // 3. Clusters coeff
    ['coeff-cluster-circle','coeff-cluster-cluster','coeff-cluster-count'].forEach(id => {
        if (map.getLayer(id)) map.moveLayer(id);
    });
    // 4. TA majorée (hachures + polygones + clusters) — priorité haute
    ['ta-maj-fill','ta-maj-line'].forEach(id => { if (map.getLayer(id)) map.moveLayer(id); });
    for (let i = 0; i < 5; i++) {
        if (map.getLayer(`ta-maj-hatch-${i}`)) map.moveLayer(`ta-maj-hatch-${i}`);
    }
    ['ta-maj-cluster','ta-maj-cluster-count','ta-maj-point'].forEach(id => {
        if (map.getLayer(id)) map.moveLayer(id);
    });
    // 5. Dossiers tout en haut
    ['dossiers-circle','dossiers-cluster','dossiers-cluster-count'].forEach(id => {
        if (map.getLayer(id)) map.moveLayer(id);
    });
}
