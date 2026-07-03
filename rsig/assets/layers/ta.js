import { showSpinner, hideSpinner, stepExpr, computeBreaks, PAL, bddOnTop, apiFetch } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

const DEPT_ZOOM = 9;
let active    = false;
let abortCtrl = null;
let loadId    = 0;
let deptCache = { fc: null, annee: null };

function getOptions() {
    return {
        champ:    document.getElementById('ta-champ')?.value           || 'ta_estime_log',
        annee:    document.getElementById('ta-annee')?.value           || '2025',
        mode:     document.getElementById('ta-mode')?.value            || 'union',
        milUnion: document.getElementById('ta-millesime-union')?.value || '',
    };
}

function bboxParam(map) {
    const b = map.getBounds();
    return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
}

function upsert(map, fc, prop) {
    const isEstime = prop.includes('estime');
    const values = fc.features.map(f => +f.properties[prop]).filter(v => isFinite(v) && v > 0);
    if (!values.length) return;
    const breaks = computeBreaks(values, 5);
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
    const { champ, annee, mode, milUnion } = getOptions();
    const zoom  = map.getZoom();
    const myId  = ++loadId;

    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();
    showSpinner();

    if (zoom < DEPT_ZOOM) {
        // Departements — cache par annee (on est forcément à zoom dept ici, pas commune)
        if (deptCache.fc && deptCache.annee === annee) {
            hideSpinner();
            upsert(map, deptCache.fc, propForZoom(champ, true));
            return;
        }
        apiFetch(`/api/ta/departements?annee=${annee}`, { signal: abortCtrl.signal })
            .then(r => r.json())
            .then(fc => {
                hideSpinner();
                if (myId !== loadId) return;
                deptCache = { fc, annee };
                upsert(map, fc, propForZoom(champ, true));
            })
            .catch(e => { hideSpinner(); });
    } else {
        const milParam = milUnion ? `&millesime=${milUnion}` : '';
        const url = mode === 'union'
            ? `/api/ta/union?bbox=${bboxParam(map)}&annee=${annee}${milParam}`
            : `/api/ta?bbox=${bboxParam(map)}&annee=${annee}`;
        apiFetch(url, { signal: abortCtrl.signal })
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
            .catch(e => { hideSpinner(); });
    }
}

export function initTa(map) {
    const toggle   = document.getElementById('toggle-ta');
    const options  = document.getElementById('ta-options');
    const champSel = document.getElementById('ta-champ');
    const anneeSel = document.getElementById('ta-annee');
    const milUnionSel = document.getElementById('ta-millesime-union');

    // Peupler sélecteur millésimes zones majorées
    if (milUnionSel) {
        fetch('/api/ta/majore/millesimes').then(r => r.json()).then(mils => {
            const opt0 = document.createElement('option');
            opt0.value = ''; opt0.textContent = 'Dernier en vigueur';
            milUnionSel.appendChild(opt0);
            mils.forEach(m => {
                const opt = document.createElement('option');
                opt.value = m; opt.textContent = m;
                milUnionSel.appendChild(opt);
            });
        }).catch(e => console.warn('[ta] millesimes', e));
        milUnionSel.addEventListener('change', () => { if (active) loadTa(map); });
    }

    map.on('mouseenter', 'ta-fill', () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'ta-fill', () => map.getCanvas().style.cursor = '');

    map.on('click', 'ta-fill', e => {
        if (!active) return;
        const p = e.features[0].properties;
        const { annee } = getOptions();
        const isDepZoom = map.getZoom() < DEPT_ZOOM;
        const fmtP = v => (v != null && v !== '' && +v !== 0) ? (+v).toFixed(3) + ' %' : null;
        const fmtE = v => (v != null && v !== '') ? (+v).toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €/m²' : null;
        const fmtV = v => (v != null && v !== '') ? (+v).toLocaleString('fr-FR') + ' €' : null;

        if (isDepZoom) {
            showInfo('ta', `TA — ${p.nom_dep} (${p.code_dep})`,
                irow('Taux communal moyen', fmtP(p.taux_com_moyen)) +
                irow('Taux départemental',  fmtP(p.taux_dep)) +
                irow('Taux régional IDF',   fmtP(p.taux_reg)) +
                irow('Taux total moyen',    fmtP(p.taux_total_moyen)) +
                irow('TA estimée logement', fmtE(p.ta_estime_log)) +
                irow('TA estimée autres',   fmtE(p.ta_estime_aut))
            );
        } else {
            const typeZone  = p.type_zone;
            const typeLabel = typeZone === 'section'  ? ' — Section majorée' :
                              typeZone === 'parcelle' ? ' — Parcelle majorée' : '';
            // Référence zone majorée dans le titre si applicable
            const zoneInTitle = p.section
                ? ` · Sect. ${p.section}${p.parcelle ? ' / Parc. '+p.parcelle : ''}`
                : '';

            const EXO_LABELS = {
                exo_habitation:'Locaux habitation', exo_pret_ptx:'Logements PTZ',
                exo_industriel:'Locaux industriels/artisanaux', exo_commerce:'Commerces de détail',
                exo_immeubles_classes:'Immeubles classés', exo_abris_jardin:'Abris de jardin',
                exo_maisons_sante:'Maisons de santé', exo_terrains_rehab:'Terrains réhabilités',
                exo_transf_habitation:'Transf. en habitation',
            };
            const exoRows = Object.entries(EXO_LABELS)
                .filter(([k]) => p[k] != null && p[k] !== '')
                .map(([k, lbl]) => irow(lbl, (+p[k]).toFixed(0) + " % d'exo.")).join('');

            showInfo('ta', `TA — ${p.libcom || p.code_insee}${typeLabel}${zoneInTitle}`,
                irow('TA estimée logement', fmtE(p.ta_estime_log)) +
                irow('TA estimée autres',   fmtE(p.ta_estime_aut)) +
                irow(typeZone === 'commune' ? 'Taux communal/EPCI' : 'Taux zone majorée', fmtP(p.taux_com)) +
                irow('Taux départemental',  fmtP(p.taux_dep)) +
                irow('Taux régional IDF',   fmtP(p.taux_reg)) +
                irow('Taux total',          fmtP(p.taux_total)) +
                irow('Forfait stationnement', fmtV(p.val_forfait_station)) +
                irow('Date délibération', p.date_effet || null) +
                (exoRows ? '<div class="info-section-sep">Exonérations votées</div><div class="info-section">' + exoRows + '</div>' : '') +
                (p.libcom ? `<div class="info-row" style="margin-top:6px">
                    <a href="https://www.google.fr/search?q=${encodeURIComponent('"' + p.libcom + '" délibération "taxe d\'aménagement" filetype:pdf')}" target="_blank" rel="noopener"
                       style="color:var(--blue);font-size:0.78rem">→ Délibération TA — ${p.libcom}</a>
                </div>` : '')
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
