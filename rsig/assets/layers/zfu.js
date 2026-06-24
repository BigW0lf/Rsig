import { showSpinner, hideSpinner, bddOnTop, apiFetch } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

let active = false;
let loaded = false;

const COLOR_FILL   = '#f59e0b';
const COLOR_STROKE = '#b45309';

export function loadZfu(map) {
    if (!active || loaded) return;
    showSpinner();
    apiFetch('/api/zfu')
        .then(r => r.json())
        .then(fc => {
            hideSpinner();
            if (!active || !fc?.features?.length) return;

            map.addSource('zfu-src', { type: 'geojson', data: fc });
            map.addLayer({ id: 'zfu-fill', type: 'fill', source: 'zfu-src',
                paint: { 'fill-color': COLOR_FILL, 'fill-opacity': 0.25 } });
            map.addLayer({ id: 'zfu-line', type: 'line', source: 'zfu-src',
                paint: { 'line-color': COLOR_STROKE, 'line-width': 1.5, 'line-dasharray': [4, 2] } });

            loaded = true;
            bddOnTop(map);
            saveLegend('zfu', 'ZFU — Exo. TSB', ['ZFU — Zone Franche Urbaine'], [COLOR_FILL], '');

            map.on('mouseenter', 'zfu-fill', () => map.getCanvas().style.cursor = 'pointer');
            map.on('mouseleave', 'zfu-fill', () => map.getCanvas().style.cursor = '');
        })
        .catch(e => { hideSpinner(); });
}

export function initZfu(map) {
    const toggle = document.getElementById('toggle-zfu');

    map.on('click', 'zfu-fill', e => {
        if (!active) return;
        const p = e.features[0].properties;
        showInfo('zfu', `ZFU — ${p.nom_quartier}`,
            irow('Code quartier', p.codquart) +
            irow('Communes', p.communes) +
            irow('Dispositif', 'Exonération de Taxe sur les Salaires Bruts')
        );
    });

    toggle.addEventListener('change', () => {
        active = toggle.checked;
        if (!active) {
            ['zfu-fill','zfu-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
            if (map.getSource('zfu-src')) map.removeSource('zfu-src');
            loaded = false;
            dropLegend('zfu');
            clearInfo('zfu');
        } else {
            loadZfu(map);
        }
    });

    return { isActive: () => active };
}
