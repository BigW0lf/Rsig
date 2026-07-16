import { showSpinner, hideSpinner } from './utils.js';
import { isMeasuring } from './measure.js';
import { hasVisibleLayer } from './catalogue.js';

// ── Cache millésimes ortho par département ────────────────────────────────────
const _orthoCache = {};

async function getOrthoAnnee(codeDep) {
    const sel = document.getElementById('ign-campagne-ctrl');
    const campagne = sel?.value || 'actuelle';
    if (!_orthoCache[campagne]) {
        try {
            const r = await fetch('/api/ortho/millesimes?campagne=' + campagne);
            const fc = await r.json();
            _orthoCache[campagne] = {};
            (fc.features || []).forEach(f => {
                _orthoCache[campagne][f.properties.code_dep] = f.properties.annee_acq;
            });
        } catch { _orthoCache[campagne] = {}; }
    }
    return _orthoCache[campagne][codeDep] ?? null;
}

// ── Labels lisibles par type de feature ──────────────────────────────────────
const TYPE_LABEL = {
    departements: 'Département',
    communes: 'Commune',
    sections: 'Section cadastrale',
    parcelles: 'Parcelle cadastrale',
};

function featureProps(type, props) {
    switch (type) {
        case 'departements':
            return [
                ['Code', props.code_insee],
                ['Département', props.nom_officiel],
            ];
        case 'communes':
            return [
                ['Code INSEE', props.code_insee],
                ['Commune', props.nom_com],
                ['Département', props.code_dep],
            ];
        case 'sections':
            return [
                ['Commune', props.nom_com],
                ['Code INSEE', props.code_insee],
                ['Section', props.section],
                ['Préfixe', props.com_abs || null],
            ];
        case 'parcelles':
            return [
                ['Commune', props.nom_com],
                ['Code INSEE', props.code_insee],
                ['Section', props.section],
                ['Numéro', props.numero],
                ['IDU', props.idu],
                ['Contenance', props.contenance != null ? props.contenance + ' m²' : null],
            ];
        default: return [];
    }
}

const style = {
    departements: { fill: '#003189', opacity: 0.05, line: '#003189', lw: 1   },
    communes:     { fill: '#7a8fbb', opacity: 0.04, line: '#7a8fbb', lw: 0.5 },
    sections:     { fill: '#ede9fe', opacity: 0.08, line: '#5b21b6', lw: 1   },
    parcelles:    { fill: '#fef3c7', opacity: 0.10, line: '#b45309', lw: 1   },
};

// ── IGN TMS vector tiles ──────────────────────────────────────────────────────
// Cadastral Express — communes (z9-13), sections/feuilles (z13-15), parcelles (z15+)
const IGN_TMS = 'https://data.geopf.fr/tms/1.0.0/CADASTRALPARCELS.PARCELLAIRE_EXPRESS@EPSG:3857/{z}/{x}/{y}.pbf';

// source-layer dans le PBF → clé interne
const SRC_LAYER_TO_TYPE = { commune: 'communes', feuille: 'sections', parcelle: 'parcelles' };

const CADASTRAL_LAYERS = [
    { type: 'communes',  srcLayer: 'commune',  minzoom:  9, maxzoom: 13, label: null },
    { type: 'sections',  srcLayer: 'feuille',  minzoom: 13, maxzoom: 15, label: ['get', 'section'] },
    { type: 'parcelles', srcLayer: 'parcelle', minzoom: 15, maxzoom: 22, label: ['get', 'numero'] },
];

// Compatibilité avec les rares endroits qui appelaient getWfsType()
export function getWfsType(zoom) {
    if (zoom < 9)  return 'departements';
    if (zoom < 13) return 'communes';
    if (zoom < 15) return 'sections';
    return 'parcelles';
}

// ── Initialisation (appelée une seule fois sur map.load, puis no-op) ──────────
let _initialized = false;

export function updateWfs(map) {
    if (_initialized) return;
    _initialized = true;

    // 1. Tuiles vectorielles IGN : communes, sections, parcelles
    map.addSource('ign-cadastral', {
        type: 'vector',
        tiles: [IGN_TMS],
        minzoom: 6,
        maxzoom: 20,
        attribution: '© IGN — Parcellaire Express',
    });

    for (const { type, srcLayer, minzoom, maxzoom, label } of CADASTRAL_LAYERS) {
        const s = style[type];
        map.addLayer({
            id: `wfs-${type}-fill`, type: 'fill',
            source: 'ign-cadastral', 'source-layer': srcLayer,
            minzoom, maxzoom,
            paint: { 'fill-color': s.fill, 'fill-opacity': s.opacity },
        });
        map.addLayer({
            id: `wfs-${type}-line`, type: 'line',
            source: 'ign-cadastral', 'source-layer': srcLayer,
            minzoom, maxzoom,
            paint: { 'line-color': s.line, 'line-width': s.lw },
        });
        if (label) {
            map.addLayer({
                id: `wfs-${type}-labels`, type: 'symbol',
                source: 'ign-cadastral', 'source-layer': srcLayer,
                minzoom, maxzoom,
                layout: {
                    'text-field': label,
                    'text-size': 10,
                    'text-font': ['Noto Sans Regular'],
                    'text-max-width': 8,
                    'text-allow-overlap': false,
                },
                paint: {
                    'text-color': '#1a2332',
                    'text-halo-color': '#ffffff',
                    'text-halo-width': 1.5,
                    'text-opacity': 0.8,
                },
            });
        }
    }

    // 2. Départements : GeoJSON proxy local (cache 24h, ~200 KB)
    _fetchDept(map);
}

