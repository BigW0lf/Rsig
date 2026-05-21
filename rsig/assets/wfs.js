import { showSpinner, hideSpinner } from './utils.js';

const typeNames = {
    departements: 'LIMITES_ADMINISTRATIVES_EXPRESS.LATEST:departement',
    communes:     'CADASTRALPARCELS.PARCELLAIRE_EXPRESS:commune',
    sections:     'CADASTRALPARCELS.PARCELLAIRE_EXPRESS:feuille',
    parcelles:    'CADASTRALPARCELS.PARCELLAIRE_EXPRESS:parcelle',
};
const maxFeat = { departements: 150, communes: 500, sections: 400, parcelles: 600 };
const style   = {
    departements: { fill: '#003189', opacity: 0.05, line: '#003189', lw: 1   },
    communes:     { fill: '#7a8fbb', opacity: 0.04, line: '#7a8fbb', lw: 0.5 },
    sections:     { fill: '#ede9fe', opacity: 0.08, line: '#5b21b6', lw: 1   },
    parcelles:    { fill: '#fef3c7', opacity: 0.10, line: '#b45309', lw: 1   },
};
const labelField = {
    sections:  ['get', 'section'],
    parcelles: ['get', 'numero'],
};

let ctrl     = null;
let lastType = null;
let lastBbox = null;

export function getWfsType(zoom) {
    if (zoom < 9)  return 'departements';
    if (zoom < 13) return 'communes';
    if (zoom < 15) return 'sections';
    return 'parcelles';
}

export function updateWfs(map) {
    const zoom = map.getZoom();
    const type = getWfsType(zoom);
    const b    = map.getBounds();
    const bbox = `${b.getWest().toFixed(4)},${b.getSouth().toFixed(4)},${b.getEast().toFixed(4)},${b.getNorth().toFixed(4)}`;

    if (type === 'departements') {
        if (lastType === 'departements') return;
        lastBbox = null;
    } else {
        if (type === lastType && bbox === lastBbox) return;
    }
    lastType = type;
    lastBbox = bbox;

    if (ctrl) ctrl.abort();
    ctrl = new AbortController();
    showSpinner();

    const params = new URLSearchParams({
        SERVICE: 'WFS', VERSION: '2.0.0', REQUEST: 'GetFeature',
        TYPENAMES: typeNames[type], SRSNAME: 'EPSG:4326',
        BBOX: type === 'departements' ? '-5.14,41.33,9.56,51.09,EPSG:4326' : bbox + ',EPSG:4326',
        OUTPUTFORMAT: 'application/json', COUNT: maxFeat[type],
    });

    fetch('https://data.geopf.fr/wfs/ows?' + params, { signal: ctrl.signal })
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

        const s = style[type];
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
            map.addLayer({ id: 'wfs-labels', type: 'symbol', source: 'wfs-src',
                layout: {
                    'text-field': ['literal', ''], 'text-size': 10,
                    'text-font': ['Noto Sans Regular'], 'text-max-width': 4,
                    'text-allow-overlap': false,
                },
                paint: { 'text-color': '#1a2332', 'text-halo-color': '#ffffff', 'text-halo-width': 1.5, 'text-opacity': 0.8 }
            });
        }
        const lf = labelField[type] ?? null;
        map.setLayoutProperty('wfs-labels', 'visibility', lf ? 'visible' : 'none');
        if (lf) map.setLayoutProperty('wfs-labels', 'text-field', lf);

        hideSpinner();
    })
    .catch(err => { hideSpinner(); if (err.name !== 'AbortError') console.error('WFS:', err); });
}
