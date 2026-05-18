// ═══════════════════════════════════════
// UTILITAIRES PURS
// ═══════════════════════════════════════
const spinner = document.getElementById('map-spinner');
let spinnerTimer = null;
function showSpinner() { spinnerTimer = setTimeout(() => { if (spinner) spinner.style.display = 'flex'; }, 300); }
function hideSpinner() { clearTimeout(spinnerTimer); if (spinner) spinner.style.display = 'none'; }

function debounce(fn, delay) {
    let t;
    return function(...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), delay); };
}

function computeBreaks(values, n) {
    const sorted = values.filter(v => v != null && isFinite(v)).sort((a, b) => a - b);
    if (!sorted.length) return Array(n).fill(0);
    return Array.from({ length: n }, (_, i) => sorted[Math.floor(i * sorted.length / n)] ?? 0);
}

// Expression MapLibre step — requiert au minimum 1 stop valide (5 éléments au total)
function stepExpr(prop, breaks, palette, fallback = '#cccccc') {
    if (!breaks.length) return fallback;
    // Collecte les stops strictement croissants
    const stops = [];
    for (let i = 1; i < breaks.length; i++) {
        const prev = stops.length ? stops[stops.length - 1][0] : breaks[0];
        if (breaks[i] > prev) {
            stops.push([breaks[i], palette[i] ?? palette[palette.length - 1]]);
        }
    }
    // Pas assez de variation dans les données : couleur uniforme
    if (!stops.length) return palette[0] ?? fallback;
    const expr = ['step', ['to-number', ['get', prop], 0], palette[0]];
    stops.forEach(([val, col]) => expr.push(val, col));
    return expr;
}

function fmtVal(v, suffix) {
    const n = +v;
    if (suffix === ' %')    return n.toFixed(2);
    if (Math.abs(n) < 100 && n % 1 !== 0) return n.toFixed(2); // coefficients, tarifs décimaux
    return Math.round(n).toLocaleString('fr-FR');
}

const PAL = {
    taux:    ['#bfdbfe', '#60a5fa', '#3b82f6', '#1d4ed8', '#1e3a6e'],
    // 9 valeurs discrètes : 0.7 0.8 0.85 0.9 1.0 1.1 1.15 1.2 1.3
    // bleu foncé (bas) → bleu clair → blanc (1.0) → rose → rouge foncé (haut)
    coeff:   ['#1d4ed8','#60a5fa','#bfdbfe','#e0f2fe','#f8fafc','#fecaca','#f87171','#ef4444','#7f1d1d'],
    coeffEv: ['#b91c1c', '#f97316', '#facc15', '#86efac', '#16a34a'],
    // Spectral inversé : vert (bas) → jaune → orange → rouge (haut)
    tarifs:  ['#1a9850', '#91cf60', '#d9ef8b', '#fee08b', '#fc8d59', '#d73027', '#a50026'],
    tf:      ['#eff6ff', '#93c5fd', '#3b82f6', '#1d4ed8', '#1e3a6e'],
};

// ── Légende multi-couches ────────────────────────────────
const legendEl    = document.getElementById('legend');
const legendState = {}; // { key: {title, breaks, pal, suffix} }

function renderLegend() {
    const keys = ['taux', 'coeff', 'tarifs', 'dossiers'].filter(k => legendState[k]);
    if (!keys.length) { legendEl.classList.add('hidden'); return; }

    document.getElementById('legend-title').textContent = '';
    document.getElementById('legend-items').innerHTML = keys.map(k => {
        const { title, breaks, pal, suffix } = legendState[k];
        const rows = breaks.map((b, i) => {
            const next  = breaks[i + 1];
            const label = next !== undefined
                ? `${fmtVal(b, suffix)}${suffix} – ${fmtVal(next, suffix)}${suffix}`
                : `≥ ${fmtVal(b, suffix)}${suffix}`;
            return `<div class="legend-item">
                <span class="legend-swatch" style="background:${pal[i]}"></span>
                <span>${label}</span>
            </div>`;
        }).join('');
        return `<div class="legend-section-title">${title}</div>${rows}`;
    }).join('');
    legendEl.classList.remove('hidden');
}

function saveLegend(key, title, breaks, pal, suffix = '') {
    legendState[key] = { title, breaks, pal, suffix };
    renderLegend();
}

function dropLegend(key) {
    delete legendState[key];
    renderLegend();
}

// ── Panneau droit ─────────────────────────────────────────
const panelRight = document.getElementById('panel-right');
document.getElementById('close-right').addEventListener('click', () => panelRight.classList.add('hidden'));

function showInfo(title, html) {
    document.getElementById('info-title').textContent = title;
    document.getElementById('info-content').innerHTML = html;
    panelRight.classList.remove('hidden');
    panelRight.scrollTop = 0;
}

function irow(label, val) {
    if (val === null || val === undefined || val === '') return '';
    return `<div class="info-row"><span class="info-label">${label}</span><span class="info-value">${val}</span></div>`;
}

// Pré-chargement des catégories tarifs dès le démarrage du script (avant map.on('load'))
const _catsReady = fetch('/api/tarifs/categories')
    .then(r => r.json())
    .catch(() => []);