function _fetchDept(map) {
    showSpinner();
    fetch('/api/wfs/departements')
        .then(r => { if (!r.ok) throw new Error('dept ' + r.status); return r.json(); })
        .then(data => {
            hideSpinner();
            if (!data?.features) return;
            const s = style.departements;
            map.addSource('wfs-dept-src', { type: 'geojson', data });
            map.addLayer({
                id: 'wfs-dept-fill', type: 'fill', source: 'wfs-dept-src',
                maxzoom: 9,
                paint: { 'fill-color': s.fill, 'fill-opacity': s.opacity },
            });
            map.addLayer({
                id: 'wfs-dept-line', type: 'line', source: 'wfs-dept-src',
                maxzoom: 9,
                paint: { 'line-color': s.line, 'line-width': s.lw },
            });
        })
        .catch(() => hideSpinner());
}

// ── Clic + hover ──────────────────────────────────────────────────────────────
export function initWfsClick(map) {
    let _popup = null;

    async function handleClick(e, type) {
        if (isMeasuring()) return;
        if (hasVisibleLayer()) return;
        if (map.queryRenderedFeatures(e.point, { layers: ['osm-point'] }).length) return;

        const f = e.features?.[0];
        if (!f) return;
        const props = f.properties;

        const rows = featureProps(type, props).filter(([, v]) => v != null && v !== '');

        const isSat = map.getLayoutProperty('osm-tiles', 'visibility') !== 'visible';
        let orthoHtml = '';
        if (isSat) {
            let codeDep = props.code_dep ?? null;
            if (!codeDep && props.code_insee) {
                const insee = String(props.code_insee);
                codeDep = ['971','972','973','974','976'].some(d => insee.startsWith(d))
                    ? insee.slice(0, 3) : insee.slice(0, 2);
            }
            if (type === 'departements') codeDep = props.code_insee;
            if (codeDep) {
                const annee = await getOrthoAnnee(codeDep);
                const sel   = document.getElementById('ign-campagne-ctrl');
                const camp  = sel?.options[sel.selectedIndex]?.text || '';
                if (annee) orthoHtml = `<div style="margin-top:6px;padding-top:6px;border-top:1px solid rgba(0,0,0,.1);font-size:11px;color:#555">
                    📸 Ortho <strong>${camp}</strong> : <strong>${annee}</strong>
                </div>`;
            }
        }

        const tableRows = rows.map(([l, v]) =>
            `<tr><td style="color:#888;font-size:11px;padding:2px 8px 2px 0;white-space:nowrap">${l}</td>
             <td style="font-size:12px;font-weight:500;padding:2px 0">${v}</td></tr>`
        ).join('');

        const html = `<div style="min-width:160px">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#003189;margin-bottom:5px">${TYPE_LABEL[type] ?? type}</div>
            <table style="border-collapse:collapse;width:100%">${tableRows}</table>
            ${orthoHtml}
        </div>`;

        if (_popup) _popup.remove();
        _popup = new maplibregl.Popup({ closeButton: true, maxWidth: '260px' })
            .setLngLat(e.lngLat).setHTML(html).addTo(map);
    }

    // Enregistrement des handlers — MapLibre les mémorise même si la couche n'existe pas encore
    map.on('click', 'wfs-dept-fill',      e => handleClick(e, 'departements'));
    map.on('click', 'wfs-communes-fill',  e => handleClick(e, 'communes'));
    map.on('click', 'wfs-sections-fill',  e => handleClick(e, 'sections'));
    map.on('click', 'wfs-parcelles-fill', e => handleClick(e, 'parcelles'));

    map.on('layoutproperty', () => {
        if (_popup) { _popup.remove(); _popup = null; }
    });

    for (const id of ['wfs-dept-fill', 'wfs-communes-fill', 'wfs-sections-fill', 'wfs-parcelles-fill']) {
        map.on('mouseenter', id, () => { map.getCanvas().style.cursor = 'pointer'; });
        map.on('mouseleave', id, () => { map.getCanvas().style.cursor = '';        });
    }
}
