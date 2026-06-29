import { showSpinner, hideSpinner, stepExpr, PAL, computeBreaks, bddOnTop, apiFetch } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

let active = false;
let abortCtrl    = null;
let polyCache    = null;
let clusterCache = null;
let clusterChamp = null;
const globalBreaks = {};

const MIN_ZOOM = 13;
let _seuil = 1.0;

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

function equalIntervalBreaks(min, max, n) {
    const step = (max - min) / n;
    return Array.from({ length: n + 1 }, (_, i) => +(min + i * step).toFixed(4));
}

function getBreaks(champ, cb) {
    const key = champ === 'evolution' ? '_evol_global' : champ;
    if (globalBreaks[key]) { cb(globalBreaks[key]); return; }
    const apiChamp = champ === 'evolution' ? 'coeff_2026' : champ;
    fetch(`/api/coeff/stats?champ=${apiChamp}`)
        .then(r => r.json())
        .then(([vmin, vmax]) => {
            const breaks = equalIntervalBreaks(vmin, vmax, 6);
            globalBreaks[key] = breaks;
            cb(breaks);
        })
        .catch(e => { console.warn('[coeff] stats', e); cb(null); });
}

function getVal(p, champ) {
    if (champ === 'evolution')
        return (p.coeff_2026 != null && p.coeff_2017 != null && +p.coeff_2017 !== 0)
            ? ((+p.coeff_2026 - +p.coeff_2017) / +p.coeff_2017 * 100) : null;
    return p[champ] != null ? +p[champ] : null;
}

function applySeuilFilter(fc, champ) {
    if (!fc?.features) return fc;
    const isEvol = champ === 'evolution';
    if (isEvol) return fc; // seuil non applicable sur l'évolution %
    return {
        ...fc,
        features: fc.features.filter(f => {
            const v = f.properties[champ];
            return v != null && +v >= _seuil;
        })
    };
}

// Génère un pattern hachuré en biais pour une couleur donnée (traits colorés, fond transparent)
function makeHatchImage(color, key) {
    const size = 10;
    const canvas = document.createElement('canvas');
    canvas.width = size; canvas.height = size;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, size, size);
    ctx.strokeStyle = color;
    ctx.lineWidth = 2;
    // Diagonale /
    ctx.beginPath(); ctx.moveTo(-1, size + 1); ctx.lineTo(size + 1, -1); ctx.stroke();
    // Répétition pour couvrir les bords
    ctx.beginPath(); ctx.moveTo(-1, 1); ctx.lineTo(1, -1); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(size - 1, size + 1); ctx.lineTo(size + 1, size - 1); ctx.stroke();
    const imgData = ctx.getImageData(0, 0, size, size);
    return { width: size, height: size, data: imgData.data };
}

// Cache des patterns créés par couleur hex
const _hatchCache = {};
function ensureHatchForColor(map, colorHex, key) {
    if (map.hasImage(key)) return;
    map.addImage(key, makeHatchImage(colorHex, key), { pixelRatio: 2 });
    _hatchCache[key] = true;
}

// Met à jour (ou crée) les sources hachurées par classe de couleur — setData si déjà existantes
function buildHatchSources(map, fc, breaks, palette, propKey, beforeLayer) {
    if (!breaks?.length) return;
    palette.forEach((col, i) => {
        const imgKey = `coeff-hatch-img-${col.replace('#','')}`;
        ensureHatchForColor(map, col, imgKey);
        const lo    = breaks[i] ?? -Infinity;
        const hi    = breaks[i + 1] ?? Infinity;
        const feats = fc.features.filter(f => {
            const v = f.properties[propKey];
            return v != null && +v >= lo && (i === palette.length - 1 ? true : +v < hi);
        });
        const data  = { type: 'FeatureCollection', features: feats };
        const srcId = `coeff-hatch-src-${i}`;
        if (map.getSource(srcId)) {
            // Source déjà créée : mise à jour sans remove/add
            map.getSource(srcId).setData(data);
            if (map.getLayer(`coeff-hatch-${i}`)) {
                map.setLayoutProperty(`coeff-hatch-${i}`, 'visibility', feats.length ? 'visible' : 'none');
            }
        } else {
            if (!feats.length) return;
            map.addSource(srcId, { type: 'geojson', data });
            map.addLayer({ id: `coeff-hatch-${i}`, type: 'fill', source: srcId,
                paint: { 'fill-pattern': imgKey } }, beforeLayer);
        }
    });
}

