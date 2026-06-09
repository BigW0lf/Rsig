import { showSpinner, hideSpinner, bddOnTop } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

// Couleurs par circonscription
const PALETTE = {
    1:    { fill: '#dc2626', stroke: '#991b1b' },  // rouge vif — CBD Paris
    2:    { fill: '#f97316', stroke: '#c2410c' },  // orange — Paris + 92
    '2b': { fill: '#fde047', stroke: '#a16207' },  // jaune — 2bis DCSUCS 92
    3:    { fill: '#86efac', stroke: '#16a34a' },  // vert clair — UU Paris
    4:    { fill: '#166534', stroke: '#14532d' },  // vert foncé — reste IDF
};
const LABELS = {
    IDF: {
        1:    '1ère circ.',
        2:    '2ème circ.',
        '2b': '2ème circ. bis',
        3:    '3ème circ.',
        4:    '4ème circ.',
    },
    PACA: {
        1: 'PACA',
    },
};

let activeIdf  = false;
let activePaca = false;
let loadedIdf  = false;
let loadedPaca = false;
let ctrlIdf  = null;
let ctrlPaca = null;

// Pré-charge les millésimes disponibles
const millesimesReady = fetch('/api/tsb/millesimes').then(r => r.json()).catch(() => [2025]);

// Cache des tarifs par millésime
const tarifsCache = {};
function getTarifs(millesime) {
    if (tarifsCache[millesime]) return Promise.resolve(tarifsCache[millesime]);
    return fetch(`/api/tsb/tarifs?millesime=${millesime}`)
        .then(r => r.json())
        .then(d => { tarifsCache[millesime] = d.tarifs || []; return tarifsCache[millesime]; })
        .catch(() => []);
}

function layerIds(region) {
    const r = region.toLowerCase();
    return [`tsb-${r}-fill`, `tsb-${r}-line`];
}
function srcId(region) { return `tsb-${region.toLowerCase()}-src`; }

function remove(map, region) {
    layerIds(region).forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
    if (map.getSource(srcId(region))) map.removeSource(srcId(region));
}

function getMillesime(region) {
    const sel = document.getElementById(`tsb-${region.toLowerCase()}-millesime`);
    return sel?.value || '';
}

function loadRegion(map, region) {
    const isIdf = region === 'IDF';
    if (isIdf) { if (ctrlIdf) ctrlIdf.abort(); ctrlIdf = new AbortController(); }
    else        { if (ctrlPaca) ctrlPaca.abort(); ctrlPaca = new AbortController(); }
    const signal = isIdf ? ctrlIdf.signal : ctrlPaca.signal;
    const activeFlag = () => isIdf ? activeIdf : activePaca;

    showSpinner();
    const mil = getMillesime(region);
    fetch(`/api/tsb?region=${region}${mil ? '&millesime=' + mil : ''}`, { signal })
        .then(r => r.json())
        .then(fc => {
            hideSpinner();
            if (!activeFlag()) return;
            if (!fc?.features?.length) return;

            // Expressions MapLibre couleur par circonscription (+ 2bis pour DCSUCS dep 92)
            const circExpr = ['case',
                ['all', ['==', ['get', 'circonscription'], 2], ['boolean', ['get', 'dcsucs'], false]], PALETTE['2b'].fill,
                ['==', ['get', 'circonscription'], 1], PALETTE[1].fill,
                ['==', ['get', 'circonscription'], 2], PALETTE[2].fill,
                ['==', ['get', 'circonscription'], 3], PALETTE[3].fill,
                ['==', ['get', 'circonscription'], 4], PALETTE[4].fill,
                '#888888'
            ];
            const strokeExpr = ['case',
                ['all', ['==', ['get', 'circonscription'], 2], ['boolean', ['get', 'dcsucs'], false]], PALETTE['2b'].stroke,
                ['==', ['get', 'circonscription'], 1], PALETTE[1].stroke,
                ['==', ['get', 'circonscription'], 2], PALETTE[2].stroke,
                ['==', ['get', 'circonscription'], 3], PALETTE[3].stroke,
                ['==', ['get', 'circonscription'], 4], PALETTE[4].stroke,
                '#555555'
            ];

            const sid = srcId(region);
            map.addSource(sid, { type: 'geojson', data: fc });
            map.addLayer({
                id: `tsb-${region.toLowerCase()}-fill`, type: 'fill', source: sid,
                paint: { 'fill-color': circExpr, 'fill-opacity': 0.45 }
            });
            map.addLayer({
                id: `tsb-${region.toLowerCase()}-line`, type: 'line', source: sid,
                paint: { 'line-color': strokeExpr, 'line-width': 0.8 }
            });

            if (region === 'IDF') loadedIdf = true;
            else loadedPaca = true;

            bddOnTop(map);

            // Légende — entrées uniques par circ + 2bis si présent
            const circs = [...new Set(fc.features.map(f => +f.properties.circonscription))].sort();
            const has2bis = region === 'IDF' && fc.features.some(f => +f.properties.circonscription === 2 && f.properties.dcsucs);
            const entries = [];
            circs.forEach(c => {
                entries.push({ label: LABELS[region][c] || `Circ. ${c}`, color: PALETTE[c]?.fill || '#888' });
                if (c === 2 && has2bis)
                    entries.push({ label: LABELS[region]['2b'], color: PALETTE['2b'].fill });
            });
            saveLegend(`tsb-${region}`, `TSB ${region}`, entries.map(e => e.label), entries.map(e => e.color), '');

            // Curseur
            map.on('mouseenter', `tsb-${region.toLowerCase()}-fill`, () => map.getCanvas().style.cursor = 'pointer');
            map.on('mouseleave', `tsb-${region.toLowerCase()}-fill`, () => map.getCanvas().style.cursor = '');
        })
        .catch(e => { hideSpinner(); if (e.name !== 'AbortError') console.error(`tsb-${region}`, e); });
}

