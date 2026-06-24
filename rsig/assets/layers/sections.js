import { showSpinner, hideSpinner, PAL, bddOnTop, apiFetch } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

let active = false;
let abortCtrl = null;
let loadId = 0;
const cache = {};

const DEPT_ZOOM    = 9;
const COMMUNE_ZOOM = 13;

// Palette : 7 couleurs tarifs pour secteurs 1–7 (secteur null → gris)
const SECT_PAL = ['#a5d6a7','#c5e1a5','#dce775','#ffcc80','#ffa726','#fb8c00','#e65100'];
const SECT_LABELS = ['Secteur 1','Secteur 2','Secteur 3','Secteur 4','Secteur 5','Secteur 6','Secteur 7'];

// Expression MapLibre : match sur secteur (entier 1–7)
function secteurColorExpr(prop) {
    return ['match', ['to-number', ['get', prop], 0],
        1, SECT_PAL[0],
        2, SECT_PAL[1],
        3, SECT_PAL[2],
        4, SECT_PAL[3],
        5, SECT_PAL[4],
        6, SECT_PAL[5],
        7, SECT_PAL[6],
        '#cccccc',
    ];
}

function bboxParam(map) {
    const b = map.getBounds();
    return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
}

function fetchLayer(url, onData) {
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();
    showSpinner();
    apiFetch(url, { signal: abortCtrl.signal })
        .then(r => r.json())
        .then(d => { hideSpinner(); onData(d); })
        .catch(e => { hideSpinner(); });
}

function getLevel(zoom) {
    if (zoom < DEPT_ZOOM)    return 'dept';
    if (zoom < COMMUNE_ZOOM) return 'commune';
    return 'section';
}

// prop : 'secteur' pour le niveau section, 'secteur_dom' pour commune/dept
function upsert(map, fc, propKey) {
    const color = secteurColorExpr(propKey);
    if (map.getLayer('sections-fill')) {
        map.getSource('sections-src').setData(fc);
        map.setPaintProperty('sections-fill', 'fill-color', color);
    } else {
        if (map.getSource('sections-src')) {
            if (map.getLayer('sections-fill')) map.removeLayer('sections-fill');
            map.removeSource('sections-src');
        }
        map.addSource('sections-src', { type: 'geojson', data: fc });
        map.addLayer({ id: 'sections-fill', type: 'fill', source: 'sections-src',
            paint: { 'fill-color': color, 'fill-opacity': 0.5, 'fill-outline-color': '#000000' } });
        map.on('mouseenter', 'sections-fill', () => map.getCanvas().style.cursor = 'pointer');
        map.on('mouseleave', 'sections-fill', () => map.getCanvas().style.cursor = '');
    }
}

function remove(map) {
    if (map.getLayer('sections-fill')) map.removeLayer('sections-fill');
    if (map.getSource('sections-src')) map.removeSource('sections-src');
}

export function loadSections(map) {
    if (!active) return;
    const zoom  = map.getZoom();
    const level = getLevel(zoom);
    const myId  = ++loadId;
    const render = (fc, renderLevel) => {
        if (myId !== loadId || !active) return;
        if (getLevel(map.getZoom()) !== renderLevel) return;
        if (!fc?.features?.length) {
            if (map.getSource('sections-src'))
                map.getSource('sections-src').setData({ type: 'FeatureCollection', features: [] });
            return;
        }
        // Au niveau section, le champ est 'secteur' ; sinon 'secteur_dom'
        const propKey = renderLevel === 'section' ? 'secteur' : 'secteur_dom';
        upsert(map, fc, propKey);
        bddOnTop(map);
        const niveauLabel = { dept: 'départements', commune: 'communes', section: 'sections' }[renderLevel];
        saveLegend('sections', `Secteurs — ${niveauLabel}`, SECT_LABELS, SECT_PAL, '');
    };

    if (level === 'dept') {
        if (cache['dept']) { render(cache['dept'], 'dept'); return; }
        fetchLayer('/api/sections/departements', fc => { cache['dept'] = fc; render(fc, 'dept'); });
        return;
    }

    const url = level === 'commune'
        ? `/api/sections/communes?bbox=${bboxParam(map)}`
        : `/api/sections?bbox=${bboxParam(map)}`;
    fetchLayer(url, fc => render(fc, level));
}

export function initSections(map) {
    const toggle  = document.getElementById('toggle-sections');
    const options = document.getElementById('sections-options');

    toggle.addEventListener('change', () => {
        active = toggle.checked;
        options.classList.toggle('hidden', !active);
        if (!active) { if (abortCtrl) abortCtrl.abort(); remove(map); dropLegend('sections'); clearInfo('sections'); }
        else loadSections(map);
    });

    map.on('click', 'sections-fill', e => {
        if (!active) return;
        const p = e.features[0].properties;
        if (p.section !== undefined) {
            showInfo('sections', `${p.nom_com} (${p.code_dep}) — Section ${p.section}`,
                irow('Secteur locatif', p.secteur != null ? p.secteur : null)
            );
        } else if (p.code_insee) {
            showInfo('sections', `${p.nom_com} (${p.code_dep})`,
                irow('Nb sections', p.nb_sections) +
                irow('Secteur dominant', p.secteur_dom != null ? p.secteur_dom : null) +
                `<div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez ≥ 13 pour le détail par section</div>`
            );
        } else {
            showInfo('sections', `${p.nom_dep ?? 'Département'} (${p.code_dep})`,
                irow('Nb communes', p.nb_communes) +
                irow('Secteur dominant', p.secteur_dom != null ? p.secteur_dom : null) +
                `<div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez ≥ 9 pour le détail par commune</div>`
            );
        }
    });

    return { load: () => loadSections(map), isActive: () => active };
}
