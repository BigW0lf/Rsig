import { showSpinner, hideSpinner, bddOnTop, apiFetch, debounce, makeAutocomplete } from '../utils.js';
import { isMeasuring } from '../measure.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

let active = false;
let loaded = false;
let _allFeatures = [];   // cache complet pour filtrage surface + statut

// ── Couleurs par statut ───────────────────────────────────────────────────────
const STATUT_COLOR = {
    nouveau:    null,          // null = utiliser couleur évol (comportement par défaut)
    contacte:   '#3b82f6',     // bleu
    en_attente: '#8b5cf6',     // violet
    annule:     '#94a3b8',     // gris
    client:     '#16a34a',     // vert
};

const STATUT_LABEL = {
    nouveau:    'Nouveau',
    contacte:   'Contacté',
    en_attente: 'En attente',
    annule:     'Annulé',
    client:     'Client',
};

const COLOR_HIGH = '#dc2626';
const COLOR_MED  = '#f97316';
const COLOR_LOW  = '#eab308';

// ── Filtres actifs ─────────────────────────────────────────────────────────
let _activeStatuts  = new Set(['nouveau','contacte','en_attente','annule','client']);
let _rtaxesOnly     = false;
let _clientFilter   = '';

function _getSurfaceMin() {
    const sl = document.getElementById('prospects-surface');
    return sl ? +sl.value : 500;
}

function _applyFilter() {
    const src = map_ref?.getSource('prospects-src');
    if (!src || !_allFeatures.length) return;
    const minSurf = _getSurfaceMin();
    const clientTerm = _clientFilter.toLowerCase();
    const filtered = _allFeatures.filter(f => {
        if ((f.properties.surface_bati_m2 ?? 0) < minSurf) return false;
        if (!_activeStatuts.has(f.properties.statut ?? 'nouveau')) return false;
        if (_rtaxesOnly && !f.properties.crm_account_id) return false;
        if (clientTerm && !(f.properties.denomination ?? '').toLowerCase().includes(clientTerm)) return false;
        return true;
    });
    src.setData({ type: 'FeatureCollection', features: filtered });
}

// Met à jour le statut d'un feature dans _allFeatures (après save)
function _patchStatut(idu, statut, note) {
    _allFeatures.forEach(f => {
        if (f.properties.idu === idu) {
            f.properties.statut = statut;
            f.properties.note   = note;
        }
    });
}

function remove(map) {
    ['prospects-cluster-count','prospects-cluster','prospects-circle'].forEach(id => {
        if (map.getLayer(id)) map.removeLayer(id);
    });
    if (map.getSource('prospects-src')) map.removeSource('prospects-src');
}

let map_ref = null;

// ── Panneau clic sur un point ─────────────────────────────────────────────────
function showProspectPanel(p) {
    const evolCls = +p.evol_pct >= 10 ? 'tag-up' : '';
    const statutSel = Object.entries(STATUT_LABEL).map(([v, l]) =>
        `<option value="${v}"${p.statut === v ? ' selected' : ''}>${l}</option>`
    ).join('');

    const crmBadge = p.crm_account_id
        ? `<a class="prospect-crm-badge" href="/client/${encodeURIComponent(p.crm_account_id)}" target="_blank" rel="noopener">
               ★ Client RTaxes — ${p.crm_client_name || ''}
           </a>`
        : '';

    const html =
        (crmBadge ? `<div class="prospect-crm-row">${crmBadge}</div>` : '') +
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
        `<div class="prospect-statut-block" id="pstat-${p.idu}">
            <div class="prospect-statut-row">
                <label class="prospect-statut-label">État commercial</label>
                <select class="prospect-statut-sel" data-idu="${p.idu}">
                    ${statutSel}
                </select>
            </div>
            <textarea class="prospect-note-area" data-idu="${p.idu}" placeholder="Note…">${p.note || ''}</textarea>
            <div style="display:flex;gap:6px;align-items:center;margin-top:4px">
                <button class="prospect-save-btn" data-idu="${p.idu}">Enregistrer</button>
                <span class="prospect-save-msg" id="psave-msg-${p.idu}" style="display:none;font-size:11px;color:var(--green)">✓ Sauvegardé</span>
            </div>
        </div>
        <div id="prospects-occ-zone" style="margin-top:8px">
            <div style="font-size:11px;color:var(--text3)">Chargement des occupants…</div>
        </div>`;

    showInfo('prospects', `Prospect — ${p.denomination}`, html);

    // Save handler
    const block = document.getElementById(`pstat-${p.idu}`);
    if (block) {
        block.querySelector('.prospect-save-btn').addEventListener('click', () => {
            const sel  = block.querySelector('.prospect-statut-sel');
            const note = block.querySelector('.prospect-note-area');
            const msg  = document.getElementById(`psave-msg-${p.idu}`);
            const newStatut = sel.value;
            const newNote   = note.value;
            fetch('/api/prospects/statut', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ idu: p.idu, statut: newStatut, note: newNote }),
            })
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    _patchStatut(p.idu, newStatut, newNote);
                    _applyFilter();
                    if (msg) { msg.style.display = 'inline'; setTimeout(() => msg.style.display = 'none', 2500); }
                }
            })
            .catch(() => {});
        });
    }

    // Occupants
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
}

