import { showSpinner, hideSpinner, stepExpr, PAL, computeBreaks } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, irow } from '../panel.js';

let active = false;
let abortCtrl    = null;
let polyCache    = null;
let clusterCache = null;
let clusterChamp = null;
const globalBreaks = {};

const MIN_ZOOM = 13;

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
        .catch(e => { hideSpinner(); if (e.name !== 'AbortError') console.error('coeff', e); });
}

function getBreaks(champ, cb) {
    const key = champ === 'evolution' ? '_evol_global' : champ;
    if (globalBreaks[key]) { cb(globalBreaks[key]); return; }
    const apiChamp = champ === 'evolution' ? 'coeff_2026' : champ;
    fetch(`/api/coeff/stats?champ=${apiChamp}`)
        .then(r => r.json())
        .then(b => { globalBreaks[key] = b; cb(b); })
        .catch(() => cb(null));
}

function getVal(p, champ) {
    if (champ === 'evolution')
        return (p.coeff_2026 != null && p.coeff_2017 != null && +p.coeff_2017 !== 0)
            ? ((+p.coeff_2026 - +p.coeff_2017) / +p.coeff_2017 * 100) : null;
    return p[champ] != null ? +p[champ] : null;
}

function upsertPoly(map, fc, color) {
    if (map.getLayer('coeff-fill')) {
        map.getSource('coeff-src').setData(fc);
        map.setPaintProperty('coeff-fill', 'fill-color', color);
        map.setLayoutProperty('coeff-fill', 'visibility', 'visible');
        map.setLayoutProperty('coeff-line', 'visibility', 'visible');
    } else {
        if (map.getSource('coeff-src')) { map.removeLayer('coeff-line'); map.removeSource('coeff-src'); }
        map.addSource('coeff-src', { type: 'geojson', data: fc });
        map.addLayer({ id: 'coeff-fill', type: 'fill', source: 'coeff-src', paint: { 'fill-color': color, 'fill-opacity': 0.75 } });
        map.addLayer({ id: 'coeff-line', type: 'line', source: 'coeff-src', paint: { 'line-color': '#666', 'line-width': 0.5 } });
        map.on('mouseenter', 'coeff-fill', () => map.getCanvas().style.cursor = 'pointer');
        map.on('mouseleave', 'coeff-fill', () => map.getCanvas().style.cursor = '');
    }
}

