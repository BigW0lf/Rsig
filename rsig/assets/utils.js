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
    taux:    ['#bfdbfe', '#60a5fa', '#3b82f6', '#1d4ed8', '#1e3a6e'],
    coeff:   ['#1d4ed8','#60a5fa','#bfdbfe','#e0f2fe','#f8fafc','#fecaca','#f87171','#ef4444','#7f1d1d'],
    coeffEv: ['#b91c1c', '#f97316', '#facc15', '#86efac', '#16a34a'],
    tarifs:  ['#1a9850', '#91cf60', '#d9ef8b', '#fee08b', '#fc8d59', '#d73027', '#a50026'],
    tf:      ['#eff6ff', '#93c5fd', '#3b82f6', '#1d4ed8', '#1e3a6e'],
};