export function initTsb(map) {
    const toggleIdf  = document.getElementById('toggle-tsb-idf');
    const togglePaca = document.getElementById('toggle-tsb-paca');

    // Peupler les selects millésime
    millesimesReady.then(mils => {
        ['idf', 'paca'].forEach(r => {
            const sel = document.getElementById(`tsb-${r}-millesime`);
            if (!sel || !mils.length) return;
            mils.forEach(m => {
                const opt = document.createElement('option');
                opt.value = m; opt.textContent = m;
                sel.appendChild(opt);
            });
        });
    });

    function renderTarifs(tarifs, region, circ, is2b) {
        const regionKey = region === 'PACA' ? 'PACA' : (is2b ? 'IDF_2BIS' : 'IDF');
        const rows = tarifs.filter(t =>
            t.region === regionKey &&
            (region === 'PACA' ? true : +t.circonscription === circ)
        );
        if (!rows.length) return '';
        const fmt = v => v != null ? (+v).toFixed(2) + ' €/m²' : '–';
        return '<div class="info-section-sep">Tarifs TSB</div>' +
            '<div class="info-section">' +
            rows.map(t => irow(t.type_local, fmt(t.tarif))).join('') +
            '</div>';
    }

    // Clic IDF
    map.on('click', 'tsb-idf-fill', e => {
        const p   = e.features[0].properties;
        const circ = +p.circonscription;
        const is2b = circ === 2 && !!p.dcsucs;
        const circKey = is2b ? '2b' : circ;
        const mil  = +p.millesime;

        getTarifs(mil).then(tarifs => {
            showInfo('tsb', `TSB IDF — ${p.libcom || p.code_insee}`,
                irow('Circonscription', `${circ}${is2b ? ' (DCSUCS)' : ''} — ${LABELS.IDF[circKey] || ''}`) +
                (is2b ? irow('Régime tarifaire', 'Tarif réduit 10% (DCSUCS dep 92)') : '') +
                (p.dcsucs && circ === 4 ? irow('Statut', 'DCSUCS — dérogation circ 4') : '') +
                irow('Département', p.dep) +
                irow('Code INSEE', p.code_insee) +
                irow('Millésime', mil) +
                renderTarifs(tarifs, 'IDF', circ, is2b)
            );
        });
    });

    // Clic PACA
    map.on('click', 'tsb-paca-fill', e => {
        const p   = e.features[0].properties;
        const mil = +p.millesime;

        getTarifs(mil).then(tarifs => {
            showInfo('tsb', `TSB PACA — ${p.libcom || p.code_insee}`,
                irow('Région', 'PACA — Bouches-du-Rhône, Var, Alpes-Maritimes') +
                irow('Département', p.dep) +
                irow('Code INSEE', p.code_insee) +
                irow('Millésime', mil) +
                renderTarifs(tarifs, 'PACA', null, false)
            );
        });
    });

    function reloadRegion(region) {
        if (region === 'IDF' && !activeIdf) return;
        if (region === 'PACA' && !activePaca) return;
        remove(map, region);
        if (region === 'IDF') loadedIdf = false;
        else loadedPaca = false;
        dropLegend(`tsb-${region}`);
        loadRegion(map, region);
    }

    document.getElementById('tsb-idf-millesime')?.addEventListener('change', () => reloadRegion('IDF'));
    document.getElementById('tsb-paca-millesime')?.addEventListener('change', () => reloadRegion('PACA'));

    toggleIdf?.addEventListener('change', () => {
        activeIdf = toggleIdf.checked;
        document.getElementById('tsb-idf-options')?.classList.toggle('hidden', !activeIdf);
        if (!activeIdf) {
            remove(map, 'IDF');
            loadedIdf = false;
            dropLegend('tsb-IDF');
            clearInfo('tsb');
        } else if (!loadedIdf) {
            loadRegion(map, 'IDF');
        }
    });

    togglePaca?.addEventListener('change', () => {
        activePaca = togglePaca.checked;
        document.getElementById('tsb-paca-options')?.classList.toggle('hidden', !activePaca);
        if (!activePaca) {
            remove(map, 'PACA');
            loadedPaca = false;
            dropLegend('tsb-PACA');
            clearInfo('tsb');
        } else if (!loadedPaca) {
            loadRegion(map, 'PACA');
        }
    });

    return { isActive: () => activeIdf || activePaca };
}
