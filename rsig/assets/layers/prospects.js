import { showSpinner, hideSpinner, bddOnTop, apiFetch } from '../utils.js';
import { isMeasuring } from '../measure.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

let active = false;
let loaded = false;

const COLOR_HIGH  = '#dc2626'; // évol >= 20%
const COLOR_MED   = '#f97316'; // évol 10-20%
const COLOR_LOW   = '#eab308'; // évol > 0-10%

function evolColor(evol) {
    if (evol >= 20) return COLOR_HIGH;
    if (evol >= 10) return COLOR_MED;
    return COLOR_LOW;
}

function remove(map) {
    ['prospects-cluster-count','prospects-cluster','prospects-circle'].forEach(id => {
        if (map.getLayer(id)) map.removeLayer(id);
    });
    if (map.getSource('prospects-src')) map.removeSource('prospects-src');
}

export function initProspects(map) {
    const toggle = document.getElementById('toggle-prospects');
    if (!toggle) return;

    // ── Handlers enregistrés une seule fois ──────────────────────────────
    map.on('mouseenter', 'prospects-circle',  () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'prospects-circle',  () => map.getCanvas().style.cursor = '');
    map.on('mouseenter', 'prospects-cluster', () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'prospects-cluster', () => map.getCanvas().style.cursor = '');

    map.on('click', 'prospects-cluster', e => {
        if (!active || isMeasuring()) return;
        const feat = map.queryRenderedFeatures(e.point, { layers: ['prospects-cluster'] });
        if (!feat.length) return;
        map.getSource('prospects-src').getClusterExpansionZoom(feat[0].properties.cluster_id, (err, zoom) => {
            if (!err) map.easeTo({ center: feat[0].geometry.coordinates, zoom });
        });
    });

    map.on('click', 'prospects-circle', e => {
        if (!active || isMeasuring()) return;
        const f = e.features?.[0];
        if (!f) return;
        const p = f.properties;
        const evolCls = +p.evol_pct >= 10 ? 'tag-up' : '';

        const baseHtml =
            irow('Dénomination',  p.denomination) +
            irow('SIREN',         p.numero_siren) +
            irow('Forme jur.',    p.forme_juridique_abregee) +
            irow('Adresse',       p.adresse) +
            irow('INSEE',         p.code_insee) +
            irow('Parcelle',      `${p.section} ${p.parcelle}`) +
            `<div class="info-row">
                <span class="info-label">Coeff 2017 → 2024</span>
                <span class="info-value ${evolCls}">${p.coeff_2017} → ${p.coeff_2024} <strong>(+${p.evol_pct}%)</strong></span>
            </div>` +
            irow('Surface bâtie', p.surface_bati_m2 ? p.surface_bati_m2 + ' m²' : null) +
            irow('Usage IGN',     p.usages) +
            `<div id="prospects-occ-zone" style="margin-top:8px">
                <div style="font-size:11px;color:var(--text3)">Chargement des occupants…</div>
            </div>`;

        showInfo('prospects', `Prospect — ${p.denomination}`, baseHtml);

        fetch(`/api/prospects/occupants?idu=${encodeURIComponent(p.idu)}`)
            .then(r => r.ok ? r.json() : [])
            .then(rows => {
                const zone = document.getElementById('prospects-occ-zone');
                if (!zone) return;
                if (!rows.length) {
                    zone.innerHTML = `<div style="font-size:11px;color:var(--text3);font-style:italic">Aucun occupant SIRENE géolocalisé sur cette parcelle</div>`;
                    return;
                }
                const items = rows.map(r => {
                    const eff = r.effectifs ? ` · ${r.effectifs} sal.` : '';
                    return `<div class="prospect-occ-row">
                        <div class="prospect-occ-nom">${r.nom || '–'}</div>
                        <div class="prospect-occ-meta">${r.naf || ''}${eff}</div>
                        <div class="prospect-occ-addr">${[r.adresse, r.cp, r.ville].filter(Boolean).join(', ')}</div>
                        <div class="prospect-occ-siret" style="font-size:10px;color:var(--text3)">SIRET ${r.siret}</div>
                    </div>`;
                }).join('');
                zone.innerHTML = `<details open>
                    <summary style="cursor:pointer;font-weight:600;font-size:11px;margin-bottom:4px">
                        Occupants SIRENE (${rows.length})
                    </summary>
                    ${items}
                </details>`;
            })
            .catch(() => {
                const zone = document.getElementById('prospects-occ-zone');
                if (zone) zone.remove();
            });
    });

    // ── Toggle ON/OFF ─────────────────────────────────────────────────────
    toggle.addEventListener('change', () => {
        active = toggle.checked;
        if (!active) {
            remove(map);
            loaded = false;
            dropLegend('prospects');
            clearInfo('prospects');
            return;
        }
        if (loaded) return;
        showSpinner();
        apiFetch('/api/prospects')
            .then(r => r.json())
            .then(fc => {
                hideSpinner();
                if (!active || !fc?.features?.length) return;

                map.addSource('prospects-src', {
                    type: 'geojson', data: fc,
                    cluster: true, clusterRadius: 40, clusterMaxZoom: 13,
                });

                map.addLayer({ id: 'prospects-circle', type: 'circle', source: 'prospects-src',
                    filter: ['!', ['has', 'point_count']],
                    paint: {
                        'circle-color': ['case',
                            ['>=', ['to-number', ['get', 'evol_pct']], 20], COLOR_HIGH,
                            ['>=', ['to-number', ['get', 'evol_pct']], 10], COLOR_MED,
                            COLOR_LOW,
                        ],
                        'circle-radius': 8,
                        'circle-stroke-width': 2,
                        'circle-stroke-color': '#fff',
                    }
                });

                map.addLayer({ id: 'prospects-cluster', type: 'circle', source: 'prospects-src',
                    filter: ['has', 'point_count'],
                    paint: {
                        'circle-color': '#dc2626',
                        'circle-radius': ['step', ['get', 'point_count'], 12, 10, 16, 50, 21],
                        'circle-stroke-width': 2, 'circle-stroke-color': 'rgba(255,255,255,.8)',
                    }
                });

                map.addLayer({ id: 'prospects-cluster-count', type: 'symbol', source: 'prospects-src',
                    filter: ['has', 'point_count'],
                    layout: { 'text-field': '{point_count_abbreviated}', 'text-font': ['Noto Sans Regular'], 'text-size': 11 },
                    paint: { 'text-color': '#fff' }
                });

                loaded = true;
                saveLegend(
                    'prospects',
                    'Prospects – évol. coeff. loc.',
                    ['> 0 – 10 %', '10 – 20 %', '≥ 20 %'],
                    [COLOR_LOW, COLOR_MED, COLOR_HIGH]
                );
                bddOnTop(map);
            })
            .catch(() => hideSpinner());
    });

    return { isActive: () => active };
}
