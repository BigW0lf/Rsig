/**
 * catalogue.js — Panneau gauche à deux onglets
 * Répertoire : groupes repliables, bouton + Ajouter
 * Couches (N) : cards avec œil masquer/afficher, options repliables, × Retirer
 */
import { setLegendVisible } from './legend.js';

// ── Icônes SVG ─────────────────────────────────────────────────────────────
const ICONS = {
    ortho:      'M3 3h18v18H3zM3 9h18M9 21V9',
    taux:       'M2 20h20M5 20V10m4 10V4m4 16v-7m4 7V8',
    coeff:      'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM9 22V12h6v10',
    cfe:        'M2 7h20v14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7zM16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2M12 12v4M10 14h4',
    tf:         'M2 7h20v14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7zM16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2M12 12v4M10 14h4',
    tarifs:     'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
    sections:   'M3 6l6-3 6 3 6-3v15l-6 3-6-3-6 3zM9 3v15M15 6v15',
    ta:         'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM9 22V12h6v10',
    'ta-majore':'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z',
    dossiers:   'M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z',
    'tsb-idf':  'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
    'tsb-paca': 'M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
    tass:       'M3 3h18v18H3zM9 9h6v6H9z',
    zfu:        'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM9 22V12h6v10M12 2v5',
};

const SVG_EYE_OPEN   = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
const SVG_EYE_CLOSED = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`;
const SVG_CHEVRON_DN = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>`;
const SVG_CHEVRON_RT = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 6 15 12 9 18"/></svg>`;

// ── Catalogue ──────────────────────────────────────────────────────────────
// mapPrefix: préfixe des couches MapLibre (pour l'œil). null = pas de layer MapLibre direct.
const CATALOGUE = [
    {
        group: 'Valeur locative',
        layers: [
            { id: 'sections', mapPrefix: 'sections', label: 'Secteurs cadastraux',  optionsId: 'sections-options', desc: 'Sections et tarifs pivot sections_2025' },
            { id: 'coeff',    mapPrefix: 'coeff',    label: 'Coeff. localisation',  optionsId: 'coeff-options',    desc: 'Coefficients de localisation foncière' },
            { id: 'tarifs',   mapPrefix: 'tarifs',   label: 'Tarifs locatifs',      optionsId: 'tarifs-options',   desc: 'Valeurs locatives de référence' },
            { id: 'taux',     mapPrefix: 'taux',     label: 'Taux fiscaux',         optionsId: 'taux-options',     desc: 'Taux communaux TFPB / TFPNB par millésime' },
        ],
    },
    {
        group: 'Estimation €/m²',
        layers: [
            { id: 'cfe', mapPrefix: 'cfe', label: 'CFE estimée €/m²', optionsId: 'cfe-options', desc: 'Cotisation foncière des entreprises estimée' },
            { id: 'tf',  mapPrefix: 'tf',  label: 'TF estimée €/m²',  optionsId: 'tf-options',  desc: 'Taxe foncière estimée par catégorie d\'activité' },
        ],
    },
    {
        group: 'Urbanisme',
        layers: [
            { id: 'ta',        mapPrefix: 'ta',     label: 'Taxe d\'aménagement', optionsId: 'ta-options',        desc: 'TA communale et zones majorées' },
            { id: 'ta-majore', mapPrefix: 'ta-maj', label: 'TA majorée >5%',      optionsId: 'ta-majore-options', desc: 'Zones de taxe d\'aménagement majorée' },
        ],
    },
    {
        group: 'TS',
        layers: [
            { id: 'tsb-idf',  mapPrefix: 'tsb-idf',  label: 'TSB — Circ. IDF',         optionsId: 'tsb-idf-options',  desc: 'Taxe sur les surfaces de bureaux — Île-de-France' },
            { id: 'tsb-paca', mapPrefix: 'tsb-paca', label: 'TSB — PACA',               optionsId: 'tsb-paca-options', desc: 'Taxe sur les surfaces de bureaux — PACA' },
            { id: 'tass',     mapPrefix: 'tass',     label: 'TASS — Stationnement IDF', optionsId: 'tass-options',     desc: 'Taxe annuelle sur les surfaces de stationnement' },
        ],
    },
    {
        group: 'Autres',
        layers: [
            { id: 'dossiers', mapPrefix: 'dossiers', label: 'Dossiers',       optionsId: null, desc: 'Dossiers CRM géolocalisés' },
            { id: 'zfu',      mapPrefix: 'zfu',      label: 'ZFU — Exo. TSB', optionsId: null, desc: 'Zones franches urbaines — exonération TSB' },
            { id: 'ortho',    mapPrefix: null,        label: 'Ortho historique IGN', optionsId: null, desc: 'Campagnes d\'acquisition 2000-2025 + millésimes par département' },
        ],
    },
];

