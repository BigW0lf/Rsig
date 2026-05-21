import { showSpinner, hideSpinner, stepExpr, PAL, computeBreaks } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, irow } from '../panel.js';

let active = false;
let abortCtrl = null;
const cache        = {};
const globalBreaks = {};

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

function getBreaks(cat, annee, cb) {
    const key = `${cat}|${annee}`;
    if (globalBreaks[key]) { cb(globalBreaks[key]); return; }
    fetch(`/api/tarifs/stats?categorie=${cat}&annee=${annee}`)
        .then(r => r.json())
        .then(b => { globalBreaks[key] = b; cb(b); })
        .catch(() => cb(null));
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
        if (map.getSource('tarifs-src')) { map.removeLayer('tarifs-line'); map.removeSource('tarifs-src'); }
        map.addSource('tarifs-src', { type: 'geojson', data: fc });
        map.addLayer({ id: 'tarifs-fill', type: 'fill', source: 'tarifs-src', paint: { 'fill-color': color, 'fill-opacity': 0.7 } });
        map.addLayer({ id: 'tarifs-line', type: 'line', source: 'tarifs-src', paint: { 'line-color': '#444', 'line-width': 0.5 } });
        map.on('mouseenter', 'tarifs-fill', () => map.getCanvas().style.cursor = 'pointer');
        map.on('mouseleave', 'tarifs-fill', () => map.getCanvas().style.cursor = '');
    }
}

function remove(map) {
    ['tarifs-fill','tarifs-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
    if (map.getSource('tarifs-src')) map.removeSource('tarifs-src');
}

export function loadTarifs(map) {
    if (!active) return;
    const catEl   = document.getElementById('tarifs-cat');
    const anneeEl = document.getElementById('tarifs-annee');
    const cat     = catEl.value;
    const annee   = anneeEl.value;
    if (!cat) return;

    const zoom  = map.getZoom();
    const level = getLevel(zoom);
    const levelInfo = document.getElementById('tarifs-level-info');
    if (levelInfo) {
        const labels = { dept: 'Niveau : département (zoom < 9)', commune: 'Niveau : commune (zoom < 13)', section: 'Niveau : section (zoom ≥ 13)' };
        levelInfo.textContent = labels[level];
    }

    getBreaks(cat, annee, globalB => {
        const render = (fc, renderLevel) => {
            if (getLevel(map.getZoom()) !== renderLevel) return;
            if (!fc?.features?.length) {
                if (map.getSource('tarifs-src')) map.getSource('tarifs-src').setData({ type: 'FeatureCollection', features: [] });
                return;
            }
            const breaks = globalB ?? computeBreaks(fc.features.map(f => +f.properties.valeur).filter(v => isFinite(v)), 7);
            upsert(map, fc, stepExpr('valeur', breaks, PAL.tarifs));
            const niveauLabel = { dept: 'départements', commune: 'communes', section: 'sections' }[renderLevel];
            const title = renderLevel === 'section'
                ? `${cat} — ${annee} (${niveauLabel})`
                : `${cat} — ${annee} — moy. par ${niveauLabel.slice(0,-1)}`;
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
    });
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
        if (!active) { remove(map); dropLegend('tarifs'); }
        else loadTarifs(map);
    });
    catEl.addEventListener('change', () => loadTarifs(map));
    anneeEl.addEventListener('change', () => loadTarifs(map));

    map.on('click', 'tarifs-fill', e => {
        const p     = e.features[0].properties;
        const annee = anneeEl.value;
        if (p.section) {
            const rows = [2017,2019,2020,2021,2022,2023,2024,2025,2026]
                .filter(y => p[`val_${y}`] != null)
                .map(y => `<tr><td>${y}</td><td>${p['val_'+y]} €/m²</td></tr>`)
                .join('');
            showInfo(`Section ${p.section} — ${p.nom_com}`, `
                ${irow('Commune', p.nom_com)}
                ${irow('INSEE', p.code_insee)}
                ${irow('Secteur', p.secteur)}
                ${irow('Catégorie', p.categorie)}
                ${irow(`Tarif ${annee}`, p.valeur != null ? p.valeur+' €/m²' : '–')}
                <div class="info-row"><span class="info-label">Évolution</span></div>
                <table class="evol-table"><tr><th>Année</th><th>Tarif</th></tr>${rows}</table>
            `);
        } else if (p.code_insee) {
            showInfo(`${p.nom_com} (${p.code_insee})`, `
                ${irow('Département', p.code_dep)}
                ${irow('Tarif moyen '+annee, p.valeur != null ? p.valeur+' €/m²' : '–')}
                <div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez pour voir le détail par section</div>
            `);
        } else {
            showInfo(`${p.nom_dep ?? 'Département'} (${p.code_dep})`, `
                ${irow('Tarif moyen '+annee, p.valeur != null ? p.valeur+' €/m²' : '–')}
                <div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez pour voir le détail par commune ou section</div>
            `);
        }
    });

    return { load: () => loadTarifs(map), isActive: () => active };
}
