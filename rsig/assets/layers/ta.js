import { showSpinner, hideSpinner, stepExpr, computeBreaks, PAL, bddOnTop } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

const DEPT_ZOOM = 9;
let active    = false;
let abortCtrl = null;
let loadId    = 0;
let deptCache = { fc: null, annee: null };

// Breaks globaux précalculés (non adaptatifs) — chargés une fois
let GLOBAL_BREAKS = null;
const breaksReady = fetch('/api/ta/stats')
    .then(r => r.json())
    .then(d => { GLOBAL_BREAKS = d; })
    .catch(() => {});

function getOptions() {
    return {
        champ: document.getElementById('ta-champ')?.value || 'ta_estime_log',
        annee: document.getElementById('ta-annee')?.value || '2025',
        mode:  document.getElementById('ta-mode')?.value  || 'union',
    };
}

function bboxParam(map) {
    const b = map.getBounds();
    return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
}

function upsert(map, fc, prop) {
    const values = fc.features.map(f => +f.properties[prop]).filter(v => isFinite(v) && v > 0);
    if (!values.length) return;
    // Breaks globaux (non adaptatifs) pour cohérence entre zooms et modes
    const isEstime = prop.includes('estime');
    const breaks = GLOBAL_BREAKS
        ? (isEstime ? GLOBAL_BREAKS.estime : GLOBAL_BREAKS.taux_total)
        : computeBreaks(values, 5);   // fallback si stats pas encore chargées
    const color  = stepExpr(prop, breaks, PAL.cfe);

    if (map.getLayer('ta-fill')) {
        map.getSource('ta-src').setData(fc);
        map.setPaintProperty('ta-fill', 'fill-color', color);
    } else {
        if (map.getSource('ta-src')) {
            ['ta-fill','ta-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
            map.removeSource('ta-src');
        }
        map.addSource('ta-src', { type: 'geojson', data: fc });
        map.addLayer({ id: 'ta-fill', type: 'fill', source: 'ta-src',
            paint: { 'fill-color': color, 'fill-opacity': 0.5 } });
        map.addLayer({ id: 'ta-line', type: 'line', source: 'ta-src',
            paint: { 'line-color': '#555', 'line-width': 0.4 } });
    }
    bddOnTop(map);

    const { annee } = getOptions();
    const champLabels = {
        ta_estime_log:  `TA estimée/m² logement (${annee})`,
        ta_estime_aut:  `TA estimée/m² autres constructions (${annee})`,
        taux_total:     'Taux total TA (%)',
        taux_total_moyen: 'Taux total moyen TA (%)',
        taux_com:       'Taux communal TA (%)',
        taux_dep:       'Taux départ. TA (%)',
        taux_reg:       'Taux régional IDF TA (%)',
    };
    const suffix = isEstime ? ' €/m²' : ' %';
    saveLegend('ta', champLabels[prop] || prop, breaks, PAL.cfe, suffix);
}

function remove(map) {
    ['ta-fill','ta-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
    if (map.getSource('ta-src')) map.removeSource('ta-src');
}

// Mapper champ affiché sur la bonne propriété selon le zoom
function propForZoom(champ, isDept) {
    if (isDept) {
        if (champ === 'ta_estime_log') return 'ta_estime_log';
        if (champ === 'ta_estime_aut') return 'ta_estime_aut';
        return 'taux_total_moyen';
    }
    return champ;
}

export function loadTa(map) {
    if (!active) return;
    const { champ, annee, mode } = getOptions();
    const zoom  = map.getZoom();
    const myId  = ++loadId;

    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();
    showSpinner();

    if (zoom < DEPT_ZOOM) {
        // Departements — cache par annee+mode (union n'est disponible qu'au zoom commune)
        if (deptCache.fc && deptCache.annee === annee && mode === 'commune') {
            hideSpinner();
            upsert(map, deptCache.fc, propForZoom(champ, true));
            return;
        }
        fetch(`/api/ta/departements?annee=${annee}`, { signal: abortCtrl.signal })
            .then(r => r.json())
            .then(fc => {
                hideSpinner();
                if (myId !== loadId) return;
                deptCache = { fc, annee };
                upsert(map, fc, propForZoom(champ, true));
            })
            .catch(e => { hideSpinner(); if (e.name !== 'AbortError') console.error('ta', e); });
    } else {
        const url = mode === 'union'
            ? `/api/ta/union?bbox=${bboxParam(map)}&annee=${annee}&millesime=2026`
            : `/api/ta?bbox=${bboxParam(map)}&annee=${annee}`;
        fetch(url, { signal: abortCtrl.signal })
            .then(r => r.json())
            .then(fc => {
                hideSpinner();
                if (myId !== loadId) return;
                if (!fc?.features?.length) {
                    if (map.getSource('ta-src')) map.getSource('ta-src').setData({ type: 'FeatureCollection', features: [] });
                    return;
                }
                upsert(map, fc, propForZoom(champ, false));
            })
            .catch(e => { hideSpinner(); if (e.name !== 'AbortError') console.error('ta', e); });
    }
}

export function initTa(map) {
    const toggle   = document.getElementById('toggle-ta');
    const options  = document.getElementById('ta-options');
    const champSel = document.getElementById('ta-champ');
    const anneeSel = document.getElementById('ta-annee');

    map.on('mouseenter', 'ta-fill', () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'ta-fill', () => map.getCanvas().style.cursor = '');

    map.on('click', 'ta-fill', e => {
        const p = e.features[0].properties;
        const { annee } = getOptions();
        const isDepZoom = map.getZoom() < DEPT_ZOOM;
        const fmt  = v => v != null && v !== '' ? (+v).toFixed(3) + ' %' : '–';
        const fmtE = v => v != null && v !== '' ? (+v).toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €/m²' : '–';

        const estimRows = (p.ta_estime_log != null || p.ta_estime_aut != null)
            ? '<div class="info-section-sep">TA estimée (forfait × taux total)</div><div class="info-section">' +
              irow('Logement', fmtE(p.ta_estime_log)) +
              irow('Autres constructions', fmtE(p.ta_estime_aut)) +
              irow('Forfait zone', p.forfait_zone || '–') +
              irow('Année forfait', p.forfait_annee || annee) +
              '</div>'
            : '';

        // Exonérations
        const EXO_LABELS = {
            exo_habitation:'Locaux habitation', exo_pret_ptx:'Logements PTZ',
            exo_industriel:'Locaux industriels/artisanaux', exo_commerce:'Commerces de détail',
            exo_immeubles_classes:'Immeubles classés', exo_abris_jardin:'Abris de jardin',
            exo_maisons_sante:'Maisons de santé', exo_terrains_rehab:'Terrains réhabilités',
            exo_transf_habitation:'Transf. en habitation',
        };
        const exoRows = Object.entries(EXO_LABELS)
            .filter(([k]) => p[k] != null && p[k] !== '')
            .map(([k, lbl]) => irow(lbl, (+p[k]).toFixed(0) + ' % d\'exo.')).join('');

        const lienDecl = `<div class="info-row" style="margin-top:6px">
            <a href="https://www.impots.gouv.fr/portail/particulier/taxe-damenagement" target="_blank"
               style="color:var(--blue);font-size:0.78rem">→ Accéder à la déclaration TA (DGFiP)</a>
        </div>`;

        if (isDepZoom) {
            showInfo('ta', `TA — ${p.nom_dep} (${p.code_dep})`,
                irow('Taux communal moyen', fmt(p.taux_com_moyen)) +
                irow('Taux départemental', fmt(p.taux_dep)) +
                irow('Taux régional IDF', fmt(p.taux_reg)) +
                irow('Taux total moyen', fmt(p.taux_total_moyen)) +
                estimRows
            );
        } else {
            const fmtV = v => v != null && v !== '' ? (+v).toLocaleString('fr-FR') + ' €' : '–';
            const typeZone = p.type_zone;
            const typeLabel = typeZone === 'section' ? ' — Section majorée' :
                              typeZone === 'parcelle' ? ' — Parcelle majorée' : '';
            const zoneRef = p.section ? irow('Zone', `Section ${p.section}${p.parcelle ? ' / Parcelle '+p.parcelle : ''}`) : '';
            showInfo('ta', `TA — ${p.libcom || p.code_insee}${typeLabel}`,
                estimRows +
                '<div class="info-section-sep">Taux</div><div class="info-section">' +
                zoneRef +
                irow(typeZone === 'commune' ? 'Taux communal/EPCI' : 'Taux zone majorée', fmt(p.taux_com)) +
                irow('Taux départemental', fmt(p.taux_dep)) +
                irow('Taux régional IDF', fmt(p.taux_reg)) +
                irow('Taux total', fmt(p.taux_total)) + '</div>' +
                (p.val_forfait_station != null ? irow('Forfait stationnement', fmtV(p.val_forfait_station)) : '') +
                irow('Date délibération', p.date_effet || '–') +
                irow('Code INSEE', p.code_insee) +
                (exoRows ? '<div class="info-section-sep">Exonérations votées</div><div class="info-section">' + exoRows + '</div>' : '') +
                lienDecl
            );
        }
    });

    toggle?.addEventListener('change', () => {
        active = toggle.checked;
        options?.classList.toggle('hidden', !active);
        if (!active) { if (abortCtrl) abortCtrl.abort(); remove(map); dropLegend('ta'); clearInfo('ta'); }
        else loadTa(map);
    });

    champSel?.addEventListener('change', () => { if (active) loadTa(map); });
    document.getElementById('ta-mode')?.addEventListener('change', () => {
        deptCache = { fc: null, annee: null };
        if (active) loadTa(map);
    });
    anneeSel?.addEventListener('change', () => {
        deptCache = { fc: null, annee: null };
        if (active) loadTa(map);
    });

    // Pas de map.on('moveend') ici — géré de façon centralisée et debouncée dans map.js

    return { load: () => loadTa(map), isActive: () => active };
}