// ── SVG helper ─────────────────────────────────────────────────────────────
function svgIcon(id, size = 13) {
    const d = ICONS[id] || ICONS.sections;
    return `<svg width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="${d}"/></svg>`;
}

// ── State ──────────────────────────────────────────────────────────────────
let _activeTab = 'repertoire';
let _map = null;
let _hidden = {};        // layerId → bool
let _cardCollapsed = {}; // layerId → bool

// Tous les groupes fermés par défaut
let _groupCollapsed = Object.fromEntries(
    CATALOGUE.map(g => [g.group, true])
);

// ── DOM refs ───────────────────────────────────────────────────────────────
let _panel, _tabRepertoire, _tabCouches, _badge, _paneRepertoire, _paneCouches;

// ── Helpers ────────────────────────────────────────────────────────────────
function getToggle(id) { return document.getElementById('toggle-' + id); }

function countActive() {
    return CATALOGUE.flatMap(g => g.layers).filter(l => {
        const cb = getToggle(l.id);
        return cb && cb.checked;
    }).length;
}

function updateBadge() {
    const n = countActive();
    _badge.textContent = n;
    _badge.style.display = n > 0 ? 'inline-flex' : 'none';
}

// Retourne tous les IDs de couches MapLibre correspondant à un préfixe
function getMapLayerIds(prefix) {
    if (!_map || !prefix) return [];
    try {
        return (_map.getStyle()?.layers || [])
            .map(l => l.id)
            .filter(id => id === prefix || id.startsWith(prefix + '-'));
    } catch { return []; }
}

// Applique la visibilité MapLibre (true = visible, false = masqué)
function applyVisibility(layerId, visible) {
    const def = CATALOGUE.flatMap(g => g.layers).find(l => l.id === layerId);
    if (!def) return;
    const ids = getMapLayerIds(def.mapPrefix);
    ids.forEach(id => {
        if (_map.getLayer(id)) {
            _map.setLayoutProperty(id, 'visibility', visible ? 'visible' : 'none');
        }
    });
}

// ── Répertoire — groupes repliables ───────────────────────────────────────
function buildRepertoirePane() {
    const wrap = document.createElement('div');
    wrap.id = 'cat-pane-repertoire';
    wrap.className = 'cat-pane';

    CATALOGUE.forEach(group => {
        const collapsed = !!_groupCollapsed[group.group];

        const groupEl = document.createElement('div');
        groupEl.className = 'cat-group' + (collapsed ? ' cat-group-collapsed' : '');

        // Header cliquable
        const header = document.createElement('button');
        header.className = 'cat-group-header';
        header.innerHTML = `<span class="cat-group-chevron">${collapsed ? SVG_CHEVRON_RT : SVG_CHEVRON_DN}</span><span>${group.group}</span><span class="cat-group-count"></span>`;
        header.addEventListener('click', () => {
            const isNowCollapsed = !groupEl.classList.contains('cat-group-collapsed');
            _groupCollapsed[group.group] = isNowCollapsed;
            groupEl.classList.toggle('cat-group-collapsed', isNowCollapsed);
            header.querySelector('.cat-group-chevron').innerHTML = isNowCollapsed ? SVG_CHEVRON_RT : SVG_CHEVRON_DN;
        });
        groupEl.appendChild(header);

        // Body repliable
        const body = document.createElement('div');
        body.className = 'cat-group-body';

        group.layers.forEach(layer => {
            const row = document.createElement('div');
            row.className = 'cat-layer-row';
            row.dataset.layerId = layer.id;

            const info = document.createElement('div');
            info.className = 'cat-layer-info';
            info.innerHTML = `<span class="cat-layer-icon">${svgIcon(layer.id)}</span><span class="cat-layer-name">${layer.label}</span>`;

            const desc = document.createElement('div');
            desc.className = 'cat-layer-desc';
            desc.textContent = layer.desc;

            const btn = document.createElement('button');
            btn.className = 'cat-btn-add';
            btn.dataset.layerId = layer.id;
            const cb = getToggle(layer.id);
            if (cb && cb.checked) {
                btn.textContent = '✓ Actif';
                btn.classList.add('cat-btn-active');
            } else {
                btn.textContent = '+ Ajouter';
            }

            btn.addEventListener('click', () => {
                const toggle = getToggle(layer.id);
                if (!toggle) return;
                if (!toggle.checked) {
                    toggle.checked = true;
                    toggle.dispatchEvent(new Event('change', { bubbles: true }));
                }
                switchTab('couches');
            });

            row.appendChild(info);
            row.appendChild(desc);
            row.appendChild(btn);
            body.appendChild(row);
        });

        groupEl.appendChild(body);
        wrap.appendChild(groupEl);
    });

    return wrap;
}

