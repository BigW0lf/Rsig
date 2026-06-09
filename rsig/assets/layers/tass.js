import { showSpinner, hideSpinner, bddOnTop } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

const PALETTE = {
    1: { fill: '#dc2626', stroke: '#991b1b' },  // rouge — Paris + 92
    2: { fill: '#f59e0b', stroke: '#b45309' },  // orange — UU Paris hors 92
    3: { fill: '#22c55e', stroke: '#15803d' },  // vert — reste IDF
};
const LABELS = {
    1: '1ère circ.',
    2: '2ème circ.',
    3: '3ème circ.',
};

let active   = false;
let loaded   = false;
let fcCache  = null;
let ctrl     = null;

const millesimesReady = fetch('/api/tass/millesimes').then(r => r.json()).catch(() => [2026]);
const tarifsCache = {};

function getTarifs(mil) {
    if (tarifsCache[mil]) return Promise.resolve(tarifsCache[mil]);
    return fetch(`/api/tass/tarifs?millesime=${mil}`)
        .then(r => r.json())
        .then(d => { tarifsCache[mil] = d.tarifs || []; return tarifsCache[mil]; })
        .catch(() => []);
}

function getMillesime() {
    return document.getElementById('tass-millesime')?.value || '';
}

function load(map) {
    if (!active) return;
    showSpinner();

    const doRender = (fc) => {
        hideSpinner();
        if (!active || !fc?.features?.length) return;

        const colorExpr = ['match', ['get', 'circonscription'],
            1, PALETTE[1].fill,
            2, PALETTE[2].fill,
            3, PALETTE[3].fill,
            '#888'
        ];
        const strokeExpr = ['match', ['get', 'circonscription'],
            1, PALETTE[1].stroke,
            2, PALETTE[2].stroke,
            3, PALETTE[3].stroke,
            '#555'
        ];

        if (map.getSource('tass-src')) {
            map.getSource('tass-src').setData(fc);
            map.setPaintProperty('tass-fill', 'fill-color', colorExpr);
        } else {
            map.addSource('tass-src', { type: 'geojson', data: fc });
            map.addLayer({ id: 'tass-fill', type: 'fill', source: 'tass-src',
                paint: { 'fill-color': colorExpr, 'fill-opacity': 0.45 } });
            map.addLayer({ id: 'tass-line', type: 'line', source: 'tass-src',
                paint: { 'line-color': strokeExpr, 'line-width': 0.8 } });
        }

        loaded = true;
        bddOnTop(map);
        saveLegend('tass', 'TASS IDF', Object.values(LABELS), Object.values(PALETTE).map(p => p.fill), '');
    };

    if (fcCache) { doRender(fcCache); return; }

    if (ctrl) ctrl.abort();
    ctrl = new AbortController();
    fetch('/api/tass', { signal: ctrl.signal })
        .then(r => r.json())
        .then(fc => { fcCache = fc; doRender(fc); })
        .catch(e => { hideSpinner(); if (e.name !== 'AbortError') console.error('tass', e); });
}

function remove(map) {
    ['tass-fill', 'tass-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
    if (map.getSource('tass-src')) map.removeSource('tass-src');
}

export function initTass(map) {
    const toggle = document.getElementById('toggle-tass');

    millesimesReady.then(mils => {
        const sel = document.getElementById('tass-millesime');
        if (!sel || !mils.length) return;
        mils.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m; opt.textContent = m;
            sel.appendChild(opt);
        });
    });

    map.on('mouseenter', 'tass-fill', () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'tass-fill', () => map.getCanvas().style.cursor = '');

    map.on('click', 'tass-fill', e => {
        const p   = e.features[0].properties;
        const c   = +p.circonscription;
        const mil = +(getMillesime() || new Date().getFullYear());
        getTarifs(mil).then(tarifs => {
            const t = tarifs.find(r => +r.circonscription === c);
            showInfo('tass', `TASS — ${p.libcom || p.code_insee}`,
                irow('Circonscription', `${c} — ${LABELS[c] || ''}`) +
                irow('Département', p.dep) +
                irow('Code INSEE', p.code_insee) +
                irow('Millésime', mil) +
                (t ? irow('Tarif TASS', (+t.tarif).toFixed(2) + ' €/m²') : '')
            );
        });
    });

    toggle?.addEventListener('change', () => {
        active = toggle.checked;
        document.getElementById('tass-options')?.classList.toggle('hidden', !active);
        if (!active) { remove(map); loaded = false; dropLegend('tass'); clearInfo('tass'); }
        else load(map);
    });

    return { isActive: () => active };
}