// ── Légende avec statuts ───────────────────────────────────────────────────────
function _saveLegendStatut() {
    saveLegend(
        'prospects',
        'Prospects – état commercial',
        ['Nouveau (↑ évol)', 'Contacté', 'En attente', 'Annulé', 'Client'],
        [COLOR_HIGH, STATUT_COLOR.contacte, STATUT_COLOR.en_attente, STATUT_COLOR.annule, STATUT_COLOR.client]
    );
}

export function initProspects(map) {
    map_ref = map;
    const toggle = document.getElementById('toggle-prospects');
    if (!toggle) return;

    // Slider surface
    const slider  = document.getElementById('prospects-surface');
    const valSpan = document.getElementById('prospects-surface-val');
    if (slider) {
        const applyFilterDebounced = debounce(_applyFilter, 120);
        slider.addEventListener('input', () => {
            const v = +slider.value;
            if (valSpan) valSpan.textContent = v.toLocaleString('fr-FR') + ' m²';
            applyFilterDebounced();
        });
    }

    // Filtre client / dénomination avec autocomplete
    const clientInput = document.getElementById('prospects-client-filter');
    if (clientInput) {
        const applyClientDebounced = debounce(() => {
            _clientFilter = clientInput.value.trim();
            _applyFilter();
        }, 120);
        clientInput.addEventListener('input', applyClientDebounced);

        makeAutocomplete(clientInput, (term) => {
            if (!_allFeatures.length) return [];
            const low = term.toLowerCase();
            const seen = new Set();
            const results = [];
            for (const f of _allFeatures) {
                const name = f.properties.denomination ?? '';
                if (name && name.toLowerCase().includes(low) && !seen.has(name)) {
                    seen.add(name);
                    results.push(name);
                    if (results.length >= 8) break;
                }
            }
            return results;
        });
    }

    // Bouton "Clients RTaxes uniquement"
    const rtaxesBtn = document.getElementById('prospects-rtaxes-only');
    if (rtaxesBtn) {
        rtaxesBtn.addEventListener('click', () => {
            _rtaxesOnly = !_rtaxesOnly;
            rtaxesBtn.dataset.active = _rtaxesOnly ? '1' : '0';
            _applyFilter();
        });
    }

    // Checkboxes statut dans les options
    document.querySelectorAll('.prospect-statut-filter').forEach(cb => {
        cb.addEventListener('change', () => {
            _activeStatuts = new Set(
                [...document.querySelectorAll('.prospect-statut-filter:checked')].map(c => c.value)
            );
            _applyFilter();
        });
    });

    // ── Handlers carte ───────────────────────────────────────────────────────
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
        showProspectPanel(f.properties);
    });

    // ── Toggle ON/OFF ────────────────────────────────────────────────────────
    toggle.addEventListener('change', () => {
        active = toggle.checked;
        if (!active) {
            remove(map);
            loaded = false;
            _allFeatures = [];
            _rtaxesOnly  = false;
            _clientFilter = '';
            const clientInput = document.getElementById('prospects-client-filter');
            if (clientInput) clientInput.value = '';
            const btn = document.getElementById('prospects-rtaxes-only');
            if (btn) btn.dataset.active = '0';
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

                _allFeatures = fc.features;
                const minSurf = _getSurfaceMin();
                const initialData = {
                    type: 'FeatureCollection',
                    features: _allFeatures.filter(f =>
                        (f.properties.surface_bati_m2 ?? 0) >= minSurf &&
                        _activeStatuts.has(f.properties.statut ?? 'nouveau')
                    ),
                };

                map.addSource('prospects-src', {
                    type: 'geojson', data: initialData,
                    cluster: true, clusterRadius: 40, clusterMaxZoom: 13,
                });

                // Couleur = statut d'abord, sinon évolution
                map.addLayer({ id: 'prospects-circle', type: 'circle', source: 'prospects-src',
                    filter: ['!', ['has', 'point_count']],
                    paint: {
                        'circle-color': ['case',
                            ['==', ['get', 'statut'], 'contacte'],   STATUT_COLOR.contacte,
                            ['==', ['get', 'statut'], 'en_attente'], STATUT_COLOR.en_attente,
                            ['==', ['get', 'statut'], 'annule'],     STATUT_COLOR.annule,
                            ['==', ['get', 'statut'], 'client'],     STATUT_COLOR.client,
                            // nouveau → couleur évolution
                            ['>=', ['to-number', ['get', 'evol_pct']], 20], COLOR_HIGH,
                            ['>=', ['to-number', ['get', 'evol_pct']], 10], COLOR_MED,
                            COLOR_LOW,
                        ],
                        'circle-radius': ['case',
                            ['==', ['get', 'statut'], 'client'], 10,
                            8,
                        ],
                        'circle-stroke-width': ['case',
                            ['==', ['get', 'statut'], 'annule'], 1,
                            2,
                        ],
                        'circle-stroke-color': '#fff',
                        'circle-opacity': ['case',
                            ['==', ['get', 'statut'], 'annule'], 0.45,
                            1,
                        ],
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
                _saveLegendStatut();
                bddOnTop(map);
            })
            .catch(() => hideSpinner());
    });

    return { isActive: () => active };
}