// ── Couches — cards avec œil + options repliables ─────────────────────────
function buildCouchesPane() {
    const wrap = document.createElement('div');
    wrap.id = 'cat-pane-couches';
    wrap.className = 'cat-pane';

    const empty = document.createElement('div');
    empty.className = 'cat-empty';
    empty.id = 'cat-empty-msg';
    empty.textContent = 'Aucune couche active. Ajoutez des couches depuis le Répertoire.';
    wrap.appendChild(empty);
    return wrap;
}

function refreshCouchesPane() {
    if (!_paneCouches) return;

    // Remettre les options divs + filtre dossiers dans le holder avant suppression des cards
    const holder = document.getElementById('layer-toggles-holder');
    if (holder) {
        _paneCouches.querySelectorAll('.cat-active-card').forEach(card => {
            card.querySelectorAll('[id$="-options"]').forEach(el => holder.appendChild(el));
            const fw = card.querySelector('#dossiers-filter-wrap');
            if (fw) holder.appendChild(fw);
        });
    }
    _paneCouches.querySelectorAll('.cat-active-card').forEach(c => c.remove());

    const allLayers = CATALOGUE.flatMap(g => g.layers);
    const activeLayers = allLayers.filter(l => { const cb = getToggle(l.id); return cb && cb.checked; });

    const emptyMsg = document.getElementById('cat-empty-msg');
    if (emptyMsg) emptyMsg.style.display = activeLayers.length ? 'none' : 'block';

    activeLayers.forEach(layer => {
        const hidden = !!_hidden[layer.id];
        const bodyCollapsed = !!_cardCollapsed[layer.id]; // options repliées par défaut si première fois
        const hasOptions = !!(layer.optionsId || layer.id === 'dossiers');

        const card = document.createElement('div');
        card.className = 'cat-active-card' + (hidden ? ' cat-card-hidden' : '');
        card.dataset.layerId = layer.id;

        // ── Tête de card ───────────────────────────────────────────────
        const head = document.createElement('div');
        head.className = 'cat-active-head';

        // Icône couche
        const iconEl = document.createElement('span');
        iconEl.className = 'cat-active-icon';
        iconEl.innerHTML = svgIcon(layer.id);
        head.appendChild(iconEl);

        // Label
        const labelEl = document.createElement('span');
        labelEl.className = 'cat-active-label';
        labelEl.textContent = layer.label;
        head.appendChild(labelEl);

        // Bouton œil (masquer / afficher)
        const eyeBtn = document.createElement('button');
        eyeBtn.className = 'cat-btn-eye' + (hidden ? ' cat-btn-eye-hidden' : '');
        eyeBtn.title = hidden ? 'Afficher la couche' : 'Masquer la couche';
        eyeBtn.innerHTML = hidden ? SVG_EYE_CLOSED : SVG_EYE_OPEN;
        eyeBtn.addEventListener('click', () => {
            _hidden[layer.id] = !_hidden[layer.id];
            const nowHidden = _hidden[layer.id];
            applyVisibility(layer.id, !nowHidden);
            setLegendVisible(layer.id, !nowHidden);
            eyeBtn.innerHTML = nowHidden ? SVG_EYE_CLOSED : SVG_EYE_OPEN;
            eyeBtn.title = nowHidden ? 'Afficher la couche' : 'Masquer la couche';
            eyeBtn.classList.toggle('cat-btn-eye-hidden', nowHidden);
            card.classList.toggle('cat-card-hidden', nowHidden);
        });
        head.appendChild(eyeBtn);

        // Corps de card (options) — créé tôt pour que le chevron puisse le référencer
        let cardBody = null;
        if (hasOptions) {
            cardBody = document.createElement('div');
            cardBody.className = 'cat-card-body';
            cardBody.style.display = bodyCollapsed ? 'none' : 'block';

            if (layer.optionsId) {
                const optionsEl = document.getElementById(layer.optionsId);
                if (optionsEl) { optionsEl.classList.remove('hidden'); cardBody.appendChild(optionsEl); }
            }
            if (layer.id === 'dossiers') {
                const filterWrap = window._dossiersFilter?.getContainer?.();
                if (filterWrap && !cardBody.contains(filterWrap)) cardBody.appendChild(filterWrap);
            }
        }

        // Bouton chevron — référence directe à cardBody (pas de clone)
        if (hasOptions && cardBody) {
            const chevronBtn = document.createElement('button');
            chevronBtn.className = 'cat-btn-chevron';
            chevronBtn.title = bodyCollapsed ? 'Voir les options' : 'Réduire';
            chevronBtn.innerHTML = bodyCollapsed ? SVG_CHEVRON_RT : SVG_CHEVRON_DN;
            chevronBtn.addEventListener('click', () => {
                _cardCollapsed[layer.id] = !_cardCollapsed[layer.id];
                const nowCollapsed = _cardCollapsed[layer.id];
                cardBody.style.display = nowCollapsed ? 'none' : 'block';
                chevronBtn.innerHTML = nowCollapsed ? SVG_CHEVRON_RT : SVG_CHEVRON_DN;
                chevronBtn.title = nowCollapsed ? 'Voir les options' : 'Réduire';
            });
            head.appendChild(chevronBtn);
        }

        // Bouton × Retirer
        const removeBtn = document.createElement('button');
        removeBtn.className = 'cat-btn-remove';
        removeBtn.title = 'Retirer la couche';
        removeBtn.textContent = '×';
        removeBtn.addEventListener('click', () => {
            const toggle = getToggle(layer.id);
            if (!toggle) return;
            _hidden[layer.id] = false;
            toggle.checked = false;
            toggle.dispatchEvent(new Event('change', { bubbles: true }));
        });
        head.appendChild(removeBtn);

        card.appendChild(head);
        if (cardBody) card.appendChild(cardBody);

        _paneCouches.appendChild(card);
    });
}

