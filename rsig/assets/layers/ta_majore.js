import { showSpinner, hideSpinner, bddOnTop, apiFetch } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

const ZOOM_POLY  = 12;   // en dessous → clusters, au-dessus → polygones hachurés

const PALETTE = ['#fde047', '#fb923c', '#f97316', '#dc2626', '#7f1d1d'];
const BREAKS  = [5, 7, 10, 15, 20];

let active    = false;
let abortCtrl = null;

// ── Hachures ─────────────────────────────────────────────────
function makeHatchImage(color) {
    const sz = 10;
    const c  = document.createElement('canvas');
    c.width = sz; c.height = sz;
    const ctx = c.getContext('2d');
    ctx.clearRect(0, 0, sz, sz);
    ctx.strokeStyle = color; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.moveTo(-1, sz+1); ctx.lineTo(sz+1, -1); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(-1, 1);    ctx.lineTo(1, -1);    ctx.stroke();
    ctx.beginPath(); ctx.moveTo(sz-1, sz+1); ctx.lineTo(sz+1, sz-1); ctx.stroke();
    const d = ctx.getImageData(0, 0, sz, sz);
    return { width: sz, height: sz, data: d.data };
}

function ensureHatch(map, color, key) {
    if (!map.hasImage(key)) map.addImage(key, makeHatchImage(color), { pixelRatio: 2 });
}

// ── Calcul des centroïdes côté JS pour la source cluster ─────
function centroidsFromFeatures(features) {
    return features.map(f => {
        const g = f.geometry;
        let pt;
        if (g.type === 'Point') {
            pt = g.coordinates;
        } else {
            // centroïde naïf : moyenne des coordonnées du premier anneau
            const coords = g.type === 'Polygon' ? g.coordinates[0]
                         : g.type === 'MultiPolygon' ? g.coordinates[0][0]
                         : [[0,0]];
            const n = coords.length;
            pt = [coords.reduce((s,c)=>s+c[0],0)/n, coords.reduce((s,c)=>s+c[1],0)/n];
        }
        return { type:'Feature', geometry:{type:'Point',coordinates:pt}, properties: f.properties };
    });
}

// ── Build / update layers ─────────────────────────────────────
function buildLayers(map, fc) {
    // ─ Source polygones ─
    if (map.getSource('ta-maj-poly')) {
        map.getSource('ta-maj-poly').setData(fc);
    } else {
        map.addSource('ta-maj-poly', { type: 'geojson', data: fc });

        // Fond semi-transparent
        map.addLayer({ id: 'ta-maj-fill', type: 'fill', source: 'ta-maj-poly',
            minzoom: ZOOM_POLY,
            paint: { 'fill-color': ['step', ['get','taux'],
                PALETTE[0], 7, PALETTE[1], 10, PALETTE[2], 15, PALETTE[3], 20, PALETTE[4]],
                'fill-opacity': 0.15 },
        });
        map.addLayer({ id: 'ta-maj-line', type: 'line', source: 'ta-maj-poly',
            minzoom: ZOOM_POLY,
            paint: { 'line-color': '#7f1d1d', 'line-width': 1.2 },
        });

        // Hachures par palier (filter MapLibre au lieu de sources multiples)
        PALETTE.forEach((col, i) => {
            const lo = BREAKS[i];
            const hi = BREAKS[i+1] ?? Infinity;
            const imgKey = `ta-maj-img-${col.replace('#','')}`;
            ensureHatch(map, col, imgKey);
            const filter = i === PALETTE.length - 1
                ? ['>=', ['get','taux'], lo]
                : ['all', ['>=', ['get','taux'], lo], ['<', ['get','taux'], hi]];
            map.addLayer({ id: `ta-maj-hatch-${i}`, type: 'fill', source: 'ta-maj-poly',
                minzoom: ZOOM_POLY,
                filter,
                paint: { 'fill-pattern': imgKey },
            });
        });
    }

    // ─ Source clusters (centroïdes recalculés) ─
    const pts = { type:'FeatureCollection', features: centroidsFromFeatures(fc.features) };
    if (map.getSource('ta-maj-pts')) {
        map.getSource('ta-maj-pts').setData(pts);
    } else {
        map.addSource('ta-maj-pts', {
            type: 'geojson', data: pts,
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
            },
        });
        map.addLayer({ id: 'ta-maj-cluster-count', type: 'symbol', source: 'ta-maj-pts',
            maxzoom: ZOOM_POLY,
            filter: ['has', 'point_count'],
            layout: { 'text-field': '{point_count_abbreviated}', 'text-size': 12,
                      'text-font': ['Noto Sans Regular'] },
            paint: { 'text-color': '#fff', 'text-halo-color': '#7f1d1d', 'text-halo-width': 1 },
        });
        map.addLayer({ id: 'ta-maj-point', type: 'circle', source: 'ta-maj-pts',
            maxzoom: ZOOM_POLY,
            filter: ['!', ['has', 'point_count']],
            paint: {
                'circle-color': ['step', ['get','taux'],
                    PALETTE[0], 7, PALETTE[1], 10, PALETTE[2], 15, PALETTE[3], 20, PALETTE[4]],
                'circle-radius': 7,
                'circle-stroke-width': 1.5, 'circle-stroke-color': '#7f1d1d',
            },
        });

        map.on('click', 'ta-maj-cluster', e => {
            const f = map.queryRenderedFeatures(e.point, { layers: ['ta-maj-cluster'] })[0];
            map.getSource('ta-maj-pts').getClusterExpansionZoom(f.properties.cluster_id, (err, zoom) => {
                if (!err) map.easeTo({ center: f.geometry.coordinates, zoom });
            });
        });
        map.on('mouseenter', 'ta-maj-cluster', () => map.getCanvas().style.cursor = 'pointer');
        map.on('mouseleave', 'ta-maj-cluster', () => map.getCanvas().style.cursor = '');
    }

    bddOnTop(map);
}

