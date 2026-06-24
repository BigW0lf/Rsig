/**
 * ortho.js — Fonds de carte IGN historiques + overlay millésimes acquisition par département
 */

const CAMPAGNES = [
    { id: 'actuelle',  label: 'Actuelle (2024-2025)',  layer: 'ORTHOIMAGERY.ORTHOPHOTOS',         tileMatrixSet: 'PM',     maxzoom: 19 },
    { id: '2021-2023', label: '2021 – 2023',            layer: 'ORTHOIMAGERY.ORTHOPHOTOS2021-2023', tileMatrixSet: 'PM_6_19', maxzoom: 19 },
    { id: '2016-2020', label: '2016 – 2020',            layer: 'ORTHOIMAGERY.ORTHOPHOTOS2016-2020', tileMatrixSet: 'PM_6_19', maxzoom: 19 },
    { id: '2011-2015', label: '2011 – 2015',            layer: 'ORTHOIMAGERY.ORTHOPHOTOS2011-2015', tileMatrixSet: 'PM_6_18', maxzoom: 18 },
    { id: '2006-2010', label: '2006 – 2010',            layer: 'ORTHOIMAGERY.ORTHOPHOTOS2006-2010', tileMatrixSet: 'PM_6_18', maxzoom: 18 },
    { id: '2000-2005', label: '2000 – 2005',            layer: 'ORTHOIMAGERY.ORTHOPHOTOS2000-2005', tileMatrixSet: 'PM_6_18', maxzoom: 18 },
];

// Palette années → couleur
const ANNEE_COLORS = [
    [2025, '#1a9850'], [2024, '#66bd63'], [2023, '#a6d96a'],
    [2022, '#d9ef8b'], [2021, '#fee08b'], [2020, '#fdae61'],
    [2019, '#f46d43'], [2018, '#d73027'], [2017, '#a50026'],
    [2016, '#762a83'], [2015, '#9970ab'], [2014, '#c2a5cf'],
    [2013, '#e7d4e8'], [2012, '#d1e5f0'], [2011, '#92c5de'],
    [2010, '#4393c3'], [2009, '#2166ac'], [2008, '#053061'],
    [2007, '#313695'], [2006, '#4575b4'], [2005, '#74add1'],
    [2004, '#abd9e9'], [2003, '#e0f3f8'], [2002, '#ffffbf'],
    [2001, '#fee090'], [2000, '#fdae61'],
];

function buildColorExpr() {
    const expr = ['match', ['get', 'annee_acq']];
    ANNEE_COLORS.forEach(([y, c]) => { expr.push(y, c); });
    expr.push('#cccccc');
    return expr;
}

function wmtsTileUrl(campagne) {
    return 'https://data.geopf.fr/wmts?REQUEST=GetTile&SERVICE=WMTS&VERSION=1.0.0'
        + '&STYLE=normal&TILEMATRIXSET=' + campagne.tileMatrixSet
        + '&FORMAT=image/jpeg'
        + '&LAYER=' + campagne.layer
        + '&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}';
}

let _map = null;
let _active = false;
let _campagne = 'actuelle';
let _geojsonCache = {};
let _popup = null;

function isActive() { return _active; }

// ── Swap la source raster ign_ortho ──────────────────────────────────────────
function swapOrthoSource(campagneId) {
    const c = CAMPAGNES.find(x => x.id === campagneId) || CAMPAGNES[0];
    const src = _map.getSource('ign_ortho');
    if (!src) return;
    // MapLibre ne permet pas de modifier les tiles d'une source existante directement,
    // on retire / réajoute la source et les couches dépendantes
    const hadLabels = !!_map.getLayer('ign-labels');

    _map.removeLayer('ign-labels');
    _map.removeLayer('ign-ortho');
    _map.removeSource('ign_ortho');

    _map.addSource('ign_ortho', {
        type: 'raster',
        tiles: [wmtsTileUrl(c)],
        tileSize: 256,
        maxzoom: c.maxzoom,
        attribution: 'IGN-F/Géoportail',
    });

    // Réinsérer sous toutes les autres couches
    const firstLayerId = _map.getStyle().layers[0]?.id;
    _map.addLayer({ id: 'ign-ortho', type: 'raster', source: 'ign_ortho' }, firstLayerId);
    if (hadLabels) {
        _map.addLayer({ id: 'ign-labels', type: 'raster', source: 'ign_labels' }, firstLayerId === 'ign-ortho' ? undefined : firstLayerId);
    }
}

