import { fmtVal } from './utils.js';

const legendEl    = document.getElementById('legend');
const legendItems = document.getElementById('legend-items');
const state = {};

export function saveLegend(key, title, breaks, pal, suffix = '') {
    state[key] = { title, breaks, pal, suffix };
    _render();
}

export function dropLegend(key) {
    delete state[key];
    _render();
}

function _render() {
    const keys = ['taux', 'coeff', 'tarifs', 'dossiers'].filter(k => state[k]);
    if (!keys.length) { legendEl.classList.add('hidden'); return; }
    legendItems.innerHTML = keys.map(k => {
        const { title, breaks, pal, suffix } = state[k];
        const rows = breaks.map((b, i) => {
            const next  = breaks[i + 1];
            const label = next !== undefined
                ? `${fmtVal(b, suffix)}${suffix} – ${fmtVal(next, suffix)}${suffix}`
                : `≥ ${fmtVal(b, suffix)}${suffix}`;
            return `<div class="legend-item">
                <span class="legend-swatch" style="background:${pal[i]}"></span>
                <span>${label}</span>
            </div>`;
        }).join('');
        return `<div class="legend-section-title">${title}</div>${rows}`;
    }).join('');
    legendEl.classList.remove('hidden');
}
