import { showSpinner, hideSpinner, stepExpr, PAL, computeBreaks, bddOnTop } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

let active = false;
let loaded = false;

export function loadDossiers(map) {
    if (!active || loaded) return;
    showSpinner();
    fetch('/api/crm/geojson')
        .then(r => r.json())
        .then(fc => {
            hideSpinner();
            if (!active || !fc?.features?.length) return;

            // Enregistrer le dataset complet dans le filtre si disponible
            const filter = window._dossiersFilter;
            if (filter) filter.setFullData(fc);

            // Appliquer les filtres éventuellement déjà posés avant le chargement
            const displayFc = filter ? filter.applyFilters(fc) : fc;

            const tfVals = fc.features.map(f => +f.properties.apo_montanttaxefonciere).filter(v => isFinite(v) && v > 0);
            const breaks = computeBreaks(tfVals, 5);
            const color  = stepExpr('apo_montanttaxefonciere', breaks, PAL.tf, '#94a3b8');

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
                layout: { 'text-field': '{point_count_abbreviated}', 'text-font': ['Noto Sans Regular'], 'text-size': 11 },
                paint: { 'text-color': '#fff' } });

            loaded = true;
            bddOnTop(map);
            saveLegend('dossiers', 'Taxe foncière', breaks, PAL.tf, ' €');

            map.on('mouseenter', 'dossiers-circle',  () => map.getCanvas().style.cursor = 'pointer');
            map.on('mouseleave', 'dossiers-circle',  () => map.getCanvas().style.cursor = '');
            map.on('mouseenter', 'dossiers-cluster', () => map.getCanvas().style.cursor = 'pointer');
            map.on('mouseleave', 'dossiers-cluster', () => map.getCanvas().style.cursor = '');

            map.on('click', 'dossiers-cluster', e => {
                const feat = map.queryRenderedFeatures(e.point, { layers: ['dossiers-cluster'] });
                map.getSource('dossiers-src').getClusterExpansionZoom(feat[0].properties.cluster_id, (err, zoom) => {
                    if (!err) map.easeTo({ center: feat[0].geometry.coordinates, zoom });
                });
            });
        })
        .catch(e => { hideSpinner(); console.error('dossiers', e); });
}

export function initDossiers(map) {
    const toggle = document.getElementById('toggle-dossiers');

    map.on('click', 'dossiers-circle', e => {
        const p  = e.features[0].properties;
        const tf = +p.apo_montanttaxefonciere;
        const html = `
            ${irow('Dossier', p.dossier)}
            ${irow('Client', p.name)}
            ${irow('Réf. client', p.rtx_code)}
            ${irow('Adresse', p.adresse_complete)}
            ${irow('Ville', p.ville)}
            ${irow('Taxe foncière', isFinite(tf) && tf > 0 ? tf.toLocaleString('fr-FR')+' €' : '–')}
            ${irow('Section', p.section)}
            ${irow('Parcelle', p.parcelle)}
            ${irow('INSEE', p.insee)}
            ${irow('Date demande', p.date_demande)}
            ${irow('Date remise', p.date_remise)}`;
        const loc = [p.ville, p.insee].filter(Boolean).join(' — ');
        const title = loc ? `${loc} · Dossier ${p.dossier}` : `Dossier ${p.dossier}`;
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
            dropLegend('dossiers');
            clearInfo('dossiers');
        } else {
            loadDossiers(map);
        }
    });

    return { isActive: () => active };
}