// ═══════════════════════════════════════
// CARTE
// ═══════════════════════════════════════
const map = new maplibregl.Map({
    container: 'map',
    style: {
        version: 8,
        glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
        sources: {
            ign_ortho: {
                type: 'raster',
                tiles: [
                    'https://data.geopf.fr/wmts?REQUEST=GetTile&SERVICE=WMTS&VERSION=1.0.0'
                    + '&STYLE=normal&TILEMATRIXSET=PM&FORMAT=image/jpeg'
                    + '&LAYER=ORTHOIMAGERY.ORTHOPHOTOS'
                    + '&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}'
                ],
                tileSize: 256,
                maxzoom: 19,
                attribution: 'IGN-F/Géoportail',
            }
        },
        layers: [{ id: 'ign-ortho', type: 'raster', source: 'ign_ortho' }]
    },
    center: [2.35, 46.6],
    zoom: 6,
});

map.addControl(new maplibregl.NavigationControl(), 'top-right');

// ── PIN géocodage (accessible depuis accueil.php) ─────────
let searchMarker = null;
function afficherSurCarte(lat, lon, classif) {
    const lngLat = [parseFloat(lon), parseFloat(lat)];
    if (searchMarker) {
        searchMarker.setLngLat(lngLat);
    } else {
        const el = document.createElement('div');
        el.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="36" height="48" viewBox="0 0 36 48">
  <defs><radialGradient id="pg" cx="40%" cy="35%" r="60%">
    <stop offset="0%" stop-color="#60a5fa"/>
    <stop offset="100%" stop-color="#1d4ed8"/>
  </radialGradient></defs>
  <path d="M18 2C9.163 2 2 9.163 2 18c0 10 16 28 16 28s16-18 16-28C34 9.163 26.837 2 18 2z"
        fill="url(#pg)" filter="drop-shadow(0 3px 3px rgba(0,0,0,.4))"/>
  <circle cx="18" cy="18" r="6" fill="white" opacity=".95"/>
  <circle cx="18" cy="18" r="2.5" fill="#1d4ed8"/>
</svg>`;
        el.style.cssText = 'cursor:pointer;width:36px;height:48px;';
        searchMarker = new maplibregl.Marker({ element: el, anchor: 'bottom' }).setLngLat(lngLat).addTo(map);
    }
    let zoom = 13;
    if (classif == 4) zoom = 15;
    else if (classif == 3) zoom = 16;
    else if (classif == 7) zoom = 17;
    map.flyTo({ center: lngLat, zoom, duration: 1800, essential: true });
}


// ═══════════════════════════════════════
// TOUT LE RESTE DANS map.on('load')
// (garantit que les sources/couches existent)
// ═══════════════════════════════════════
map.on('load', () => {

    // ── Registre couches BDD ──────────────────────────────
    const BDD = {
        taux:   { src: 'taux-src',   fill: 'taux-fill',   line: 'taux-line'   },
        coeff:  { src: 'coeff-src',  fill: 'coeff-fill',  line: 'coeff-line'  },
        coeffCluster: {
            src:          'coeff-cluster-src',
            circle:       'coeff-cluster-circle',
            cluster:      'coeff-cluster-cluster',
            clusterCount: 'coeff-cluster-count',
        },
        tarifs: { src: 'tarifs-src', fill: 'tarifs-fill', line: 'tarifs-line' },
        dossiers: {
            src:          'dossiers-src',
            circle:       'dossiers-circle',
            cluster:      'dossiers-cluster',
            clusterCount: 'dossiers-cluster-count',
        },
    };

    const bddAbort  = { taux: null, coeff: null, tarifs: null, dossiers: null };
    const bddActive = { taux: false, coeff: false, tarifs: false, dossiers: false };

    function bboxParam() {
        const b = map.getBounds();
        return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
    }

    function fetchBdd(key, url, onData) {
        if (bddAbort[key]) bddAbort[key].abort();
        bddAbort[key] = new AbortController();
        showSpinner();
        fetch(url, { signal: bddAbort[key].signal })
            .then(r => r.json())
            .then(d => { hideSpinner(); onData(d); })
            .catch(e => {
            hideSpinner();
            // Firefox lève un TypeError "NetworkError" sur abort au lieu d'AbortError
            const isAbort = e.name === 'AbortError' || (e instanceof TypeError && e.message.includes('NetworkError'));
            if (!isAbort) console.error(key, e);
        });
    }

    // Remonte les couches BDD au sommet de la pile (au-dessus du WFS)
    function bddOnTop() {
        ['taux-fill','taux-line',
         'coeff-fill','coeff-line',
         'tarifs-fill','tarifs-line',
         'dossiers-circle','dossiers-cluster','dossiers-cluster-count',
        ].forEach(id => { if (map.getLayer(id)) map.moveLayer(id); });
    }

    // Crée ou met à jour une couche polygone BDD
    function upsertPoly(key, geojson, color, outlineColor, opacity) {
        const { src, fill, line } = BDD[key];
        const layerExists = !!map.getLayer(fill);

        // Nettoie une source orpheline si la couche n'existe plus
        if (!layerExists && map.getSource(src)) {
            if (map.getLayer(line)) map.removeLayer(line);
            map.removeSource(src);
        }

        if (layerExists) {
            map.getSource(src).setData(geojson);
            map.setPaintProperty(fill, 'fill-color', color);
        } else {
            map.addSource(src, { type: 'geojson', data: geojson });
            map.addLayer({ id: fill, type: 'fill', source: src,
                paint: { 'fill-color': color, 'fill-opacity': opacity } });
            map.addLayer({ id: line, type: 'line', source: src,
                paint: { 'line-color': outlineColor, 'line-width': 0.5 } });
            map.on('mouseenter', fill, () => map.getCanvas().style.cursor = 'pointer');
            map.on('mouseleave', fill, () => map.getCanvas().style.cursor = '');
        }
        bddOnTop();
    }

    function removePoly(key) {
        const { src, fill, line } = BDD[key];
        [fill, line].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
        if (map.getSource(src)) map.removeSource(src);
    }


    // ─────────────────────────────────────────────────────
    // WFS IGN — fond cadastral
    // ─────────────────────────────────────────────────────
    const wfsTypeNames = {
        departements: 'LIMITES_ADMINISTRATIVES_EXPRESS.LATEST:departement',
        communes:     'CADASTRALPARCELS.PARCELLAIRE_EXPRESS:commune',
        sections:     'CADASTRALPARCELS.PARCELLAIRE_EXPRESS:feuille',
        parcelles:    'CADASTRALPARCELS.PARCELLAIRE_EXPRESS:parcelle',
    };
    const wfsMaxFeat = { departements: 150, communes: 500, sections: 400, parcelles: 600 };
    const wfsStyle   = {
        departements: { fill: '#003189', opacity: 0.05, line: '#003189', lw: 1   },
        communes:     { fill: '#7a8fbb', opacity: 0.04, line: '#7a8fbb', lw: 0.5 },
        sections:     { fill: '#ede9fe', opacity: 0.08, line: '#5b21b6', lw: 1   },
        parcelles:    { fill: '#fef3c7', opacity: 0.10, line: '#b45309', lw: 1   },
    };
    // Labels uniquement sur sections et parcelles (pas de spam sur communes/depts)
    const wfsLabelField = {
        sections:  ['get', 'section'],
        parcelles: ['get', 'numero'],
    };

    let wfsCtrl     = null;
    let lastWfsType = null;
    let lastWfsBbox = null;

    function getWfsType(zoom) {
        if (zoom < 9)  return 'departements';
        if (zoom < 13) return 'communes';
        if (zoom < 15) return 'sections';
        return 'parcelles';
    }

    function updateWfs() {
        const zoom = map.getZoom();
        const type = getWfsType(zoom);
        const b    = map.getBounds();
        const bbox = `${b.getWest().toFixed(4)},${b.getSouth().toFixed(4)},${b.getEast().toFixed(4)},${b.getNorth().toFixed(4)}`;

        if (type === 'departements') {
            if (lastWfsType === 'departements') return;
            lastWfsBbox = null;
        } else {
            if (type === lastWfsType && bbox === lastWfsBbox) return;
        }
        lastWfsType = type;
        lastWfsBbox = bbox;

        if (wfsCtrl) wfsCtrl.abort();
        wfsCtrl = new AbortController();
        showSpinner();

        const params = new URLSearchParams({
            SERVICE: 'WFS', VERSION: '2.0.0', REQUEST: 'GetFeature',
            TYPENAMES: wfsTypeNames[type], SRSNAME: 'EPSG:4326',
            BBOX: type === 'departements' ? '-5.14,41.33,9.56,51.09,EPSG:4326' : bbox + ',EPSG:4326',
            OUTPUTFORMAT: 'application/json', COUNT: wfsMaxFeat[type],
        });

        fetch('https://data.geopf.fr/wfs/ows?' + params, { signal: wfsCtrl.signal })
        .then(r => { if (!r.ok) throw new Error('WFS ' + r.status); return r.text(); })
        .then(text => {
            let data;
            try { data = JSON.parse(text); }
            catch {
                const cut = text.lastIndexOf(',{"type":"Feature"');
                if (cut > 0) {
                    try { data = JSON.parse(text.slice(0, cut) + ']}'); }
                    catch { hideSpinner(); return; }
                } else { hideSpinner(); return; }
            }
            if (!data?.features) { hideSpinner(); return; }

            const s = wfsStyle[type];
            if (map.getSource('wfs-src')) {
                map.getSource('wfs-src').setData(data);
                map.setPaintProperty('wfs-fill', 'fill-color',   s.fill);
                map.setPaintProperty('wfs-fill', 'fill-opacity', s.opacity);
                map.setPaintProperty('wfs-line', 'line-color',   s.line);
                map.setPaintProperty('wfs-line', 'line-width',   s.lw);
            } else {
                map.addSource('wfs-src', { type: 'geojson', data });
                map.addLayer({ id: 'wfs-fill', type: 'fill', source: 'wfs-src',
                    paint: { 'fill-color': s.fill, 'fill-opacity': s.opacity } });
                map.addLayer({ id: 'wfs-line', type: 'line', source: 'wfs-src',
                    paint: { 'line-color': s.line, 'line-width': s.lw } });
                // Couche labels — créée une fois, text-field mis à jour selon le type
                map.addLayer({ id: 'wfs-labels', type: 'symbol', source: 'wfs-src',
                    layout: {
                        'text-field':         ['literal', ''],
                        'text-size':          10,
                        'text-font':          ['Noto Sans Regular'],
                        'text-max-width':     4,
                        'text-allow-overlap': false,
                    },
                    paint: {
                        'text-color':      '#1a2332',
                        'text-halo-color': '#ffffff',
                        'text-halo-width': 1.5,
                        'text-opacity':    0.8,
                    }
                });
            }

            // Labels : visibles seulement pour sections/parcelles
            const labelField = wfsLabelField[type] ?? null;
            map.setLayoutProperty('wfs-labels', 'visibility', labelField ? 'visible' : 'none');
            if (labelField) map.setLayoutProperty('wfs-labels', 'text-field', labelField);

            // Les couches BDD doivent rester au-dessus du WFS
            bddOnTop();
            hideSpinner();
        })
        .catch(err => { hideSpinner(); if (err.name !== 'AbortError') console.error('WFS:', err); });
    }


    // ─────────────────────────────────────────────────────
    // TAUX FISCAUX
    // ─────────────────────────────────────────────────────
    const toggleTaux    = document.getElementById('toggle-taux');
    const tauxOptions   = document.getElementById('taux-options');
    const tauxChamp     = document.getElementById('taux-champ');
    const tauxLevelInfo = document.getElementById('taux-level-info');
    const tauxDeptCache = {}; // cache dept par champ (global, pas de bbox)
    const tauxGlobalBreaks = {};

    const TAUX_DEPT_ZOOM = 9;

    function getTauxBreaks(champ, cb) {
        if (tauxGlobalBreaks[champ]) { cb(tauxGlobalBreaks[champ]); return; }
        fetch(`/api/taux/stats?champ=${champ}`)
            .then(r => r.json())
            .then(b => { tauxGlobalBreaks[champ] = b; cb(b); })
            .catch(() => cb(null));
    }

    function loadTaux() {
        if (!bddActive.taux) return;
        const champ  = tauxChamp.value;
        const zoom   = map.getZoom();
        const isDept = zoom < TAUX_DEPT_ZOOM;

        if (tauxLevelInfo) tauxLevelInfo.textContent = isDept
            ? 'Niveau : département (zoom < 9)'
            : 'Niveau : commune (zoom ≥ 9 et < 13 — sections non dispo)';

        getTauxBreaks(champ, globalBreaks => {
            const render = (fc, level) => {
                // Ignorer si le niveau a changé pendant le fetch
                if ((map.getZoom() < TAUX_DEPT_ZOOM) !== (level === 'dept')) return;
                if (!fc?.features?.length) {
                    if (map.getSource('taux-src')) map.getSource('taux-src').setData({ type: 'FeatureCollection', features: [] });
                    return;
                }
                const breaks = globalBreaks ?? computeBreaks(
                    fc.features.map(f => +f.properties.valeur_affichee).filter(v => isFinite(v)), 5
                );
                upsertPoly('taux', fc, stepExpr('valeur_affichee', breaks, PAL.taux), '#334', 0.7);
                const champLabel = tauxChamp.options[tauxChamp.selectedIndex].text;
                const niveauLabel = level === 'dept' ? 'moy. par département' : 'communes';
                saveLegend('taux', `${champLabel} — ${niveauLabel}`, breaks, PAL.taux, ' %');
            };

            if (isDept) {
                if (tauxDeptCache[champ]) { render(tauxDeptCache[champ], 'dept'); return; }
                fetchBdd('taux', `/api/taux/departements?champ=${champ}`, fc => {
                    tauxDeptCache[champ] = fc;
                    render(fc, 'dept');
                });
            } else {
                fetchBdd('taux', `/api/taux?bbox=${bboxParam()}&champ=${champ}`, fc => render(fc, 'commune'));
            }
        });
    }

    toggleTaux.addEventListener('change', () => {
        bddActive.taux = toggleTaux.checked;
        tauxOptions.classList.toggle('hidden', !bddActive.taux);
        if (!bddActive.taux) { removePoly('taux'); dropLegend('taux'); }
        else loadTaux();
    });
    tauxChamp.addEventListener('change', () => loadTaux());

    map.on('click', 'taux-fill', e => {
        const p = e.features[0].properties;
        const v = p.valeur_affichee;
        const champLabel = tauxChamp.options[tauxChamp.selectedIndex].text;
        if (p.nom_dep) {
            // Niveau département
            showInfo(`${p.nom_dep} (${p.code_dep})`, `
                ${irow(champLabel + ' moyen', v != null ? (+v).toFixed(4)+' %' : '–')}
                <div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez ≥ 9 pour voir le détail par commune</div>
            `);
        } else {
            // Niveau commune
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


    // ─────────────────────────────────────────────────────
    // COEFFICIENTS DE LOCALISATION
    // ─────────────────────────────────────────────────────
    const toggleCoeff  = document.getElementById('toggle-coeff');
    const coeffOptions = document.getElementById('coeff-options');
    const coeffChamp   = document.getElementById('coeff-champ');
    let   coeffCache   = null;

    function getCoeffVal(p) {
        const champ = coeffChamp.value;
        if (champ === 'evolution')
            return (p.coeff_2026 != null && p.coeff_2017 != null && +p.coeff_2017 !== 0)
                ? ((+p.coeff_2026 - +p.coeff_2017) / +p.coeff_2017 * 100) : null;
        return p[champ] != null ? +p[champ] : null;
    }

    // Breaks globaux coeff (chargés une fois par champ)
    const coeffGlobalBreaks = {};

    function getCoeffBreaks(champ, pal, suffix, cb) {
        const key = champ === 'evolution' ? '_evol_global' : champ;
        if (coeffGlobalBreaks[key]) { cb(coeffGlobalBreaks[key]); return; }
        const apiChamp = champ === 'evolution' ? 'coeff_2026' : champ;
        fetch(`/api/coeff/stats?champ=${apiChamp}`)
            .then(r => r.json())
            .then(breaks => { coeffGlobalBreaks[key] = breaks; cb(breaks); })
            .catch(() => cb(null));
    }

    function renderCoeff(globalBreaks) {
        const fc = coeffCache;
        if (!fc?.features?.length) return;
        const isEvol  = coeffChamp.value === 'evolution';
        const pal     = isEvol ? PAL.coeffEv : PAL.coeff;
        const suffix  = isEvol ? ' %' : '';
        const propKey = isEvol ? '_evol' : coeffChamp.value;

        fc.features.forEach(f => { f.properties[propKey] = getCoeffVal(f.properties); });

        const breaks = globalBreaks ?? computeBreaks(
            fc.features.map(f => f.properties[propKey]).filter(v => v != null && isFinite(v)), 5
        );
        upsertPoly('coeff', fc, stepExpr(propKey, breaks, pal), '#666', 0.75);
        saveLegend('coeff', coeffChamp.options[coeffChamp.selectedIndex].text, breaks, pal, suffix);
    }

    const COEFF_MIN_ZOOM = 13;
    let coeffClusterCache = null; // données globales clusters (chargées une fois par champ)
    let coeffClusterChamp = null; // champ en cours dans le cache cluster

    function removeCoeffClusters() {
        const { src, circle, cluster, clusterCount } = BDD.coeffCluster;
        [clusterCount, cluster, circle].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
        if (map.getSource(src)) map.removeSource(src);
    }

    function showCoeffClusters(globalBreaks) {
        const isEvol  = coeffChamp.value === 'evolution';
        const pal     = isEvol ? PAL.coeffEv : PAL.coeff;
        const apiChamp = isEvol ? 'coeff_2026' : coeffChamp.value;

        const applyCluster = fc => {
            if (!fc?.features?.length) return;
            // Calcule la valeur affichée sur chaque feature
            fc.features.forEach(f => {
                f.properties._cv = isEvol ? null : +f.properties.valeur;
            });

            const breaks = globalBreaks ?? computeBreaks(
                fc.features.map(f => f.properties._cv).filter(v => v != null && isFinite(v)), 5
            );
            const color = stepExpr('_cv', breaks, pal);

            const { src, circle, cluster, clusterCount } = BDD.coeffCluster;
            if (map.getSource(src)) {
                map.getSource(src).setData(fc);
                if (map.getLayer(circle)) map.setPaintProperty(circle, 'circle-color', color);
            } else {
                map.addSource(src, { type: 'geojson', data: fc, cluster: true, clusterRadius: 35, clusterMaxZoom: COEFF_MIN_ZOOM - 1 });
                map.addLayer({
                    id: circle, type: 'circle', source: src,
                    filter: ['!', ['has', 'point_count']],
                    paint: { 'circle-color': color, 'circle-radius': 6,
                             'circle-stroke-width': 1.5, 'circle-stroke-color': '#fff' }
                });
                map.addLayer({
                    id: cluster, type: 'circle', source: src,
                    filter: ['has', 'point_count'],
                    paint: {
                        'circle-color': ['step', ['get', 'point_count'], pal[1], 5, pal[2], 20, pal[3], 50, pal[4]],
                        'circle-radius': ['step', ['get', 'point_count'], 10, 5, 14, 20, 18, 50, 24],
                        'circle-stroke-width': 2, 'circle-stroke-color': 'rgba(255,255,255,.7)',
                    }
                });
                map.addLayer({
                    id: clusterCount, type: 'symbol', source: src,
                    filter: ['has', 'point_count'],
                    layout: { 'text-field': '{point_count_abbreviated}',
                              'text-font': ['Noto Sans Regular'], 'text-size': 11 },
                    paint: { 'text-color': '#fff' }
                });
                map.on('mouseenter', circle,  () => map.getCanvas().style.cursor = 'pointer');
                map.on('mouseleave', circle,  () => map.getCanvas().style.cursor = '');
                map.on('mouseenter', cluster, () => map.getCanvas().style.cursor = 'pointer');
                map.on('mouseleave', cluster, () => map.getCanvas().style.cursor = '');
                map.on('click', cluster, e => {
                    const feat = map.queryRenderedFeatures(e.point, { layers: [cluster] });
                    map.getSource(src).getClusterExpansionZoom(feat[0].properties.cluster_id, (err, zoom) => {
                        if (!err) map.easeTo({ center: feat[0].geometry.coordinates, zoom });
                    });
                });
                map.on('click', circle, e => {
                    const p = e.features[0].properties;
                    showInfo(`Commune ${p.codecommune}`, `
                        ${irow('Code commune', p.codecommune)}
                        ${irow('Coeff moyen', p.valeur)}
                        ${irow('Nb parcelles', p.nb_parcelles)}
                        <div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez ≥ 12 pour voir le détail par parcelle</div>
                    `);
                });
            }
            bddOnTop();
            const suffix = isEvol ? ' %' : '';
            saveLegend('coeff', coeffChamp.options[coeffChamp.selectedIndex].text + ' (communes)', breaks, pal, suffix);
        };

        // Cache par champ
        if (coeffClusterCache && coeffClusterChamp === apiChamp) { applyCluster(coeffClusterCache); return; }
        fetchBdd('coeff', `/api/coeff/clusters?champ=${apiChamp}`, fc => {
            coeffClusterCache = fc;
            coeffClusterChamp = apiChamp;
            applyCluster(fc);
        });
    }

    function loadCoeff() {
        if (!bddActive.coeff) return;
        const zoom = map.getZoom();

        if (zoom < COEFF_MIN_ZOOM) {
            // Mode cluster : masquer les polygones, afficher les clusters
        if (map.getLayer('coeff-fill')) {
                map.setLayoutProperty('coeff-fill', 'visibility', 'none');
                map.setLayoutProperty('coeff-line', 'visibility', 'none');
            }
        if (map.getLayer('coeff-cluster-circle')) {
            map.setLayoutProperty('coeff-cluster-circle',  'visibility', 'visible');
            map.setLayoutProperty('coeff-cluster-cluster', 'visibility', 'visible');
            map.setLayoutProperty('coeff-cluster-count',   'visibility', 'visible');
        }
            const isEvol = coeffChamp.value === 'evolution';
            const pal    = isEvol ? PAL.coeffEv : PAL.coeff;
            getCoeffBreaks(coeffChamp.value, pal, isEvol ? ' %' : '', globalBreaks => {
                showCoeffClusters(globalBreaks);
            });
            return;
        }

        // Mode polygone : masquer les clusters, afficher les parcelles
        if (map.getLayer('coeff-cluster-circle')) {
            map.setLayoutProperty('coeff-cluster-circle',  'visibility', 'none');
            map.setLayoutProperty('coeff-cluster-cluster', 'visibility', 'none');
            map.setLayoutProperty('coeff-cluster-count',   'visibility', 'none');
        }
        if (map.getLayer('coeff-fill')) {
            map.setLayoutProperty('coeff-fill', 'visibility', 'visible');
            map.setLayoutProperty('coeff-line', 'visibility', 'visible');
        }
        const isEvol = coeffChamp.value === 'evolution';
        const pal    = isEvol ? PAL.coeffEv : PAL.coeff;
        const suffix = isEvol ? ' %' : '';
        getCoeffBreaks(coeffChamp.value, pal, suffix, globalBreaks => {
            fetchBdd('coeff', `/api/coeff?bbox=${bboxParam()}`, fc => {
                coeffCache = fc;
                renderCoeff(globalBreaks);
            });
        });
    }

    toggleCoeff.addEventListener('change', () => {
        bddActive.coeff = toggleCoeff.checked;
        coeffOptions.classList.toggle('hidden', !bddActive.coeff);
        if (!bddActive.coeff) {
            removePoly('coeff');
            removeCoeffClusters();
            dropLegend('coeff');
            coeffCache = null;
        } else loadCoeff();
    });
    coeffChamp.addEventListener('change', () => {
        coeffCache = null;
        coeffClusterCache = null;
        loadCoeff();
    });

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


    // ─────────────────────────────────────────────────────
    // DOSSIERS (points clusterisés)
    // ─────────────────────────────────────────────────────
    const toggleDossiers = document.getElementById('toggle-dossiers');
    let dossiersLoaded   = false;

    function loadDossiers() {
        if (!bddActive.dossiers || dossiersLoaded) return;
        fetchBdd('dossiers', '/api/dossiers', fc => {
            if (!fc?.features?.length) return;
            const tfVals = fc.features
                .map(f => +f.properties.apo_montanttaxefonciere)
                .filter(v => isFinite(v) && v > 0);
            const breaks = computeBreaks(tfVals, 5);
            const color  = stepExpr('apo_montanttaxefonciere', breaks, PAL.tf, '#94a3b8');

            const { src, circle, cluster, clusterCount } = BDD.dossiers;
            map.addSource(src, { type: 'geojson', data: fc, cluster: true, clusterRadius: 40 });
            map.addLayer({
                id: circle, type: 'circle', source: src,
                filter: ['!', ['has', 'point_count']],
                paint: { 'circle-color': color, 'circle-radius': 5,
                         'circle-stroke-width': 1.5, 'circle-stroke-color': '#fff' }
            });
            map.addLayer({
                id: cluster, type: 'circle', source: src,
                filter: ['has', 'point_count'],
                paint: {
                    'circle-color':        '#003189',
                    'circle-radius':       ['step', ['get', 'point_count'], 12, 10, 16, 50, 20],
                    'circle-stroke-width': 2,
                    'circle-stroke-color': 'rgba(255,255,255,.8)',
                }
            });
            map.addLayer({
                id: clusterCount, type: 'symbol', source: src,
                filter: ['has', 'point_count'],
                layout: { 'text-field': '{point_count_abbreviated}',
                          'text-font': ['Noto Sans Regular'], 'text-size': 11 },
                paint: { 'text-color': '#fff' }
            });

            dossiersLoaded = true;
            saveLegend('dossiers', 'Taxe foncière', breaks, PAL.tf, ' €');
            bddOnTop();

            map.on('mouseenter', circle,  () => map.getCanvas().style.cursor = 'pointer');
            map.on('mouseleave', circle,  () => map.getCanvas().style.cursor = '');
            map.on('mouseenter', cluster, () => map.getCanvas().style.cursor = 'pointer');
            map.on('mouseleave', cluster, () => map.getCanvas().style.cursor = '');
        });
    }

    toggleDossiers.addEventListener('change', () => {
        bddActive.dossiers = toggleDossiers.checked;
        if (!bddActive.dossiers) {
            const { src, circle, cluster, clusterCount } = BDD.dossiers;
            [clusterCount, cluster, circle].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
            if (map.getSource(src)) map.removeSource(src);
            dossiersLoaded = false;
            dropLegend('dossiers');
        } else {
            loadDossiers();
        }
    });

    map.on('click', 'dossiers-circle', e => {
        const p  = e.features[0].properties;
        const tf = +p.apo_montanttaxefonciere;
        showInfo(`Dossier ${p.dossier}`, `
            ${irow('Dossier', p.dossier)}
            ${irow('Nom', p.name)}
            ${irow('Code', p.rtx_code)}
            ${irow('Adresse', p.adresse_complete)}
            ${irow('Taxe foncière', isFinite(tf) && tf > 0 ? tf.toLocaleString('fr-FR')+' €' : '–')}
            ${irow('Lot', p.lot)}
            ${irow('Section', p.section)}
            ${irow('INSEE', p.insee)}
        `);
    });

    map.on('click', 'dossiers-cluster', e => {
        const feat = map.queryRenderedFeatures(e.point, { layers: ['dossiers-cluster'] });
        const id   = feat[0].properties.cluster_id;
        map.getSource(BDD.dossiers.src).getClusterExpansionZoom(id, (err, zoom) => {
            if (!err) map.easeTo({ center: feat[0].geometry.coordinates, zoom });
        });
    });


    // ─────────────────────────────────────────────────────
    // TARIFS SECTIONS
    // ─────────────────────────────────────────────────────
    const toggleTarifs  = document.getElementById('toggle-tarifs');
    const tarifsOptions = document.getElementById('tarifs-options');
    const tarifsCat     = document.getElementById('tarifs-cat');
    const tarifsAnnee   = document.getElementById('tarifs-annee');
    const tarifsCache   = {};

    // Breaks globaux tarifs (chargés une fois par cat+année)
    const tarifsGlobalBreaks = {};

    function getTarifsBreaks(cat, annee, cb) {
        const key = `${cat}|${annee}`;
        if (tarifsGlobalBreaks[key]) { cb(tarifsGlobalBreaks[key]); return; }
        fetch(`/api/tarifs/stats?categorie=${cat}&annee=${annee}`)
            .then(r => r.json())
            .then(breaks => { tarifsGlobalBreaks[key] = breaks; cb(breaks); })
            .catch(() => cb(null));
    }

    // Zoom aligné sur le WFS : < 9 → départements, < 13 → communes, ≥ 13 → sections
    const TARIFS_DEPT_ZOOM    = 9;
    const TARIFS_COMMUNE_ZOOM = 13;

    _catsReady.then(cats => {
        if (!cats?.length) return;
        tarifsCat.innerHTML = cats.map(c => `<option value="${c}">${c}</option>`).join('');
        if (bddActive.tarifs) loadTarifs();
    });

    function getTarifsLevel(zoom) {
        if (zoom < TARIFS_DEPT_ZOOM)    return 'dept';
        if (zoom < TARIFS_COMMUNE_ZOOM) return 'commune';
        return 'section';
    }

    const tarifsLevelInfo = document.getElementById('tarifs-level-info');

    function loadTarifs() {
        if (!bddActive.tarifs || !tarifsCat.value) return;
        const cat   = tarifsCat.value;
        const annee = tarifsAnnee.value;
        const zoom  = map.getZoom();
        const level = getTarifsLevel(zoom);

        if (tarifsLevelInfo) {
            const labels = { dept: 'Niveau : département (zoom < 9)', commune: 'Niveau : commune (zoom < 13)', section: 'Niveau : section (zoom ≥ 13)' };
            tarifsLevelInfo.textContent = labels[level] ?? '';
        }

        getTarifsBreaks(cat, annee, globalBreaks => {
            const render = (fc, renderLevel) => {
                // Si le niveau a changé pendant le fetch, ignorer la réponse obsolète
                if (getTarifsLevel(map.getZoom()) !== renderLevel) return;

                // Vider la couche si pas de données
                if (!fc?.features?.length) {
                    if (map.getSource('tarifs-src')) map.getSource('tarifs-src').setData({ type: 'FeatureCollection', features: [] });
                    return;
                }
                const breaks = globalBreaks ?? computeBreaks(
                    fc.features.map(f => +f.properties.valeur).filter(v => isFinite(v)), 7
                );
                upsertPoly('tarifs', fc, stepExpr('valeur', breaks, PAL.tarifs), '#444', 0.7);
                const niveauLabel = { dept: 'départements', commune: 'communes', section: 'sections' }[renderLevel];
                const legendTitle = renderLevel === 'section'
                    ? `${cat} — ${annee} (${niveauLabel})`
                    : `${cat} — ${annee} — moy. par ${niveauLabel.slice(0,-1)}`;
                saveLegend('tarifs', legendTitle, breaks, PAL.tarifs, ' €/m²');
            };

            // Dept : données globales → cache (pas de bbox)
            const deptKey = `${cat}|${annee}|dept`;
            if (level === 'dept') {
                if (tarifsCache[deptKey]) { render(tarifsCache[deptKey], 'dept'); return; }
                fetchBdd('tarifs', `/api/tarifs/departements?categorie=${cat}&annee=${annee}`, fc => {
                    tarifsCache[deptKey] = fc;
                    render(fc, 'dept');
                });
                return;
            }

            // Commune / section : bbox-dépendant → recharge à chaque moveend
            const url = level === 'commune'
                ? `/api/tarifs/communes?bbox=${bboxParam()}&categorie=${cat}&annee=${annee}`
                : `/api/tarifs?bbox=${bboxParam()}&categorie=${cat}&annee=${annee}`;
            fetchBdd('tarifs', url, fc => render(fc, level));
        });
    }

    toggleTarifs.addEventListener('change', () => {
        bddActive.tarifs = toggleTarifs.checked;
        tarifsOptions.classList.toggle('hidden', !bddActive.tarifs);
        if (!bddActive.tarifs) { removePoly('tarifs'); dropLegend('tarifs'); }
        else loadTarifs();
    });
    tarifsCat.addEventListener('change', loadTarifs);
    tarifsAnnee.addEventListener('change', loadTarifs);

    map.on('click', 'tarifs-fill', e => {
        const p = e.features[0].properties;
        if (p.section) {
            // Niveau section : historique complet
            const rows = [2017,2019,2020,2021,2022,2023,2024,2025,2026]
                .filter(y => p[`val_${y}`] != null)
                .map(y => `<tr><td>${y}</td><td>${p['val_'+y]} €/m²</td></tr>`)
                .join('');
            showInfo(`Section ${p.section} — ${p.nom_com}`, `
                ${irow('Commune', p.nom_com)}
                ${irow('INSEE', p.code_insee)}
                ${irow('Secteur', p.secteur)}
                ${irow('Catégorie', p.categorie)}
                ${irow(`Tarif ${tarifsAnnee.value}`, p.valeur != null ? p.valeur+' €/m²' : '–')}
                <div class="info-row"><span class="info-label">Évolution</span></div>
                <table class="evol-table"><tr><th>Année</th><th>Tarif</th></tr>${rows}</table>
            `);
        } else if (p.code_insee) {
            // Niveau commune : tarif moyen
            showInfo(`${p.nom_com} (${p.code_insee})`, `
                ${irow('Département', p.code_dep)}
                ${irow('Tarif moyen '+ tarifsAnnee.value, p.valeur != null ? p.valeur+' €/m²' : '–')}
                <div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez pour voir le détail par section</div>
            `);
        } else {
            // Niveau département
            showInfo(`${p.nom_dep ?? 'Département'} (${p.code_dep})`, `
                ${irow('Tarif moyen '+ tarifsAnnee.value, p.valeur != null ? p.valeur+' €/m²' : '–')}
                <div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez pour voir le détail par commune ou section</div>
            `);
        }
    });


    // ─────────────────────────────────────────────────────
    // ZOOM INFO + DÉCLENCHEMENT AU DÉPLACEMENT
    // ─────────────────────────────────────────────────────
    function refreshZoomInfo() {
        const el = document.getElementById('zoom-info');
        if (el) el.textContent = `Zoom : ${Math.round(map.getZoom() * 10) / 10}`;
    }

    map.on('moveend', debounce(() => {
        refreshZoomInfo();
        updateWfs();
        if (bddActive.taux)   loadTaux();
        if (bddActive.coeff)  loadCoeff();
        if (bddActive.tarifs) loadTarifs();
    }, 400));

    map.on('zoomend', refreshZoomInfo);

    updateWfs();

}); // fin map.on('load')