// ── Sync boutons Répertoire ────────────────────────────────────────────────
function syncRepertoireButtons() {
    if (!_paneRepertoire) return;
    _paneRepertoire.querySelectorAll('.cat-btn-add').forEach(btn => {
        const cb = getToggle(btn.dataset.layerId);
        if (!cb) return;
        if (cb.checked) {
            btn.textContent = '✓ Actif';
            btn.classList.add('cat-btn-active');
        } else {
            btn.textContent = '+ Ajouter';
            btn.classList.remove('cat-btn-active');
        }
    });
}

// ── Tab switching ──────────────────────────────────────────────────────────
function switchTab(tab) {
    _activeTab = tab;
    const isRep = tab === 'repertoire';
    _tabRepertoire.classList.toggle('cat-tab-active', isRep);
    _tabCouches.classList.toggle('cat-tab-active', !isRep);
    _paneRepertoire.style.display = isRep ? 'block' : 'none';
    _paneCouches.style.display    = isRep ? 'none'  : 'block';
    if (!isRep) refreshCouchesPane();
}

// ── Tracking couches ───────────────────────────────────────────────────────
let _trackTimer = null;
function trackLayers() {
    clearTimeout(_trackTimer);
    _trackTimer = setTimeout(() => {
        const active = CATALOGUE.flatMap(g => g.layers)
            .filter(l => { const cb = getToggle(l.id); return cb?.checked; })
            .map(l => l.id);
        fetch('/api/track/layers', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ layers: active }),
        }).catch(e => console.warn('[catalogue] track', e));
    }, 3000);
}

