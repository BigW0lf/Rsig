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

// ── IGN TMS raster ────────────────────────────────────────────────────────────
// Cadastral Express PNG — communes (z9-14), sections+parcelles (z14+)
const IGN_CADASTRAL_TMS = 'https://data.geopf.fr/tms/1.0.0/CADASTRALPARCELS.PARCELLAIRE_EXPRESS/{z}/{x}/{y}.png';
const IGN_LIMITES_TMS   = 'https://data.geopf.fr/tms/1.0.0/LIMITES_ADMINISTRATIVES_EXPRESS.LATEST/{z}/{x}/{y}.png';

// Pour le clic WFS (info popup uniquement, pas de rendu vectoriel)
const typeNames = {
    communes:  'CADASTRALPARCELS.PARCELLAIRE_EXPRESS:commune',
    sections:  'CADASTRALPARCELS.PARCELLAIRE_EXPRESS:feuille',
    parcelles: 'CADASTRALPARCELS.PARCELLAIRE_EXPRESS:parcelle',
};

export function getWfsType(zoom) {
    if (zoom < 9)  return 'departements';
    if (zoom < 13) return 'communes';
    if (zoom < 15) return 'sections';
    return 'parcelles';
}

// ── Initialisation (une seule fois) ──────────────────────────────────────────
let _initialized = false;

export function updateWfs(map) {
    if (_initialized) return;
    _initialized = true;

    // 1. Limites admin (depts/communes) en raster jusqu'à z13
    map.addSource('ign-limites', {
        type: 'raster',
        tiles: [IGN_LIMITES_TMS],
        tileSize: 256,
        minzoom: 6,
        maxzoom: 13,
        attribution: '© IGN',
    });
    map.addLayer({ id: 'wfs-limites-raster', type: 'raster', source: 'ign-limites',
        maxzoom: 13,
        paint: { 'raster-opacity': 0.6 },
    });

    // 2. Parcellaire (sections + parcelles) en raster à partir de z13
    map.addSource('ign-cadastral', {
        type: 'raster',
        tiles: [IGN_CADASTRAL_TMS],
        tileSize: 256,
        minzoom: 13,
        maxzoom: 20,
        attribution: '© IGN — Parcellaire Express',
    });
    map.addLayer({ id: 'wfs-cadastral-raster', type: 'raster', source: 'ign-cadastral',
        minzoom: 13,
        paint: { 'raster-opacity': 0.7 },
    });

    // 3. Départements : GeoJSON proxy local (cache 24h, ~200 KB)
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

// ── Clic carte → requête WFS ponctuelle IGN (info popup) ─────────────────────
// Les couches cadastrales sont des tuiles raster — pas de queryRenderedFeatures.
// On fait une requête WFS GetFeature avec BBOX réduite au point cliqué.
export function initWfsClick(map) {
    let _popup = null;
    let _ctrl  = null;

    map.on('click', async (e) => {
        if (isMeasuring()) return;
        if (hasVisibleLayer()) return;
        if (map.queryRenderedFeatures(e.point, { layers: ['osm-point'] }).length) return;

        const zoom = map.getZoom();
        const type = getWfsType(zoom);

        // Départements : GeoJSON local cliquable
        if (type === 'departements') {
            const feats = map.queryRenderedFeatures(e.point, { layers: ['wfs-dept-fill'] });
            if (!feats.length) return;
            await _showPopup(e.lngLat, 'departements', feats[0].properties);
            return;
        }

        // Communes / sections / parcelles : WFS ponctuel IGN
        if (_ctrl) _ctrl.abort();
        _ctrl = new AbortController();
        const lng = e.lngLat.lng;
        const lat = e.lngLat.lat;
        const tol = type === 'parcelles' ? 0.00005 : 0.001;
        const bbox = `${lng - tol},${lat - tol},${lng + tol},${lat + tol}`;
        try {
            const url = 'https://data.geopf.fr/wfs/ows?' + new URLSearchParams({
                SERVICE: 'WFS', VERSION: '2.0.0', REQUEST: 'GetFeature',
                TYPENAMES: typeNames[type], SRSNAME: 'EPSG:4326',
                BBOX: bbox + ',EPSG:4326',
                OUTPUTFORMAT: 'application/json', COUNT: 1,
            });
            const r = await fetch(url, { signal: _ctrl.signal });
            if (!r.ok) return;
            const data = await r.json();
            const f = data?.features?.[0];
            if (!f) return;
            await _showPopup(e.lngLat, type, f.properties);
        } catch (err) {
            if (err.name !== 'AbortError') console.warn('[wfs click]', err.message);
        }
    });

    map.on('layoutproperty', () => {
        if (_popup) { _popup.remove(); _popup = null; }
    });

    async function _showPopup(lngLat, type, props) {
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
            .setLngLat(lngLat).setHTML(html).addTo(map);
    }
}
