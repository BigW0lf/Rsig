import { showSpinner, hideSpinner, stepExpr, PAL, computeBreaks, bddOnTop } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

let active   = false;
let abortCtrl = null;
let loadId    = 0;
const cache        = {};
const globalBreaks = {};

const DEPT_ZOOM = 9;

const CHAMP_LABELS = {
    taux_fb_commune_vote:  'TFPB Commune',
    taux_fb_syndicats_net: 'TFPB Syndicat',
    taux_fb_gfp_vote:      'TFPB EPCI',
    taux_tse_net:          'TFPB TSE',
    taux_tafnb_commune_net:'TFPB TASA',
    taux_teom_plein:       'TFPB TEOM',
    taux_tse_gemapi_net:   'TFPB GEMAPI',
    taux_fnb_commune:      'TFPNB Commune',
    taux_fnb_syndicats_net:'TFPNB Syndicat',
    taux_fnb_gfp_vote:     'TFPNB EPCI',
    taux_tafnb_gfp_net:    'TFPNB TASA EPCI',
};

function bboxParam(map) {
    const b = map.getBounds();
    return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
}

function fetchLayer(url, onData) {
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();
    showSpinner();
    fetch(url, { signal: abortCtrl.signal })
        .then(r => r.json())
        .then(d => { hideSpinner(); onData(d); })
        .catch(e => { hideSpinner(); if (e.name !== 'AbortError') console.error('taux', e); });
}

function getBreaks(champ, millesime, cb) {
    const key = `${champ}|${millesime}`;
    if (globalBreaks[key]) { cb(globalBreaks[key]); return; }
    fetch(`/api/taux/stats?champ=${champ}&millesime=${millesime}`)
        .then(r => r.json())
        .then(b => { globalBreaks[key] = b; cb(b); })
        .catch(() => cb(null));
}

function upsert(map, fc, color) {
    if (map.getLayer('taux-fill')) {
        map.getSource('taux-src').setData(fc);
        map.setPaintProperty('taux-fill', 'fill-color', color);
    } else {
        ['taux-fill','taux-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
        if (map.getSource('taux-src')) map.removeSource('taux-src');
        map.addSource('taux-src', { type: 'geojson', data: fc });
        map.addLayer({ id: 'taux-fill', type: 'fill', source: 'taux-src', paint: { 'fill-color': color, 'fill-opacity': 0.7 } });
        map.addLayer({ id: 'taux-line', type: 'line', source: 'taux-src', paint: { 'line-color': '#334', 'line-width': 0.5 } });
        map.on('mouseenter', 'taux-fill', () => map.getCanvas().style.cursor = 'pointer');
        map.on('mouseleave', 'taux-fill', () => map.getCanvas().style.cursor = '');
    }
    bddOnTop(map);
}

function remove(map) {
    ['taux-fill','taux-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
    if (map.getSource('taux-src')) map.removeSource('taux-src');
}

function getLevel(zoom) { return zoom < DEPT_ZOOM ? 'dept' : 'commune'; }

export function loadTaux(map) {
    if (!active) return;
    const champ     = document.getElementById('taux-champ').value;
    const millesime = document.getElementById('taux-millesime').value;
    const level     = getLevel(map.getZoom());
    const myId      = ++loadId;

    getBreaks(champ, millesime, globalB => {
        if (myId !== loadId) return;
        const render = (fc, renderLevel) => {
            if (myId !== loadId) return;
            if (getLevel(map.getZoom()) !== renderLevel) return;
            if (!fc?.features?.length) {
                if (map.getSource('taux-src')) map.getSource('taux-src').setData({ type: 'FeatureCollection', features: [] });
                return;
            }
            const breaks = globalB ?? computeBreaks(fc.features.map(f => +f.properties.valeur_affichee).filter(v => isFinite(v)), 5);
            upsert(map, fc, stepExpr('valeur_affichee', breaks, PAL.taux));
            const label       = CHAMP_LABELS[champ] ?? champ;
            const niveauLabel = renderLevel === 'dept' ? 'moy. par département' : 'communes';
            saveLegend('taux', `${label} ${millesime} — ${niveauLabel}`, breaks, PAL.taux, ' %');
        };

        if (level === 'dept') {
            const key = `${champ}|${millesime}|dept`;
            if (cache[key]) { render(cache[key], 'dept'); return; }
            fetchLayer(`/api/taux/departements?champ=${champ}&millesime=${millesime}`, fc => { cache[key] = fc; render(fc, 'dept'); });
        } else {
            fetchLayer(`/api/taux?bbox=${bboxParam(map)}&champ=${champ}&millesime=${millesime}`, fc => render(fc, 'commune'));
        }
    });
}

export function initTaux(map) {
    const toggle      = document.getElementById('toggle-taux');
    const options     = document.getElementById('taux-options');
    const champEl     = document.getElementById('taux-champ');
    const millesimeEl = document.getElementById('taux-millesime');

    fetch('/api/taux/millesimes')
        .then(r => r.json())
        .then(ms => {
            if (!ms?.length) return;
            millesimeEl.innerHTML = ms.map(m => `<option value="${m}">${m}</option>`).join('');
            if (active) loadTaux(map);
        });

    toggle.addEventListener('change', () => {
        active = toggle.checked;
        options.classList.toggle('hidden', !active);
        if (!active) { remove(map); dropLegend('taux'); clearInfo('taux'); }
        else loadTaux(map);
    });
    champEl.addEventListener('change', () => { clearInfo('taux'); loadTaux(map); });
    millesimeEl.addEventListener('change', () => {
        Object.keys(cache).forEach(k => delete cache[k]);
        clearInfo('taux');
        loadTaux(map);
    });

    map.on('click', 'taux-fill', e => {
        const p     = e.features[0].properties;
        const v     = p.valeur_affichee;
        const label = CHAMP_LABELS[champEl.value] ?? champEl.value;
        const mil   = millesimeEl.value;
        const fmt   = val => val != null ? (+val).toFixed(4) + ' %' : '–';

        if (p.nom_dep) {
            showInfo('taux', `${p.nom_dep} (${p.code_dep})`,
                irow(label + ' moyen ' + mil, fmt(v)) +
                `<div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez ≥ 9 pour voir le détail par commune</div>`
            );
        } else {
            showInfo('taux', `${p.libcom} (${p.dep})`,
                irow('Millésime', p.millesime) +
                irow(label, fmt(v)) +
                `<details style="margin-top:6px"><summary style="cursor:pointer;font-size:11px;color:var(--text3)">Tous les taux</summary>
                ${irow('TFPB Commune',    fmt(p.taux_fb_commune_vote))}
                ${irow('TFPB Syndicat',  fmt(p.taux_fb_syndicats_net))}
                ${irow('TFPB EPCI',      fmt(p.taux_fb_gfp_vote))}
                ${irow('TFPB TSE',       fmt(p.taux_tse_net))}
                ${irow('TFPB TASA',      fmt(p.taux_tafnb_commune_net))}
                ${irow('TFPB TEOM',      fmt(p.taux_teom_plein))}
                ${irow('TFPB GEMAPI',    fmt(p.taux_tse_gemapi_net))}
                ${irow('TFPNB Commune',  fmt(p.taux_fnb_commune))}
                ${irow('TFPNB Syndicat', fmt(p.taux_fnb_syndicats_net))}
                ${irow('TFPNB EPCI',     fmt(p.taux_fnb_gfp_vote))}
                ${irow('TFPNB TASA EPCI',fmt(p.taux_tafnb_gfp_net))}
                </details>`
            );
        }
    });

    return { load: () => loadTaux(map), isActive: () => active };
}
