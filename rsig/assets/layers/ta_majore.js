import { showSpinner, hideSpinner, computeBreaks, bddOnTop } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

// Seuil zoom : en dessous clusters/points, au-dessus polygones hachurés
const ZOOM_POLY = 12;

// Palette rouge par palier de taux
const PALETTE   = ['#fde047', '#fb923c', '#f97316', '#dc2626', '#7f1d1d'];
const BREAKS    = [5, 7, 10, 15, 20];   // bornes fixes (taux %)

let active    = false;
let loaded    = false;
let abortCtrl = null;

// ── Hachures (repris du style coeff_loc) ─────────────────
function makeHatchImage(color) {
    const size = 10;
    const canvas = document.createElement('canvas');
    canvas.width = size; canvas.height = size;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, size, size);
    ctx.strokeStyle = color; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.moveTo(-1, size + 1); ctx.lineTo(size + 1, -1); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(-1, 1);         ctx.lineTo(1, -1);         ctx.stroke();
    ctx.beginPath(); ctx.moveTo(size - 1, size + 1); ctx.lineTo(size + 1, size - 1); ctx.stroke();
    const d = ctx.getImageData(0, 0, size, size);
    return { width: size, height: size, data: d.data };
}

function ensureHatch(map, color, key) {
    if (!map.hasImage(key)) map.addImage(key, makeHatchImage(color), { pixelRatio: 2 });
}

function colorForTaux(t) {
    const n = +t;
    if (n >= 20) return PALETTE[4];
    if (n >= 15) return PALETTE[3];
    if (n >= 10) return PALETTE[2];
    if (n >=  7) return PALETTE[1];
    return PALETTE[0];
}

// ── Gestion des sources/layers ────────────────────────────
const HATCH_PREFIX = 'ta-maj-hatch';
const N_HATCH = PALETTE.length;

function clearHatch(map) {
    for (let i = 0; i < N_HATCH; i++) {
        if (map.getLayer(`${HATCH_PREFIX}-${i}`)) map.removeLayer(`${HATCH_PREFIX}-${i}`);
        if (map.getSource(`${HATCH_PREFIX}-src-${i}`)) map.removeSource(`${HATCH_PREFIX}-src-${i}`);
    }
}

function remove(map) {
    clearHatch(map);
    ['ta-maj-fill', 'ta-maj-line',
     'ta-maj-cluster', 'ta-maj-cluster-count', 'ta-maj-point']
        .forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
    ['ta-maj-poly', 'ta-maj-pts']
        .forEach(id => { if (map.getSource(id)) map.removeSource(id); });
}

function buildPolygons(map, polys) {
    if (!polys?.features?.length) return;

    // Source polygones
    if (map.getSource('ta-maj-poly')) {
        map.getSource('ta-maj-poly').setData(polys);
        // Mettre à jour les hachures
        clearHatch(map);
    } else {
        map.addSource('ta-maj-poly', { type: 'geojson', data: polys });
        // Couche de base transparente (pour clic et hover)
        map.addLayer({ id: 'ta-maj-fill', type: 'fill', source: 'ta-maj-poly',
            minzoom: ZOOM_POLY,
            paint: { 'fill-color': ['step', ['get','taux'],
                PALETTE[0], 7, PALETTE[1], 10, PALETTE[2], 15, PALETTE[3], 20, PALETTE[4]],
                'fill-opacity': 0.15 }   // fond très léger, hachures apportent la couleur
        });
        map.addLayer({ id: 'ta-maj-line', type: 'line', source: 'ta-maj-poly',
            minzoom: ZOOM_POLY,
            paint: { 'line-color': '#7f1d1d', 'line-width': 1.2 }
        });
    }

    // Hachures par palier de taux
    PALETTE.forEach((col, i) => {
        const lo = BREAKS[i];
        const hi = BREAKS[i + 1] ?? Infinity;
        const feats = polys.features.filter(f => {
            const v = +f.properties.taux;
            return i === PALETTE.length - 1 ? v >= lo : (v >= lo && v < hi);
        });
        if (!feats.length) return;
        const imgKey = `ta-maj-img-${col.replace('#', '')}`;
        ensureHatch(map, col, imgKey);
        const srcId = `${HATCH_PREFIX}-src-${i}`;
        map.addSource(srcId, { type: 'geojson', data: { type: 'FeatureCollection', features: feats } });
        map.addLayer({ id: `${HATCH_PREFIX}-${i}`, type: 'fill', source: srcId,
            minzoom: ZOOM_POLY,
            paint: { 'fill-pattern': imgKey }
        });
    });

    bddOnTop(map);
}

