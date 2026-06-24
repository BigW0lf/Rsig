/**
 * state.js — Persistance couches (localStorage) + Permalien (URL hash)
 *
 * Format hash : #z=14&c=2.35,46.6&l=taux:champ=taux_fb_commune_vote,millesime=2025;cfe:categorie=BUR1,annee=2024
 * Format localStorage : même structure JSON
 */

const LS_KEY = 'rsig_state';

// ── Selects liés à chaque couche ────────────────────────────────────────────
const LAYER_OPTS = {
    taux:        ['taux-champ', 'taux-millesime'],
    coeff:       ['coeff-champ'],
    cfe:         ['cfe-categorie', 'cfe-annee'],
    tf:          ['tf-categorie', 'tf-annee'],
    ta:          ['ta-mode', 'ta-champ', 'ta-annee', 'ta-millesime-union'],
    'ta-majore': ['ta-majore-millesime'],
    'tsb-idf':   ['tsb-idf-millesime'],
    'tsb-paca':  ['tsb-paca-millesime'],
    tass:        ['tass-millesime'],
    tarifs:      ['tarifs-cat', 'tarifs-annee'],
};

// Couches sans options
const ALL_LAYERS = [
    'ortho','taux','coeff','sections','cfe','tf','ta','ta-majore',
    'tsb-idf','tsb-paca','tass','zfu','dossiers','tarifs',
];

// ── Snapshot de l'état courant ───────────────────────────────────────────────
export function snapshotState(map) {
    const center = map.getCenter();
    const zoom   = Math.round(map.getZoom() * 10) / 10;

    const layers = {};
    ALL_LAYERS.forEach(id => {
        const cb = document.getElementById('toggle-' + id);
        if (!cb?.checked) return;
        const opts = {};
        (LAYER_OPTS[id] || []).forEach(selId => {
            const sel = document.getElementById(selId);
            if (sel?.value) opts[selId.replace(id + '-', '').replace(id.replace('-','') + '-','')] = sel.value;
        });
        layers[id] = opts;
    });

    // Campagne ortho depuis le contrôle IGN
    const ignSel = document.getElementById('ign-campagne-ctrl');
    if (ignSel?.value && ignSel.value !== 'actuelle') layers['_campagne'] = ignSel.value;

    return { z: zoom, c: [+center.lng.toFixed(5), +center.lat.toFixed(5)], l: layers };
}

// ── Applique un état sauvegardé ──────────────────────────────────────────────
export function applyState(state, map) {
    if (!state) return;

    // Position
    if (state.c && state.z) {
        map.jumpTo({ center: state.c, zoom: state.z });
    }

    // Campagne ortho
    if (state.l?.['_campagne']) {
        const ignSel = document.getElementById('ign-campagne-ctrl');
        if (ignSel) { ignSel.value = state.l['_campagne']; ignSel.dispatchEvent(new Event('change')); }
    }

    // Couches + options
    const toActivate = Object.keys(state.l || {}).filter(k => k !== '_campagne');
    toActivate.forEach(id => {
        const opts = state.l[id];
        // Appliquer les options AVANT de cocher (les select doivent être prêts)
        (LAYER_OPTS[id] || []).forEach(selId => {
            const key = selId.replace(id + '-', '').replace(id.replace('-','') + '-', '');
            if (opts[key]) {
                const sel = document.getElementById(selId);
                if (sel) sel.value = opts[key];
            }
        });
        // Cocher la couche
        const cb = document.getElementById('toggle-' + id);
        if (cb && !cb.checked) {
            cb.checked = true;
            cb.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
}

// ── Sérialise en hash URL compact ───────────────────────────────────────────
function stateToHash(state) {
    const parts = ['z=' + state.z, 'c=' + state.c.join(',')];
    const lParts = Object.entries(state.l || {}).map(([id, opts]) => {
        const optStr = Object.entries(opts).map(([k, v]) => k + '=' + encodeURIComponent(v)).join(',');
        return optStr ? id + ':' + optStr : id;
    });
    if (lParts.length) parts.push('l=' + lParts.join(';'));
    return '#' + parts.join('&');
}

function hashToState(hash) {
    if (!hash || hash === '#') return null;
    try {
        const params = {};
        hash.replace(/^#/, '').split('&').forEach(p => {
            const eq = p.indexOf('=');
            if (eq > -1) params[p.slice(0, eq)] = p.slice(eq + 1);
        });
        const z = parseFloat(params.z);
        const [lng, lat] = (params.c || '').split(',').map(Number);
        const l = {};
        if (params.l) {
            params.l.split(';').forEach(seg => {
                const colon = seg.indexOf(':');
                const id    = colon > -1 ? seg.slice(0, colon) : seg;
                const opts  = {};
                if (colon > -1) {
                    seg.slice(colon + 1).split(',').forEach(o => {
                        const eq2 = o.indexOf('=');
                        if (eq2 > -1) opts[o.slice(0, eq2)] = decodeURIComponent(o.slice(eq2 + 1));
                    });
                }
                l[id] = opts;
            });
        }
        if (!isNaN(z) && !isNaN(lng) && !isNaN(lat)) return { z, c: [lng, lat], l };
    } catch {}
    return null;
}

// ── Persistance localStorage ─────────────────────────────────────────────────
export function saveState(map) {
    try {
        localStorage.setItem(LS_KEY, JSON.stringify(snapshotState(map)));
    } catch {}
}

export function loadSavedState() {
    try {
        const raw = localStorage.getItem(LS_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch { return null; }
}

// ── Permalien ────────────────────────────────────────────────────────────────
export function copyPermalink(map) {
    const state = snapshotState(map);
    const url   = location.origin + location.pathname + stateToHash(state);
    navigator.clipboard?.writeText(url).then(() => {
        showToast('Lien copié !');
    }).catch(() => {
        prompt('Copie ce lien :', url);
    });
    // Mettre à jour l'URL sans recharger
    history.replaceState(null, '', stateToHash(state));
}

export function readHashState() {
    return hashToState(location.hash);
}

// ── Toast notification ───────────────────────────────────────────────────────
export function showToast(msg) {
    let t = document.getElementById('rsig-toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'rsig-toast';
        t.style.cssText = [
            'position:fixed', 'bottom:52px', 'left:50%', 'transform:translateX(-50%)',
            'background:#1a2332', 'color:#fff', 'padding:8px 18px', 'border-radius:20px',
            'font-size:12px', 'font-weight:600', 'z-index:3000', 'pointer-events:none',
            'opacity:0', 'transition:opacity .2s',
        ].join(';');
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.style.opacity = '0'; }, 2000);
}