// ── Observe checkboxes ─────────────────────────────────────────────────────
function observeToggles() {
    CATALOGUE.flatMap(g => g.layers).forEach(layer => {
        const cb = getToggle(layer.id);
        if (!cb) return;
        cb.addEventListener('change', () => {
            updateBadge();
            syncRepertoireButtons();
            if (_activeTab === 'couches') refreshCouchesPane();
            trackLayers();
        });
    });
}

// ── CSS ────────────────────────────────────────────────────────────────────
function injectStyles() {
    if (document.getElementById('catalogue-styles')) return;
    const style = document.createElement('style');
    style.id = 'catalogue-styles';
    style.textContent = `
/* ── Tabs ──────────────────────────────── */
.cat-tabs {
    display: flex;
    background: var(--blue);
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.cat-tab {
    flex: 1;
    padding: 9px 6px 8px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: rgba(255,255,255,.65);
    background: none;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    transition: background .15s, color .15s;
    border-bottom: 2px solid transparent;
}
.cat-tab:hover { color: #fff; background: rgba(255,255,255,.1); }
.cat-tab-active { color: #fff; border-bottom-color: rgba(255,255,255,.85); background: rgba(255,255,255,.08); }
.cat-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    background: var(--accent);
    color: #fff;
    border-radius: 8px;
    font-size: 10px;
    font-weight: 700;
    line-height: 1;
}

/* ── Panes ─────────────────────────────── */
.cat-pane { flex: 1; overflow-y: auto; }

/* ── Groupes repliables ─────────────────── */
.cat-group { border-bottom: 1px solid var(--border); }
.cat-group-header {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 6px 10px 5px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .7px;
    color: var(--text3);
    background: var(--surface2);
    border: none;
    border-bottom: 1px solid var(--border2);
    cursor: pointer;
    text-align: left;
    transition: background .12s;
}
.cat-group-header:hover { background: var(--blue-hover); color: var(--text); }
.cat-group-chevron { display: flex; align-items: center; opacity: .6; flex-shrink: 0; }
.cat-group-header > span:nth-child(2) { flex: 1; }
.cat-group-body { overflow: hidden; }
.cat-group-collapsed .cat-group-body { display: none; }
.cat-layer-row {
    padding: 7px 12px;
    border-bottom: 1px solid var(--border2);
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.cat-layer-row:last-child { border-bottom: none; }
.cat-layer-info { display: flex; align-items: center; gap: 6px; font-size: 0.84rem; font-weight: 500; color: var(--text); }
.cat-layer-icon { opacity: .55; flex-shrink: 0; display: flex; align-items: center; }
.cat-layer-name { flex: 1; }
.cat-layer-desc { font-size: 11px; color: var(--text3); margin-left: 19px; }
.cat-btn-add {
    align-self: flex-end;
    margin-top: 2px;
    padding: 3px 9px;
    font-size: 11px;
    font-weight: 600;
    background: var(--blue-hover);
    color: var(--blue-light);
    border: 1px solid var(--border);
    border-radius: 4px;
    cursor: pointer;
    transition: background .15s, color .15s;
    white-space: nowrap;
}
.cat-btn-add:hover { background: var(--blue); color: #fff; }
.cat-btn-active { background: #f0fdf4; color: var(--green); border-color: #86efac; cursor: default; }
.cat-btn-active:hover { background: #f0fdf4; color: var(--green); }

/* ── Couches actives ────────────────────── */
.cat-empty {
    padding: 18px 14px;
    font-size: 12px;
    color: var(--text3);
    text-align: center;
    line-height: 1.5;
}
.cat-active-card {
    border-bottom: 1px solid var(--border);
    background: var(--surface);
}
.cat-active-card.cat-card-hidden { opacity: .45; }
.cat-active-head {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 7px 8px 6px 11px;
    background: var(--surface2);
    border-bottom: 1px solid var(--border2);
}
.cat-active-icon { opacity: .55; flex-shrink: 0; display: flex; align-items: center; }
.cat-active-label { flex: 1; font-size: 0.84rem; font-weight: 600; color: var(--text); }
.cat-btn-eye {
    width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center;
    padding: 0;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 4px;
    cursor: pointer;
    color: var(--text2);
    flex-shrink: 0;
    transition: background .12s, color .12s;
}
.cat-btn-eye:hover { background: var(--blue-hover); color: var(--blue-light); border-color: var(--border); }
.cat-btn-eye.cat-btn-eye-hidden { color: var(--text3); opacity: .5; }
.cat-btn-chevron {
    width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center;
    padding: 0;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 4px;
    cursor: pointer;
    color: var(--text3);
    flex-shrink: 0;
    transition: background .12s, color .12s;
}
.cat-btn-chevron:hover { background: var(--blue-hover); color: var(--blue-light); border-color: var(--border); }
.cat-btn-remove {
    width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; line-height: 1; padding: 0;
    background: rgba(200,16,46,.08);
    color: var(--accent);
    border: 1px solid rgba(200,16,46,.2);
    border-radius: 4px;
    cursor: pointer;
    transition: background .15s;
    flex-shrink: 0;
}
.cat-btn-remove:hover { background: var(--accent); color: #fff; }

/* ── Corps de card (options) ────────────── */
.cat-card-body .layer-sub {
    margin: 0;
    padding: 8px 12px 10px;
    display: flex !important;
}
.cat-card-body .layer-sub.hidden { display: flex !important; }
#dossiers-filter-wrap {
    padding: 8px 12px 10px;
    border-top: 1px solid var(--border2);
}
    `;
    document.head.appendChild(style);
}