function buildClusters(map, points) {
    if (!points?.features?.length) return;

    if (map.getSource('ta-maj-pts')) {
        map.getSource('ta-maj-pts').setData(points);
        return;
    }

    map.addSource('ta-maj-pts', {
        type: 'geojson', data: points,
        cluster: true, clusterMaxZoom: ZOOM_POLY - 1, clusterRadius: 40,
    });

    map.addLayer({ id: 'ta-maj-cluster', type: 'circle', source: 'ta-maj-pts',
        maxzoom: ZOOM_POLY,
        filter: ['has', 'point_count'],
        paint: {
            'circle-color': ['step', ['get','point_count'],
                '#f97316', 10, '#dc2626', 50, '#7f1d1d'],
            'circle-radius': ['step', ['get','point_count'], 16, 10, 22, 50, 28],
            'circle-opacity': 0.85,
        }
    });
    map.addLayer({ id: 'ta-maj-cluster-count', type: 'symbol', source: 'ta-maj-pts',
        maxzoom: ZOOM_POLY,
        filter: ['has', 'point_count'],
        layout: { 'text-field': '{point_count_abbreviated}', 'text-size': 12,
                  'text-font': ['Noto Sans Regular'] },
        paint: { 'text-color': '#fff', 'text-halo-color': '#7f1d1d', 'text-halo-width': 1 }
    });
    map.addLayer({ id: 'ta-maj-point', type: 'circle', source: 'ta-maj-pts',
        maxzoom: ZOOM_POLY,
        filter: ['!', ['has', 'point_count']],
        paint: {
            'circle-color': ['step', ['get','taux'],
                PALETTE[0], 7, PALETTE[1], 10, PALETTE[2], 15, PALETTE[3], 20, PALETTE[4]],
            'circle-radius': 7, 'circle-stroke-width': 1.5, 'circle-stroke-color': '#7f1d1d',
        }
    });

    // Clic cluster → zoom
    map.on('click', 'ta-maj-cluster', e => {
        const f = map.queryRenderedFeatures(e.point, { layers: ['ta-maj-cluster'] })[0];
        map.getSource('ta-maj-pts').getClusterExpansionZoom(f.properties.cluster_id, (err, zoom) => {
            if (!err) map.easeTo({ center: f.geometry.coordinates, zoom });
        });
    });
    map.on('mouseenter', 'ta-maj-cluster', () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'ta-maj-cluster', () => map.getCanvas().style.cursor = '');
}

// ── Chargement principal ──────────────────────────────────
function getMillesime() {
    return document.getElementById('ta-majore-millesime')?.value || '';
}

function bboxParam(map) {
    const b = map.getBounds();
    return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
}

function load(map) {
    if (!active) return;
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();
    showSpinner();

    const mil = getMillesime();
    const url = `/api/ta/majore${mil ? '?millesime=' + mil : ''}`;

    fetch(url, { signal: abortCtrl.signal })
        .then(r => r.json())
        .then(data => {
            hideSpinner();
            if (!active) return;
            const polys  = data.polygons;
            const points = data.points;
            if (!polys?.features?.length) return;

            buildClusters(map, points);
            buildPolygons(map, polys);
            loaded = true;

            const mil_label = mil ? ` (${mil})` : ' (dernier en vigueur)';
            saveLegend('ta-majore',
                `TA majorée >5%${mil_label}`,
                ['5–7 %', '7–10 %', '10–15 %', '15–20 %', '≥ 20 %'],
                PALETTE, '');
        })
        .catch(e => { hideSpinner(); if (e.name !== 'AbortError') console.error('ta-majore', e); });
}

// ── Info panneau ─────────────────────────────────────────
function showMajoreInfo(p) {
    const typeZone = p.parcelle ? 'Parcelle' : 'Section cadastrale';
    const ref = p.parcelle
        ? `${p.prefixe || ''}${p.section} n°${p.parcelle}`
        : `${p.prefixe || ''}${p.section}`;
    showInfo('ta-majore', `TA majorée — ${p.libcom || p.code_insee}`,
        irow('Type de zone', typeZone) +
        irow('Référence', ref) +
        irow('Taux communal', (+p.taux).toFixed(2) + ' %') +
        irow('Millésime', p.millesime || '–') +
        irow('Date délibération', p.date_effet || '–') +
        irow('Code INSEE', p.code_insee) +
        `<div class="info-row" style="margin-top:4px;font-size:0.75rem;color:var(--text3)">
            Taux >5% voté par délibération municipale
        </div>`
    );
}

// ── Init ─────────────────────────────────────────────────
export function initTaMajore(map) {
    const toggle = document.getElementById('toggle-ta-majore');
    const milSel = document.getElementById('ta-majore-millesime');

    // Sélecteur millésime
    fetch('/api/ta/majore/millesimes').then(r => r.json()).then(mils => {
        if (!milSel) return;
        const opt0 = document.createElement('option');
        opt0.value = ''; opt0.textContent = 'Dernier en vigueur';
        milSel.appendChild(opt0);
        mils.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m; opt.textContent = m;
            milSel.appendChild(opt);
        });
    }).catch(() => {});

    milSel?.addEventListener('change', () => {
        if (active) { remove(map); loaded = false; load(map); }
    });

    // Clics
    ['ta-maj-fill', 'ta-maj-poly'].forEach(id => {
        map.on('click', id, e => { if (e.features?.[0]) showMajoreInfo(e.features[0].properties); });
    });
    for (let i = 0; i < N_HATCH; i++) {
        map.on('click', `${HATCH_PREFIX}-${i}`, e => { if (e.features?.[0]) showMajoreInfo(e.features[0].properties); });
    }
    map.on('click', 'ta-maj-point', e => { if (e.features?.[0]) showMajoreInfo(e.features[0].properties); });

    map.on('mouseenter', 'ta-maj-fill',  () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'ta-maj-fill',  () => map.getCanvas().style.cursor = '');
    map.on('mouseenter', 'ta-maj-point', () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'ta-maj-point', () => map.getCanvas().style.cursor = '');

    toggle?.addEventListener('change', () => {
        active = toggle.checked;
        document.getElementById('ta-majore-options')?.classList.toggle('hidden', !active);
        if (!active) { remove(map); loaded = false; dropLegend('ta-majore'); clearInfo('ta-majore'); }
        else if (!loaded) load(map);
    });

    return { isActive: () => active };
}