function removeLayers(map) {
    for (let i = 0; i < PALETTE.length; i++) {
        if (map.getLayer(`ta-maj-hatch-${i}`)) map.removeLayer(`ta-maj-hatch-${i}`);
    }
    ['ta-maj-fill','ta-maj-line',
     'ta-maj-cluster','ta-maj-cluster-count','ta-maj-point']
        .forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
    ['ta-maj-poly','ta-maj-pts']
        .forEach(id => { if (map.getSource(id)) map.removeSource(id); });
}

// ── Chargement ────────────────────────────────────────────────
function bboxParam(map) {
    const b = map.getBounds();
    return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
}

function getMil() {
    return document.getElementById('ta-majore-millesime')?.value || '';
}

function load(map) {
    if (!active) return;
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();
    showSpinner();

    const mil = getMil();
    const url = `/api/ta/majore?bbox=${bboxParam(map)}${mil ? '&millesime='+mil : ''}`;

    apiFetch(url, { signal: abortCtrl.signal })
        .then(r => r.json())
        .then(fc => {
            hideSpinner();
            if (!active) return;
            const empty = { type:'FeatureCollection', features:[] };

            if (!fc?.features?.length) {
                if (map.getSource('ta-maj-poly')) map.getSource('ta-maj-poly').setData(empty);
                if (map.getSource('ta-maj-pts'))  map.getSource('ta-maj-pts').setData(empty);
                return;
            }

            if (fc.mode === 'points') {
                // Grande bbox : on reçoit des centroïdes commune → clusters uniquement, pas de polys
                buildLayers(map, empty);          // crée les sources/layers si besoin
                map.getSource('ta-maj-poly').setData(empty);
                map.getSource('ta-maj-pts').setData(fc);
            } else {
                // Petite bbox : polygones complets + centroïdes calculés JS
                buildLayers(map, fc);
            }

            const mil_label = mil ? ` (${mil})` : ' (dernier en vigueur)';
            saveLegend('ta-majore',
                `TA majorée >5%${mil_label}`,
                ['5–7 %','7–10 %','10–15 %','15–20 %','≥ 20 %'],
                PALETTE, '');
        })
        .catch(e => { hideSpinner(); });
}

// ── Info panneau ─────────────────────────────────────────────
function showMajoreInfo(p) {
    const typeLabel = p.type_zone === 'parcelle' ? 'Parcelle' : 'Section cadastrale';
    const ref = p.type_zone === 'parcelle'
        ? `Sect. ${p.section} · Parc. ${p.parcelle}`
        : `Sect. ${p.section}`;
    showInfo('ta-majore', `TA majorée — ${p.libcom || p.code_insee} · ${ref} — ${p.millesime || ''}`,
        irow('Type de zone', typeLabel) +
        irow('Taux', (+p.taux).toFixed(2) + ' %') +
        irow('Date délibération', p.date_effet || null)
    );
}

// ── Init ─────────────────────────────────────────────────────
export function initTaMajore(map) {
    const toggle = document.getElementById('toggle-ta-majore');
    const milSel = document.getElementById('ta-majore-millesime');

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
    }).catch(e => console.warn('[ta-majore] millesimes', e));

    milSel?.addEventListener('change', () => { if (active) load(map); });

    // Clics polygones
    ['ta-maj-fill', ...Array.from({length:PALETTE.length},(_,i)=>`ta-maj-hatch-${i}`)].forEach(id => {
        map.on('click', id, e => { if (!active || !e.features?.[0]) return; showMajoreInfo(e.features[0].properties); });
        map.on('mouseenter', id, () => map.getCanvas().style.cursor = 'pointer');
        map.on('mouseleave', id, () => map.getCanvas().style.cursor = '');
    });
    map.on('click', 'ta-maj-point', e => { if (!active || !e.features?.[0]) return; showMajoreInfo(e.features[0].properties); });
    map.on('mouseenter', 'ta-maj-point', () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'ta-maj-point', () => map.getCanvas().style.cursor = '');

    toggle?.addEventListener('change', () => {
        active = toggle.checked;
        document.getElementById('ta-majore-options')?.classList.toggle('hidden', !active);
        if (!active) { if (abortCtrl) abortCtrl.abort(); removeLayers(map); dropLegend('ta-majore'); clearInfo('ta-majore'); }
        else load(map);
    });

    return { load: () => load(map), isActive: () => active };
}