// ── Init ───────────────────────────────────────────────────────────────────
export function initCatalogue(map) {
    _map = map || null;
    _panel = document.getElementById('panel-left');
    if (!_panel) return;

    injectStyles();

    const allLayers = CATALOGUE.flatMap(g => g.layers);

    // Détacher checkboxes + options divs dans un holder caché pour que getElementById fonctionne
    const holder = document.createElement('div');
    holder.id = 'layer-toggles-holder';
    holder.style.display = 'none';

    allLayers.forEach(layer => {
        const cb = document.getElementById('toggle-' + layer.id);
        if (cb && cb.parentNode) { cb.parentNode.removeChild(cb); holder.appendChild(cb); }
        if (layer.optionsId) {
            const optEl = document.getElementById(layer.optionsId);
            if (optEl && optEl.parentNode) { optEl.parentNode.removeChild(optEl); holder.appendChild(optEl); }
        }
    });

    _panel.innerHTML = '';
    _panel.appendChild(holder);

    // Tab bar
    const tabBar = document.createElement('div');
    tabBar.className = 'cat-tabs';

    _tabRepertoire = document.createElement('button');
    _tabRepertoire.className = 'cat-tab cat-tab-active';
    _tabRepertoire.innerHTML = '<span class="tab-label">Répertoire</span>';
    _tabRepertoire.addEventListener('click', () => switchTab('repertoire'));

    _tabCouches = document.createElement('button');
    _tabCouches.className = 'cat-tab';
    _tabCouches.innerHTML = '<span class="tab-label">Couches</span><span class="cat-badge" id="cat-badge" style="display:none">0</span>';
    _tabCouches.addEventListener('click', () => switchTab('couches'));

    tabBar.appendChild(_tabRepertoire);
    tabBar.appendChild(_tabCouches);
    _panel.appendChild(tabBar);

    _badge = document.getElementById('cat-badge');

    const panesWrap = document.createElement('div');
    panesWrap.style.cssText = 'display:flex;flex-direction:column;flex:1;overflow:hidden;';
    _panel.appendChild(panesWrap);

    _paneRepertoire = buildRepertoirePane();
    panesWrap.appendChild(_paneRepertoire);

    _paneCouches = buildCouchesPane();
    _paneCouches.style.display = 'none';
    panesWrap.appendChild(_paneCouches);

    observeToggles();
    updateBadge();
}
