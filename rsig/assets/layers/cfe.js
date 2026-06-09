import { showSpinner, hideSpinner, stepExpr, PAL, bddOnTop } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

let active = false;
let abortCtrl = null;
let loadId = 0;
const deptCache   = {};
const breaksCache = {};

const DEPT_ZOOM = 11;

function bboxParam(map) {
    const b = map.getBounds();
    return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
}

function getOptions() {
    return {
        cat:   document.getElementById('cfe-categorie')?.value || '',
        annee: document.getElementById('cfe-annee')?.value     || '2026',
    };
}

function cacheKey(cat, annee) { return `${cat}_${annee}`; }

function fetchLayer(url, onData) {
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();
    showSpinner();
    fetch(url, { signal: abortCtrl.signal })
        .then(r => r.json())
        .then(d => { hideSpinner(); onData(d); })
        .catch(e => { hideSpinner(); if (e.name !== 'AbortError') console.error('cfe', e); });
}

function quantileBreaks(values, n) {
    const sorted = values.filter(v => isFinite(v) && v > 0).sort((a, b) => a - b);
    if (!sorted.length) return null;
    const breaks = [];
    for (let i = 0; i <= n; i++) {
        const idx = Math.round(i * (sorted.length - 1) / n);
        breaks.push(+sorted[idx].toFixed(4));
    }
    // dédupliquer les breaks identiques consécutifs
    const unique = [breaks[0]];
    for (let i = 1; i < breaks.length; i++) {
        if (breaks[i] > unique[unique.length - 1]) unique.push(breaks[i]);
    }
    return unique.length > 1 ? unique : null;
}

function upsert(map, fc, color) {
    if (map.getLayer('cfe-fill')) {
        map.getSource('cfe-src').setData(fc);
        map.setPaintProperty('cfe-fill', 'fill-color', color);
    } else {
        if (map.getSource('cfe-src')) {
            ['cfe-fill', 'cfe-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
            map.removeSource('cfe-src');
        }
        map.addSource('cfe-src', { type: 'geojson', data: fc });
        map.addLayer({ id: 'cfe-fill', type: 'fill', source: 'cfe-src',
            paint: { 'fill-color': color, 'fill-opacity': 0.45 } });
        map.addLayer({ id: 'cfe-line', type: 'line', source: 'cfe-src',
            paint: { 'line-color': '#444', 'line-width': 0.5 } });
    }
    bddOnTop(map);
}

function remove(map) {
    ['cfe-fill', 'cfe-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
    if (map.getSource('cfe-src')) map.removeSource('cfe-src');
}

export function loadCfe(map) {
    if (!active) return;
    const { cat, annee } = getOptions();

    // Pas de chargement sans catégorie
    if (!cat) {
        const msg = document.getElementById('cfe-msg');
        if (msg) msg.textContent = 'Choisissez une catégorie pour afficher la couche.';
        return;
    }
    const msg = document.getElementById('cfe-msg');
    if (msg) msg.textContent = '';

    const zoom  = map.getZoom();
    const level = zoom < DEPT_ZOOM ? 'dept' : 'commune';

    const myId = ++loadId;

    const render = (fc, renderLevel) => {
        if (myId !== loadId || !active) return;
        if (!fc?.features?.length) {
            if (map.getSource('cfe-src'))
                map.getSource('cfe-src').setData({ type: 'FeatureCollection', features: [] });
            return;
        }
        // Breaks stables : calculés une fois par cat+annee+level, jamais recalculés sur un pan
        const bKey = cacheKey(cat, annee) + '_' + renderLevel;
        if (!breaksCache[bKey]) {
            const values = fc.features.map(f => +f.properties.cfe_estime);
            breaksCache[bKey] = quantileBreaks(values, 6);
        }
        const breaks = breaksCache[bKey];
        if (!breaks) return;
        upsert(map, fc, stepExpr('cfe_estime', breaks, PAL.cfe));
        const niveauLabel = renderLevel === 'dept' ? 'moy. par département' : 'communes';
        saveLegend('cfe', `CFE €/m² — ${cat} ${annee} (${niveauLabel})`, breaks, PAL.cfe, ' €/m²');
    };

    const params = `categorie=${cat}&annee=${annee}`;
    if (level === 'dept') {
        const key = cacheKey(cat, annee);
        if (deptCache[key]) { render(deptCache[key], 'dept'); return; }
        fetchLayer(`/api/cfe/departements?${params}`, fc => {
            deptCache[key] = fc;
            render(fc, 'dept');
        });
    } else {
        fetchLayer(`/api/cfe?bbox=${bboxParam(map)}&${params}`, fc => render(fc, 'commune'));
    }
}

export function initCfe(map) {
    const toggle  = document.getElementById('toggle-cfe');
    const options = document.getElementById('cfe-options');
    const catSel  = document.getElementById('cfe-categorie');
    const anneSel = document.getElementById('cfe-annee');

    function onOptionChange() {
        Object.keys(deptCache).forEach(k => delete deptCache[k]);
        Object.keys(breaksCache).forEach(k => delete breaksCache[k]);
        if (active) loadCfe(map);
    }

    toggle.addEventListener('change', () => {
        active = toggle.checked;
        options.classList.toggle('hidden', !active);
        if (!active) { if (abortCtrl) abortCtrl.abort(); remove(map); dropLegend('cfe'); clearInfo('cfe'); }
        else loadCfe(map);
    });

    if (catSel)  catSel.addEventListener('change',  onOptionChange);
    if (anneSel) anneSel.addEventListener('change', onOptionChange);

    map.on('mouseenter', 'cfe-fill', () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'cfe-fill', () => map.getCanvas().style.cursor = '');

    map.on('click', 'cfe-fill', e => {
        const p   = e.features[0].properties;
        const fmtv = (v, suf) => v != null ? (+v).toFixed(4) + suf : '–';
        const fmtp = v => v != null ? (+v).toFixed(3) + ' %' : '–';
        const { cat } = getOptions();

        if (p.nom_dep) {
            showInfo('cfe', `${p.nom_dep} (${p.code_dep})`,
                irow(`CFE estimée/m² (${cat})`, fmtv(p.cfe_estime, ' €/m²')) +
                irow('Tarif moyen section', fmtv(p.tarif_moyen, ' €/m²')) +
                `<div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez ≥ 9 pour le détail par commune</div>`
            );
        } else {
            showInfo('cfe', `${p.libcom} (${p.code_insee})`,
                irow(`CFE estimée/m² (${cat})`, fmtv(p.cfe_estime, ' €/m²')) +
                irow('Tarif section', fmtv(p.tarif_section, ' €/m²')) +
                irow('Taux CFE total', fmtp(p.taux_cfe_total)) +
                irow('Coeff. neutralisation', p.coeff_neut_com ?? '–')
            );
        }
    });

    return { load: () => loadCfe(map), isActive: () => active };
}
