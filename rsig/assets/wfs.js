import { showSpinner, hideSpinner } from './utils.js';

// ── Cache millésimes ortho par département ────────────────────────────────────
const _orthoCache = {}; // campagne → { code_dep: annee_acq }

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

// Seuil minimal de déplacement pour déclencher un rechargement WFS (en degrés)
// Évite les requêtes inutiles sur les micro-pans
const MIN_DELTA = { departements: 999, communes: 0.1, sections: 0.04, parcelles: 0.01 };

export function updateWfs(map) {
    const zoom = map.getZoom();
    const type = getWfsType(zoom);
    const b    = map.getBounds();
    const bbox = `${b.getWest().toFixed(4)},${b.getSouth().toFixed(4)},${b.getEast().toFixed(4)},${b.getNorth().toFixed(4)}`;

    if (type === 'departements') {
        if (lastType === 'departements') return;
        lastBbox = null;
    } else {
        if (type === lastType && lastBbox) {
            // Comparer les centres — ne recharger que si déplacement > seuil
            const prev = lastBbox.split(',').map(Number);
            const cur  = bbox.split(',').map(Number);
            const dLng = Math.abs(((prev[0] + prev[2]) / 2) - ((cur[0] + cur[2]) / 2));
            const dLat = Math.abs(((prev[1] + prev[3]) / 2) - ((cur[1] + cur[3]) / 2));
            if (dLng < MIN_DELTA[type] && dLat < MIN_DELTA[type]) return;
        }
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

// ── Clic + hover sur les features WFS ────────────────────────────────────────
export function initWfsClick(map) {
    let _popup = null;

    map.on('click', 'wfs-fill', async (e) => {
        const f = e.features?.[0];
        if (!f) return;

        const type  = lastType;
        const props = f.properties;
        const rows  = featureProps(type, props).filter(([, v]) => v != null && v !== '');

        // Récupérer la date ortho — code_dep direct si dispo, sinon préfixe du code_insee
        let codeDep = props.code_dep ?? null;
        if (!codeDep && props.code_insee) {
            const insee = String(props.code_insee);
            codeDep = ['971','972','973','974','976'].some(d => insee.startsWith(d))
                ? insee.slice(0, 3)
                : insee.slice(0, 2);
        }
        // Pour les départements, code_dep = code_insee directement
        if (type === 'departements') codeDep = props.code_insee;

        let orthoHtml = '';
        if (codeDep) {
            const annee = await getOrthoAnnee(codeDep);
            const sel = document.getElementById('ign-campagne-ctrl');
            const camp = sel?.options[sel.selectedIndex]?.text || '';
            if (annee) orthoHtml = `<div style="margin-top:6px;padding-top:6px;border-top:1px solid rgba(0,0,0,.1);font-size:11px;color:#555">
                📸 Ortho <strong>${camp}</strong> : <strong>${annee}</strong>
            </div>`;
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
            .setLngLat(e.lngLat)
            .setHTML(html)
            .addTo(map);
    });

    map.on('mouseenter', 'wfs-fill', () => {
        map.getCanvas().style.cursor = 'pointer';
    });

    map.on('mouseleave', 'wfs-fill', () => {
        map.getCanvas().style.cursor = '';
    });
}