function upsertPoly(map, fc, color, breaks, palette, propKey) {
    // Coeff au-dessus de taux/tarifs, sous les dossiers
    const beforeLayer = map.getLayer('dossiers-circle') ? 'dossiers-circle' : undefined;

    if (map.getLayer('coeff-fill')) {
        map.getSource('coeff-src').setData(fc);
        map.setLayoutProperty('coeff-fill', 'visibility', 'visible');
        map.setLayoutProperty('coeff-line', 'visibility', 'visible');
        buildHatchSources(map, fc, breaks, palette, propKey, beforeLayer);
    } else {
        if (map.getSource('coeff-src')) {
            ['coeff-line','coeff-fill'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
            map.removeSource('coeff-src');
        }
        map.addSource('coeff-src', { type: 'geojson', data: fc });
        // Fond transparent — on voit le fond de carte
        map.addLayer({ id: 'coeff-fill', type: 'fill', source: 'coeff-src',
            paint: { 'fill-color': 'rgba(0,0,0,0)' } }, beforeLayer);
        map.addLayer({ id: 'coeff-line', type: 'line', source: 'coeff-src',
            paint: { 'line-color': color, 'line-width': 0.8 } }, beforeLayer);
        map.on('mouseenter', 'coeff-fill', () => map.getCanvas().style.cursor = 'pointer');
        map.on('mouseleave', 'coeff-fill', () => map.getCanvas().style.cursor = '');
        buildHatchSources(map, fc, breaks, palette, propKey, beforeLayer);
    }
    bddOnTop(map);
}

function removePoly(map) {
    for (let i = 0; i < 12; i++) {
        if (map.getLayer(`coeff-hatch-${i}`)) map.removeLayer(`coeff-hatch-${i}`);
        if (map.getSource(`coeff-hatch-src-${i}`)) map.removeSource(`coeff-hatch-src-${i}`);
    }
    ['coeff-line','coeff-fill'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
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
        if (!active) return;
        if (!fc?.features?.length) return;
        fc.features.forEach(f => { f.properties._cv = isEvol ? null : +f.properties.valeur; });
        const breaks = globalB ?? computeBreaks(fc.features.map(f => f.properties._cv).filter(v => v != null && isFinite(v)), 6);
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
                    'circle-color': ['step', ['get', 'point_count'], pal[2], 5, pal[3], 20, pal[4], 50, pal[5]],
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
                if (!active) return;
                const p = e.features[0].properties;
                showInfo('coeff', `Commune ${p.codecommune}`,
                    irow('Coeff moyen', p.valeur) +
                    irow('Nb parcelles', p.nb_parcelles) +
                    `<div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez ≥ 13 pour voir le détail par parcelle</div>`
                );
            });
        }

        bddOnTop(map);
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
            for (let i = 0; i < 12; i++) {
                if (map.getLayer(`coeff-hatch-${i}`)) map.setLayoutProperty(`coeff-hatch-${i}`, 'visibility', 'none');
            }
        }
        getBreaks(champ, globalB => showClusters(map, champ, globalB));
        return;
    }

    ['coeff-cluster-circle','coeff-cluster-cluster','coeff-cluster-count'].forEach(id => {
        if (map.getLayer(id)) map.setLayoutProperty(id, 'visibility', 'none');
    });

    const isEvol = champ === 'evolution';
    const pal    = isEvol ? PAL.coeffEv : PAL.coeff;

    if (isEvol) {
        fetchLayer(`/api/coeff?bbox=${bboxParam(map)}`, fc => {
            if (!active) return;
            polyCache = fc;
            if (!fc?.features?.length) return;
            fc.features.forEach(f => { f.properties._evol = getVal(f.properties, champ); });
            const vals   = fc.features.map(f => f.properties._evol).filter(v => v != null && isFinite(v));
            const breaks = computeBreaks(vals, 6);
            upsertPoly(map, fc, pal[pal.length - 1], breaks, pal, '_evol');
            saveLegend('coeff', champEl.options[champEl.selectedIndex].text, breaks, pal, ' %');
        });
    } else {
        getBreaks(champ, globalB => {
            if (!active) return;
            fetchLayer(`/api/coeff?bbox=${bboxParam(map)}`, fc => {
                if (!active) return;
                polyCache = fc;
                if (!fc?.features?.length) return;
                fc.features.forEach(f => { f.properties[champ] = getVal(f.properties, champ); });
                const filtered = applySeuilFilter(fc, champ);
                const breaks = globalB ?? computeBreaks(filtered.features.map(f => f.properties[champ]).filter(v => v != null && isFinite(v)), 6);
                upsertPoly(map, filtered, pal[pal.length - 1], breaks, pal, champ);
                saveLegend('coeff', champEl.options[champEl.selectedIndex].text, breaks, pal, '');
            });
        });
    }
}

