import { showSpinner, hideSpinner, stepExpr, PAL, computeBreaks, bddOnTop, apiFetch } from '../utils.js';
import { isMeasuring } from '../measure.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow, irowHtml } from '../panel.js';

let active = false;
let loaded = false;
let loadedBounds = null;

export function loadDossiers(map) {
    if (!active) return;

    const currentBounds = map.getBounds();

    // Ne pas recharger si la vue actuelle est déjà couverte par les bounds chargées
    if (loaded && loadedBounds &&
        loadedBounds.contains(currentBounds.getNorthEast()) &&
        loadedBounds.contains(currentBounds.getSouthWest())) {
        return;
    }

    showSpinner();
    const b = currentBounds;
    const bbox = `${b.getWest().toFixed(4)},${b.getSouth().toFixed(4)},${b.getEast().toFixed(4)},${b.getNorth().toFixed(4)}`;
    apiFetch(`/api/crm/geojson?bbox=${bbox}`)
        .then(r => r.json())
        .then(fc => {
            hideSpinner();
            if (!active || !fc?.features?.length) return;

            loadedBounds = currentBounds;

            const filter = window._dossiersFilter;
            if (filter) filter.setFullData(fc);
            const displayFc = filter ? filter.applyFilters(fc) : fc;

            const tfVals = fc.features.map(f => +f.properties.montant_tf).filter(v => isFinite(v) && v > 0);
            const breaks = tfVals.length ? computeBreaks(tfVals, 5) : null;
            const color  = breaks ? stepExpr('montant_tf', breaks, PAL.tf, '#94a3b8') : '#94a3b8';

            if (!loaded) {
                map.addSource('dossiers-src', { type: 'geojson', data: displayFc, cluster: true, clusterRadius: 40 });
                map.addLayer({ id: 'dossiers-circle', type: 'circle', source: 'dossiers-src',
                    filter: ['!', ['has', 'point_count']],
                    paint: { 'circle-color': color, 'circle-radius': 7, 'circle-stroke-width': 2, 'circle-stroke-color': '#fff' } });
                map.addLayer({ id: 'dossiers-cluster', type: 'circle', source: 'dossiers-src',
                    filter: ['has', 'point_count'],
                    paint: {
                        'circle-color': '#003189',
                        'circle-radius': ['step', ['get', 'point_count'], 12, 10, 16, 50, 20],
                        'circle-stroke-width': 2, 'circle-stroke-color': 'rgba(255,255,255,.8)',
                    } });
                map.addLayer({ id: 'dossiers-cluster-count', type: 'symbol', source: 'dossiers-src',
                    filter: ['has', 'point_count'],
                    layout: { 'text-field': ['concat', ['to-string', ['get', 'point_count']], ''], 'text-font': ['Noto Sans Regular'], 'text-size': 11 },
                    paint: { 'text-color': '#fff' } });
                loaded = true;
                bddOnTop(map);
            } else {
                map.getSource('dossiers-src').setData(displayFc);
                map.setPaintProperty('dossiers-circle', 'circle-color', color);
            }

            if (breaks) saveLegend('dossiers', 'Taxe foncière (€)', breaks, PAL.tf, ' €');
        })
        .catch(() => { hideSpinner(); });
}

export function initDossiers(map) {
    const toggle = document.getElementById('toggle-dossiers');

    map.on('moveend', () => { if (active) loadDossiers(map); });

    map.on('mouseenter', 'dossiers-circle',  () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'dossiers-circle',  () => map.getCanvas().style.cursor = '');
    map.on('mouseenter', 'dossiers-cluster', () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'dossiers-cluster', () => map.getCanvas().style.cursor = '');

    map.on('click', 'dossiers-cluster', e => {
        if (isMeasuring()) return;
        const feat = map.queryRenderedFeatures(e.point, { layers: ['dossiers-cluster'] });
        if (!feat.length) return;
        map.getSource('dossiers-src').getClusterExpansionZoom(feat[0].properties.cluster_id, (err, zoom) => {
            if (!err) map.easeTo({ center: feat[0].geometry.coordinates, zoom });
        });
    });

    map.on('click', 'dossiers-circle', e => {
        if (!active || isMeasuring()) return;
        const p  = e.features[0].properties;
        const tf = +p.montant_tf;
        const etatCls = p.etat === '+' ? 'color:#16a34a' : p.etat === '-' ? 'color:#dc2626' : 'color:inherit';
        const etatFmt = p.etat ? `<span style="${etatCls};font-weight:700">${p.etat}</span>` : null;
        const html = `
            ${irow('Client', p.client_name)}
            ${irow('Ville', p.ville)}
            ${irow('Réf. client', p.rtx_code)}
            ${irow('Taxe foncière', isFinite(tf) && tf > 0 ? tf.toLocaleString('fr-FR')+' €' : null)}
            ${irow('Date remise', p.date_remise)}
            ${irowHtml('État', etatFmt)}
            ${irow('Phase', p.phase)}
            ${irow('Auditeur', p.auditeur)}`;
        const title = p.dossier || 'Dossier';
        showInfo('dossiers', title, html);
    });

    toggle.addEventListener('change', () => {
        active = toggle.checked;
        if (!active) {
            ['dossiers-cluster-count','dossiers-cluster','dossiers-circle'].forEach(id => {
                if (map.getLayer(id)) map.removeLayer(id);
            });
            if (map.getSource('dossiers-src')) map.removeSource('dossiers-src');
            loaded = false;
            loadedBounds = null;
            dropLegend('dossiers');
            clearInfo('dossiers');
            window._dossiersFilter?.reset?.();
        } else {
            loadDossiers(map);
        }
    });

    return { load: () => loadDossiers(map), isActive: () => active };
}