function removePoly(map) {
    ['coeff-fill','coeff-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
    if (map.getSource('coeff-src')) map.removeSource('coeff-src');
}

function removeClusters(map) {
    ['coeff-cluster-count','coeff-cluster-cluster','coeff-cluster-circle'].forEach(id => {
        if (map.getLayer(id)) map.removeLayer(id);
    });
    if (map.getSource('coeff-cluster-src')) map.removeSource('coeff-cluster-src');
}

function showClusters(map, champ, globalB) {
    const isEvol   = champ === 'evolution';
    const pal      = isEvol ? PAL.coeffEv : PAL.coeff;
    const apiChamp = isEvol ? 'coeff_2026' : champ;

    const apply = fc => {
        if (!fc?.features?.length) return;
        fc.features.forEach(f => { f.properties._cv = isEvol ? null : +f.properties.valeur; });
        const breaks = globalB ?? computeBreaks(fc.features.map(f => f.properties._cv).filter(v => v != null && isFinite(v)), 5);
        const color  = stepExpr('_cv', breaks, pal);
        const src = 'coeff-cluster-src';

        if (map.getSource(src)) {
            map.getSource(src).setData(fc);
            if (map.getLayer('coeff-cluster-circle')) map.setPaintProperty('coeff-cluster-circle', 'circle-color', color);
            ['coeff-cluster-circle','coeff-cluster-cluster','coeff-cluster-count'].forEach(id => {
                if (map.getLayer(id)) map.setLayoutProperty(id, 'visibility', 'visible');
            });
        } else {
            map.addSource(src, { type: 'geojson', data: fc, cluster: true, clusterRadius: 35, clusterMaxZoom: MIN_ZOOM - 1 });
            map.addLayer({ id: 'coeff-cluster-circle', type: 'circle', source: src,
                filter: ['!', ['has', 'point_count']],
                paint: { 'circle-color': color, 'circle-radius': 6, 'circle-stroke-width': 1.5, 'circle-stroke-color': '#fff' } });
            map.addLayer({ id: 'coeff-cluster-cluster', type: 'circle', source: src,
                filter: ['has', 'point_count'],
                paint: {
                    'circle-color': ['step', ['get', 'point_count'], pal[1], 5, pal[2], 20, pal[3], 50, pal[4]],
                    'circle-radius': ['step', ['get', 'point_count'], 10, 5, 14, 20, 18, 50, 24],
                    'circle-stroke-width': 2, 'circle-stroke-color': 'rgba(255,255,255,.7)',
                } });
            map.addLayer({ id: 'coeff-cluster-count', type: 'symbol', source: src,
                filter: ['has', 'point_count'],
                layout: { 'text-field': '{point_count_abbreviated}', 'text-font': ['Noto Sans Regular'], 'text-size': 11 },
                paint: { 'text-color': '#fff' } });

            map.on('mouseenter', 'coeff-cluster-circle',  () => map.getCanvas().style.cursor = 'pointer');
            map.on('mouseleave', 'coeff-cluster-circle',  () => map.getCanvas().style.cursor = '');
            map.on('mouseenter', 'coeff-cluster-cluster', () => map.getCanvas().style.cursor = 'pointer');
            map.on('mouseleave', 'coeff-cluster-cluster', () => map.getCanvas().style.cursor = '');
            map.on('click', 'coeff-cluster-cluster', e => {
                const feat = map.queryRenderedFeatures(e.point, { layers: ['coeff-cluster-cluster'] });
                map.getSource(src).getClusterExpansionZoom(feat[0].properties.cluster_id, (err, zoom) => {
                    if (!err) map.easeTo({ center: feat[0].geometry.coordinates, zoom });
                });
            });
            map.on('click', 'coeff-cluster-circle', e => {
                const p = e.features[0].properties;
                showInfo(`Commune ${p.codecommune}`, `
                    ${irow('Code commune', p.codecommune)}
                    ${irow('Coeff moyen', p.valeur)}
                    ${irow('Nb parcelles', p.nb_parcelles)}
                    <div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez ≥ 12 pour voir le détail par parcelle</div>
                `);
            });
        }

        const suffix = isEvol ? ' %' : '';
        saveLegend('coeff', document.getElementById('coeff-champ').options[document.getElementById('coeff-champ').selectedIndex].text + ' (communes)', breaks, pal, suffix);
    };

    if (clusterCache && clusterChamp === apiChamp) { apply(clusterCache); return; }
    fetchLayer(`/api/coeff/clusters?champ=${apiChamp}`, fc => { clusterCache = fc; clusterChamp = apiChamp; apply(fc); });
}

export function loadCoeff(map) {
    if (!active) return;
    const champEl = document.getElementById('coeff-champ');
    const champ   = champEl.value;
    const zoom    = map.getZoom();

    if (zoom < MIN_ZOOM) {
        if (map.getLayer('coeff-fill')) {
            map.setLayoutProperty('coeff-fill', 'visibility', 'none');
            map.setLayoutProperty('coeff-line', 'visibility', 'none');
        }
        getBreaks(champ, globalB => showClusters(map, champ, globalB));
        return;
    }

    ['coeff-cluster-circle','coeff-cluster-cluster','coeff-cluster-count'].forEach(id => {
        if (map.getLayer(id)) map.setLayoutProperty(id, 'visibility', 'none');
    });

    const isEvol = champ === 'evolution';
    const pal    = isEvol ? PAL.coeffEv : PAL.coeff;
    getBreaks(champ, globalB => {
        fetchLayer(`/api/coeff?bbox=${bboxParam(map)}`, fc => {
            polyCache = fc;
            if (!fc?.features?.length) return;
            const propKey = isEvol ? '_evol' : champ;
            fc.features.forEach(f => { f.properties[propKey] = getVal(f.properties, champ); });
            const breaks = globalB ?? computeBreaks(fc.features.map(f => f.properties[propKey]).filter(v => v != null && isFinite(v)), 5);
            upsertPoly(map, fc, stepExpr(propKey, breaks, pal));
            saveLegend('coeff', champEl.options[champEl.selectedIndex].text, breaks, pal, isEvol ? ' %' : '');
        });
    });
}

export function initCoeff(map) {
    const toggle  = document.getElementById('toggle-coeff');
    const options = document.getElementById('coeff-options');
    const champEl = document.getElementById('coeff-champ');

    toggle.addEventListener('change', () => {
        active = toggle.checked;
        options.classList.toggle('hidden', !active);
        if (!active) { removePoly(map); removeClusters(map); dropLegend('coeff'); polyCache = null; }
        else loadCoeff(map);
    });
    champEl.addEventListener('change', () => { polyCache = null; clusterCache = null; loadCoeff(map); });

    map.on('click', 'coeff-fill', e => {
        const p    = e.features[0].properties;
        const evol = (p.coeff_2026 != null && p.coeff_2017 != null && +p.coeff_2017 !== 0)
            ? ((+p.coeff_2026 - +p.coeff_2017) / +p.coeff_2017 * 100).toFixed(1) : null;
        const cls  = evol > 0 ? 'tag-up' : evol < 0 ? 'tag-down' : '';
        showInfo(`Parcelle ${p.idu}`, `
            ${irow('IDU', p.idu)}
            ${irow('Commune', p.codecommune)}
            ${irow('Section', p.section)}
            ${irow('Parcelle', p.parcelle)}
            <div class="info-row">
                <span class="info-label">Évolution 2017→2026</span>
                <span class="info-value ${cls}">${evol !== null ? evol+' %' : '–'}</span>
            </div>
            <table class="evol-table">
                <tr><th>Année</th><th>Coeff</th></tr>
                ${[2017,2018,2019,2020,2024,2026].map(y =>
                    `<tr><td>${y}</td><td>${p['coeff_'+y] ?? '–'}</td></tr>`
                ).join('')}
            </table>
        `);
    });

    return { load: () => loadCoeff(map), isActive: () => active };
}