export function initCoeff(map) {
    const toggle  = document.getElementById('toggle-coeff');
    const options = document.getElementById('coeff-options');
    const champEl = document.getElementById('coeff-champ');

    toggle.addEventListener('change', () => {
        active = toggle.checked;
        options.classList.toggle('hidden', !active);
        if (!active) { if (abortCtrl) abortCtrl.abort(); removePoly(map); removeClusters(map); dropLegend('coeff'); clearInfo('coeff'); polyCache = null; }
        else loadCoeff(map);
    });
    champEl.addEventListener('change', () => { polyCache = null; clusterCache = null; clearInfo('coeff'); loadCoeff(map); });

    const seuilEl  = document.getElementById('coeff-seuil');
    const seuilVal = document.getElementById('coeff-seuil-val');
    if (seuilEl) {
        seuilEl.addEventListener('input', () => {
            _seuil = parseFloat(seuilEl.value);
            seuilVal.textContent = _seuil.toFixed(2).replace('.', ',');
            if (active && polyCache) {
                const champ = champEl.value;
                const isEvol = champ === 'evolution';
                if (!isEvol) {
                    const pal = PAL.coeff;
                    const filtered = applySeuilFilter(polyCache, champ);
                    filtered.features.forEach(f => { f.properties[champ] = getVal(f.properties, champ); });
                    const breaks = globalBreaks[champ] ?? computeBreaks(filtered.features.map(f => f.properties[champ]).filter(v => v != null && isFinite(v)), 6);
                    upsertPoly(map, filtered, pal[pal.length - 1], breaks, pal, champ);
                    saveLegend('coeff', champEl.options[champEl.selectedIndex].text, breaks, pal, '');
                }
            }
        });
    }

    map.on('click', 'coeff-fill', e => {
        if (!active) return;
        const p    = e.features[0].properties;
        const evol = (p.coeff_2026 != null && p.coeff_2017 != null && +p.coeff_2017 !== 0)
            ? ((+p.coeff_2026 - +p.coeff_2017) / +p.coeff_2017 * 100).toFixed(1) : null;
        const cls  = evol > 0 ? 'tag-up' : evol < 0 ? 'tag-down' : '';
        const esc = v => String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const html = `
            ${evol !== null ? `<div class="info-row">
                <span class="info-label">Évolution 2017→2026</span>
                <span class="info-value ${cls}">${evol} %</span>
            </div>` : ''}
            <table class="evol-table">
                <tr><th>Année</th><th>Coeff</th></tr>
                ${[2017,2018,2019,2020,2024,2026]
                    .filter(y => p['coeff_'+y] != null)
                    .map(y => `<tr><td>${y}</td><td>${esc(p['coeff_'+y])}</td></tr>`)
                    .join('')}
            </table>`;
        const commune = p.nom_commune ? `${p.nom_commune} (${p.codecommune})` : p.codecommune;
        const title = `${commune} — Sect. ${p.section} · Parc. ${p.parcelle}`;
        showInfo('coeff', title, html);
    });

    return { load: () => loadCoeff(map), isActive: () => active };
}
