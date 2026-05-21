import { showSpinner, hideSpinner, stepExpr, PAL, computeBreaks } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, irow } from '../panel.js';

let active = false;
let abortCtrl = null;
const deptCache    = {};
const globalBreaks = {};

function bboxParam(map) {
    const b = map.getBounds();
    return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
}

function fetchLayer(map, url, onData) {
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();
    showSpinner();
    fetch(url, { signal: abortCtrl.signal })
        .then(r => r.json())
        .then(d => { hideSpinner(); onData(d); })
        .catch(e => { hideSpinner(); if (e.name !== 'AbortError') console.error('taux', e); });
}

function upsert(map, fc, color) {
    if (map.getLayer('taux-fill')) {
        map.getSource('taux-src').setData(fc);
        map.setPaintProperty('taux-fill', 'fill-color', color);
    } else {
        if (map.getSource('taux-src')) { map.removeLayer('taux-line'); map.removeSource('taux-src'); }
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

function getBreaks(champ, cb) {
    if (globalBreaks[champ]) { cb(globalBreaks[champ]); return; }
    fetch(`/api/taux/stats?champ=${champ}`)
        .then(r => r.json())
        .then(b => { globalBreaks[champ] = b; cb(b); })
        .catch(() => cb(null));
}

const DEPT_ZOOM = 9;

export function loadTaux(map, tauxChamp, tauxLevelInfo) {
    if (!active) return;
    const champ  = tauxChamp.value;
    const isDept = map.getZoom() < DEPT_ZOOM;
    if (tauxLevelInfo) tauxLevelInfo.textContent = isDept
        ? 'Niveau : département (zoom < 9)'
        : 'Niveau : commune (zoom ≥ 9)';

    getBreaks(champ, breaks => {
        const render = (fc, level) => {
            if ((map.getZoom() < DEPT_ZOOM) !== (level === 'dept')) return;
            if (!fc?.features?.length) {
                if (map.getSource('taux-src')) map.getSource('taux-src').setData({ type: 'FeatureCollection', features: [] });
                return;
            }
            const b = breaks ?? computeBreaks(fc.features.map(f => +f.properties.valeur_affichee).filter(v => isFinite(v)), 5);
            upsert(map, fc, stepExpr('valeur_affichee', b, PAL.taux));
            const champLabel  = tauxChamp.options[tauxChamp.selectedIndex].text;
            const niveauLabel = level === 'dept' ? 'moy. par département' : 'communes';
            saveLegend('taux', `${champLabel} — ${niveauLabel}`, b, PAL.taux, ' %');
        };

        if (isDept) {
            if (deptCache[champ]) { render(deptCache[champ], 'dept'); return; }
            fetchLayer(map, `/api/taux/departements?champ=${champ}`, fc => { deptCache[champ] = fc; render(fc, 'dept'); });
        } else {
            fetchLayer(map, `/api/taux?bbox=${bboxParam(map)}&champ=${champ}`, fc => render(fc, 'commune'));
        }
    });
}

export function initTaux(map) {
    const toggle    = document.getElementById('toggle-taux');
    const options   = document.getElementById('taux-options');
    const champ     = document.getElementById('taux-champ');
    const levelInfo = document.getElementById('taux-level-info');

    toggle.addEventListener('change', () => {
        active = toggle.checked;
        options.classList.toggle('hidden', !active);
        if (!active) { remove(map); dropLegend('taux'); }
        else loadTaux(map, champ, levelInfo);
    });
    champ.addEventListener('change', () => loadTaux(map, champ, levelInfo));

    map.on('click', 'taux-fill', e => {
        const p = e.features[0].properties;
        const v = p.valeur_affichee;
        const champLabel = champ.options[champ.selectedIndex].text;
        if (p.nom_dep) {
            showInfo(`${p.nom_dep} (${p.code_dep})`, `
                ${irow(champLabel + ' moyen', v != null ? (+v).toFixed(4)+' %' : '–')}
                <div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez ≥ 9 pour voir le détail par commune</div>
            `);
        } else {
            showInfo(`${p.libcom} (${p.com})`, `
                ${irow('Département', p.dep)}
                ${irow('Millésime', p.millesime)}
                ${irow(champLabel, v != null ? (+v).toFixed(4)+' %' : '–')}
                ${irow('TF bâti',    p.taux_fb_commune_vote != null ? (+p.taux_fb_commune_vote).toFixed(4)+' %' : '–')}
                ${irow('TF non bâti',p.taux_fnb_commune     != null ? (+p.taux_fnb_commune).toFixed(4)+' %'     : '–')}
                ${irow('TSE net',    p.taux_tse_net         != null ? (+p.taux_tse_net).toFixed(4)+' %'         : '–')}
                ${irow('TEOM',       p.taux_teom_plein      != null ? (+p.taux_teom_plein).toFixed(4)+' %'      : '–')}
            `);
        }
    });

    return { load: () => loadTaux(map, champ, levelInfo), isActive: () => active };
}

// Utilisé par map.js pour réordonner les couches
function bddOnTop(map) {
    ['taux-fill','taux-line','coeff-fill','coeff-line','tarifs-fill','tarifs-line',
     'dossiers-circle','dossiers-cluster','dossiers-cluster-count',
    ].forEach(id => { if (map.getLayer(id)) map.moveLayer(id); });
}
