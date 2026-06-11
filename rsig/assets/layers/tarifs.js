import { showSpinner, hideSpinner, stepExpr, PAL, computeBreaks, bddOnTop } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

let active    = false;
let abortCtrl = null;
let loadId    = 0;
const cache = {};

const DEPT_ZOOM    = 9;
const COMMUNE_ZOOM = 13;

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
        .catch(e => { hideSpinner(); if (e.name !== 'AbortError') console.error('tarifs', e); });
}

function getLevel(zoom) {
    if (zoom < DEPT_ZOOM)    return 'dept';
    if (zoom < COMMUNE_ZOOM) return 'commune';
    return 'section';
}

function upsert(map, fc, color) {
    if (map.getLayer('tarifs-fill')) {
        map.getSource('tarifs-src').setData(fc);
        map.setPaintProperty('tarifs-fill', 'fill-color', color);
    } else {
        ['tarifs-fill','tarifs-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
        if (map.getSource('tarifs-src')) map.removeSource('tarifs-src');
        map.addSource('tarifs-src', { type: 'geojson', data: fc });
        map.addLayer({ id: 'tarifs-fill', type: 'fill', source: 'tarifs-src', paint: { 'fill-color': color, 'fill-opacity': 0.5 } });
        map.addLayer({ id: 'tarifs-line', type: 'line', source: 'tarifs-src', paint: { 'line-color': '#444', 'line-width': 0.5 } });
        map.on('mouseenter', 'tarifs-fill', () => map.getCanvas().style.cursor = 'pointer');
        map.on('mouseleave', 'tarifs-fill', () => map.getCanvas().style.cursor = '');
    }
    bddOnTop(map);
}

function remove(map) {
    ['tarifs-fill','tarifs-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
    if (map.getSource('tarifs-src')) map.removeSource('tarifs-src');
}

export function loadTarifs(map) {
    if (!active) return;
    const cat   = document.getElementById('tarifs-cat').value;
    const annee = document.getElementById('tarifs-annee').value;
    if (!cat) return;

    const level  = getLevel(map.getZoom());
    const myId   = ++loadId;

    const render = (fc, renderLevel) => {
        if (myId !== loadId || !active) return;
        if (getLevel(map.getZoom()) !== renderLevel) return;
        if (!fc?.features?.length) {
            if (map.getSource('tarifs-src')) map.getSource('tarifs-src').setData({ type: 'FeatureCollection', features: [] });
            return;
        }
        const breaks      = computeBreaks(fc.features.map(f => +f.properties.valeur).filter(v => isFinite(v) && v > 0), 7);
        const niveauLabel = { dept: 'départements', commune: 'communes', section: 'sections' }[renderLevel];
        const title       = renderLevel === 'section'
            ? `${cat} — ${annee} (${niveauLabel})`
            : `${cat} — ${annee} — moy. par ${niveauLabel.slice(0,-1)}`;
        upsert(map, fc, stepExpr('valeur', breaks, PAL.tarifs));
        saveLegend('tarifs', title, breaks, PAL.tarifs, ' €/m²');
    };

    if (level === 'dept') {
        const key = `${cat}|${annee}|dept`;
        if (cache[key]) { render(cache[key], 'dept'); return; }
        fetchLayer(`/api/tarifs/departements?categorie=${cat}&annee=${annee}`, fc => { cache[key] = fc; render(fc, 'dept'); });
        return;
    }

    const url = level === 'commune'
        ? `/api/tarifs/communes?bbox=${bboxParam(map)}&categorie=${cat}&annee=${annee}`
        : `/api/tarifs?bbox=${bboxParam(map)}&categorie=${cat}&annee=${annee}`;
    fetchLayer(url, fc => render(fc, level));
}

export function initTarifs(map, catsReady) {
    const toggle  = document.getElementById('toggle-tarifs');
    const options = document.getElementById('tarifs-options');
    const catEl   = document.getElementById('tarifs-cat');
    const anneeEl = document.getElementById('tarifs-annee');

    catsReady.then(cats => {
        if (!cats?.length) return;
        catEl.innerHTML = cats.map(c => `<option value="${c}">${c}</option>`).join('');
        if (active) loadTarifs(map);
    });

    toggle.addEventListener('change', () => {
        active = toggle.checked;
        options.classList.toggle('hidden', !active);
        if (!active) { if (abortCtrl) abortCtrl.abort(); remove(map); dropLegend('tarifs'); clearInfo('tarifs'); }
        else loadTarifs(map);
    });
    catEl.addEventListener('change',   () => { clearInfo('tarifs'); loadTarifs(map); });
    anneeEl.addEventListener('change', () => { clearInfo('tarifs'); loadTarifs(map); });

    map.on('click', 'tarifs-fill', e => {
        if (!active) return;
        const p     = e.features[0].properties;
        const annee = anneeEl.value;
        if (p.section) {
            const esc  = v => String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            const rows = [2017,2019,2020,2021,2022,2023,2024,2025,2026]
                .filter(y => p[`val_${y}`] != null)
                .map(y => `<tr><td>${y}</td><td>${esc(p['val_'+y])} €/m²</td></tr>`)
                .join('');
            showInfo('tarifs', `${p.nom_com} (${p.code_dep}) — Sect. ${p.section} / Secteur ${p.secteur}`,
                irow('Catégorie', p.categorie) +
                irow(`Tarif ${annee}`, p.valeur != null ? p.valeur+' €/m²' : null) +
                (rows ? `<div class="info-row"><span class="info-label">Évolution</span></div>
                <table class="evol-table"><tr><th>Année</th><th>Tarif</th></tr>${rows}</table>` : '')
            );
        } else if (p.code_insee) {
            showInfo('tarifs', `${p.nom_com} (${p.code_dep})`,
                irow('Tarif moyen '+annee, p.valeur != null ? p.valeur+' €/m²' : null) +
                `<div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez pour voir le détail par section</div>`
            );
        } else {
            showInfo('tarifs', `${p.nom_dep ?? 'Département'} (${p.code_dep})`,
                irow('Tarif moyen '+annee, p.valeur != null ? p.valeur+' €/m²' : null) +
                `<div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez pour voir le détail par commune ou section</div>`
            );
        }
    });

    return { load: () => loadTarifs(map), isActive: () => active };
}
