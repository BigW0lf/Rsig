const panelRight = document.getElementById('panel-right');
document.getElementById('close-right').addEventListener('click', () => panelRight.classList.add('hidden'));

// Sections par couche affichées dans le panneau
const _sections = {};

function _renderPanel() {
    const keys = ['taux', 'tarifs', 'coeff', 'cfe', 'sections', 'zfu', 'dossiers'];
    const active = keys.filter(k => _sections[k]);
    if (!active.length) { panelRight.classList.add('hidden'); return; }
    const first = _sections[active[0]];
    document.getElementById('info-title').textContent = first.title;
    document.getElementById('info-content').innerHTML = active.map((k, i) => {
        const s = _sections[k];
        if (i === 0) return `<div class="info-section">${s.html}</div>`;
        return `<div class="info-section-sep">${s.title}</div><div class="info-section">${s.html}</div>`;
    }).join('');
    panelRight.classList.remove('hidden');
}

export function showInfo(layerKey, title, html) {
    _sections[layerKey] = { title, html };
    _renderPanel();
    panelRight.scrollTop = 0;
}

export function clearInfo(layerKey) {
    delete _sections[layerKey];
    _renderPanel();
}

function _esc(v) {
    return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

export function irow(label, val) {
    if (val === null || val === undefined || val === '') return '';
    return `<div class="info-row"><span class="info-label">${_esc(label)}</span><span class="info-value">${_esc(val)}</span></div>`;
}
