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
    const keys = ['taux', 'sections', 'coeff', 'cfe', 'tarifs', 'zfu', 'dossiers'].filter(k => state[k]);
    if (!keys.length) { legendEl.classList.add('hidden'); return; }
    legendItems.innerHTML = keys.map(k => {
        const { title, breaks, pal, suffix } = state[k];
        // Si breaks contient des strings, on les affiche tels quels (légende non-choroplèthe)
        const rows = typeof breaks[0] === 'string'
            ? breaks.map((label, i) => `<div class="legend-item">
                <span class="legend-swatch" style="background:${pal[i] ?? pal[0]}"></span>
                <span>${label}</span>
            </div>`).join('')
            : (() => {
                // Si le nombre de breaks = pal.length + 1 → intervalles fermés (min–max par classe)
                const closed = breaks.length === pal.length + 1;
                const items = closed ? breaks.slice(0, -1) : breaks;
                return items.map((b, i) => {
                    const next = breaks[i + 1];
                    const label = (closed || next !== undefined)
                        ? `${fmtVal(b, suffix)}${suffix} – ${fmtVal(next, suffix)}${suffix}`
                        : `≥ ${fmtVal(b, suffix)}${suffix}`;
                    return `<div class="legend-item">
                        <span class="legend-swatch" style="background:${pal[i]}"></span>
                        <span>${label}</span>
                    </div>`;
                }).join('');
            })();
        return `<div class="legend-section-title">${title}</div>${rows}`;
    }).join('');
    legendEl.classList.remove('hidden');
}
