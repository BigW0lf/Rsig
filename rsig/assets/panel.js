import { isHidden } from './catalogue.js';

const panelRight = document.getElementById('panel-right');
const _legendEl  = document.getElementById('legend');

function _syncLegendShift() {
    const open = !panelRight.classList.contains('panel-closed');
    _legendEl?.classList.toggle('legend-shifted', open);
    document.querySelector('#map-wrap .maplibregl-ctrl-bottom-right')?.classList.toggle('ctrl-shifted', open);
}

document.getElementById('close-right').addEventListener('click', () => {
    panelRight.classList.add('panel-closed');
    _syncLegendShift();
});

// Sections par couche affichées dans le panneau
const _sections = {};

function _renderPanel() {
    const keys = ['taux', 'tarifs', 'coeff', 'cfe', 'tf', 'ta', 'ta-majore', 'sections', 'tsb', 'tass', 'zfu', 'dossiers', 'prospects', 'osm'];
    const active = keys.filter(k => _sections[k] && !isHidden(k));
    if (!active.length) { panelRight.classList.add('panel-closed'); return; }
    const first = _sections[active[0]];
    document.getElementById('info-title').textContent = first.title;
    document.getElementById('info-content').innerHTML = active.map((k, i) => {
        const s = _sections[k];
        if (i === 0) return `<div class="info-section">${s.html}</div>`;
        return `<div class="info-section-sep">${s.title}</div><div class="info-section">${s.html}</div>`;
    }).join('');
    panelRight.classList.remove('panel-closed');
}

export function showInfo(layerKey, title, html) {
    _sections[layerKey] = { title, html };
    _renderPanel();
    document.getElementById('info-content').scrollTop = 0;
    _syncLegendShift();
}

export function clearInfo(layerKey) {
    delete _sections[layerKey];
    _renderPanel();
    _syncLegendShift();
}

function _esc(v) {
    return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

export function irow(label, val) {
    if (val === null || val === undefined || val === '') return '';
    return `<div class="info-row"><span class="info-label">${_esc(label)}</span><span class="info-value">${_esc(val)}</span></div>`;
}

export function irowHtml(label, val) {
    if (val === null || val === undefined || val === '') return '';
    return `<div class="info-row"><span class="info-label">${_esc(label)}</span><span class="info-value">${val}</span></div>`;
}