// ── Overlay polygones depts ──────────────────────────────────────────────────
async function loadOverlay(campagneId) {
    if (_geojsonCache[campagneId]) {
        updateOverlaySource(campagneId);
        return;
    }
    try {
        const r = await fetch('/api/ortho/millesimes?campagne=' + campagneId);
        if (!r.ok) throw new Error('HTTP ' + r.status);
        _geojsonCache[campagneId] = await r.json();
    } catch (e) {
        console.warn('[ortho] fetch failed', e);
        _geojsonCache[campagneId] = { type: 'FeatureCollection', features: [] };
    }
    updateOverlaySource(campagneId);
}

function updateOverlaySource(campagneId) {
    const src = _map.getSource('ortho-depts');
    if (!src) return;
    src.setData(_geojsonCache[campagneId] || { type: 'FeatureCollection', features: [] });
}

// ── Ajouter couches overlay ─────────────────────────────────────────────────
function addOverlayLayers() {
    if (_map.getSource('ortho-depts')) return;

    _map.addSource('ortho-depts', {
        type: 'geojson',
        data: { type: 'FeatureCollection', features: [] },
    });

    _map.addLayer({
        id: 'ortho-depts-fill',
        type: 'fill',
        source: 'ortho-depts',
        paint: {
            'fill-color': buildColorExpr(),
            'fill-opacity': 0.55,
        },
    });

    _map.addLayer({
        id: 'ortho-depts-line',
        type: 'line',
        source: 'ortho-depts',
        paint: {
            'line-color': '#333',
            'line-width': 0.5,
            'line-opacity': 0.5,
        },
    });

    _map.addLayer({
        id: 'ortho-depts-label',
        type: 'symbol',
        source: 'ortho-depts',
        layout: {
            'text-field': ['concat', ['get', 'nom_dep'], '\n', ['to-string', ['get', 'annee_acq']]],
            'text-size': 11,
            'text-anchor': 'center',
            'text-max-width': 10,
        },
        paint: {
            'text-color': '#111',
            'text-halo-color': 'rgba(255,255,255,0.8)',
            'text-halo-width': 1.5,
        },
        minzoom: 5,
        maxzoom: 8,
    });

    // Popup clic
    _map.on('click', 'ortho-depts-fill', (e) => {
        const f = e.features?.[0];
        if (!f) return;
        const { nom_dep, annee_acq, code_dep } = f.properties;
        if (_popup) _popup.remove();
        _popup = new maplibregl.Popup({ closeButton: true, closeOnClick: true })
            .setLngLat(e.lngLat)
            .setHTML(
                `<div style="font-size:12px;line-height:1.6">
                    <strong>${nom_dep}</strong> (${code_dep})<br>
                    Acquisition : <strong>${annee_acq}</strong>
                </div>`
            )
            .addTo(_map);
    });
    _map.on('mouseenter', 'ortho-depts-fill', () => { _map.getCanvas().style.cursor = 'pointer'; });
    _map.on('mouseleave', 'ortho-depts-fill', () => { _map.getCanvas().style.cursor = ''; });
}

function removeOverlayLayers() {
    ['ortho-depts-label', 'ortho-depts-line', 'ortho-depts-fill'].forEach(id => {
        if (_map.getLayer(id)) _map.removeLayer(id);
    });
    if (_map.getSource('ortho-depts')) _map.removeSource('ortho-depts');
    if (_popup) { _popup.remove(); _popup = null; }
}

function setOverlayVisible(v) {
    ['ortho-depts-fill', 'ortho-depts-line', 'ortho-depts-label'].forEach(id => {
        if (_map.getLayer(id)) _map.setLayoutProperty(id, 'visibility', v ? 'visible' : 'none');
    });
}


// ── Activation / désactivation ───────────────────────────────────────────────
function activate() {
    _active = true;
    swapOrthoSource(_campagne);
    addOverlayLayers();
    loadOverlay(_campagne);
}

function deactivate() {
    _active = false;
    swapOrthoSource('actuelle');
    removeOverlayLayers();
}

// ── API publique — appelable depuis le contrôle IGN ─────────────────────────
export function setCampagne(id) {
    _campagne = id;
    if (!_map) return;
    swapOrthoSource(_campagne);
    if (_active) loadOverlay(_campagne);
}

// ── Export ───────────────────────────────────────────────────────────────────
export function initOrtho(map) {
    _map = map;

    // Appliquer la campagne si elle a été changée avant le chargement de la map
    const ctrlSel = document.getElementById('ign-campagne-ctrl');
    if (ctrlSel && ctrlSel.value !== _campagne) {
        _campagne = ctrlSel.value;
        swapOrthoSource(_campagne);
    }

    const toggle = document.getElementById('toggle-ortho');
    if (!toggle) return { isActive };

    toggle.addEventListener('change', () => {
        if (toggle.checked) activate();
        else deactivate();
    });

    return { isActive };
}

