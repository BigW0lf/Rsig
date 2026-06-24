import { showSpinner, hideSpinner, stepExpr, PAL, bddOnTop, apiFetch } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

let active = false;
let abortCtrl = null;
let loadId = 0;
const deptCache   = {};
const breaksCache = {};

const DEPT_ZOOM = 11;

function bboxParam(map) {
    const b = map.getBounds();
    return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
}

function getOptions() {
    return {
        cat:   document.getElementById('tf-categorie')?.value || '',
        annee: document.getElementById('tf-annee')?.value     || '2025',
    };
}

function cacheKey(cat, annee) { return `${cat}_${annee}`; }

function fetchLayer(url, onData) {
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();
    showSpinner();
    apiFetch(url, { signal: abortCtrl.signal })
        .then(r => r.json())
        .then(d => { hideSpinner(); onData(d); })
        .catch(e => { hideSpinner(); });
}

function quantileBreaks(values, n) {
    const sorted = values.filter(v => isFinite(v) && v > 0).sort((a, b) => a - b);
    if (!sorted.length) return null;
    const breaks = [];
    for (let i = 0; i <= n; i++) {
        const idx = Math.round(i * (sorted.length - 1) / n);
        breaks.push(+sorted[idx].toFixed(4));
    }
    const unique = [breaks[0]];
    for (let i = 1; i < breaks.length; i++) {
        if (breaks[i] > unique[unique.length - 1]) unique.push(breaks[i]);
    }
    return unique.length > 1 ? unique : null;
}

function upsert(map, fc, color) {
    if (map.getLayer('tf-fill')) {
        map.getSource('tf-src').setData(fc);
        map.setPaintProperty('tf-fill', 'fill-color', color);
    } else {
        if (map.getSource('tf-src')) {
            ['tf-fill', 'tf-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
            map.removeSource('tf-src');
        }
        map.addSource('tf-src', { type: 'geojson', data: fc });
        map.addLayer({ id: 'tf-fill', type: 'fill', source: 'tf-src',
            paint: { 'fill-color': color, 'fill-opacity': 0.45 } });
        map.addLayer({ id: 'tf-line', type: 'line', source: 'tf-src',
            paint: { 'line-color': '#444', 'line-width': 0.5 } });
    }
    bddOnTop(map);
}

function remove(map) {
    ['tf-fill', 'tf-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
    if (map.getSource('tf-src')) map.removeSource('tf-src');
}

export function loadTf(map) {
    if (!active) return;
    const { cat, annee } = getOptions();

    if (!cat) {
        const msg = document.getElementById('tf-msg');
        if (msg) msg.textContent = 'Choisissez une catégorie pour afficher la couche.';
        return;
    }
    const msg = document.getElementById('tf-msg');
    if (msg) msg.textContent = '';

    const zoom  = map.getZoom();
    const level = zoom < DEPT_ZOOM ? 'dept' : 'commune';
    const myId  = ++loadId;

    const render = (fc, renderLevel) => {
        if (myId !== loadId || !active) return;
        if (!fc?.features?.length) {
            if (map.getSource('tf-src'))
                map.getSource('tf-src').setData({ type: 'FeatureCollection', features: [] });
            return;
        }
        // Breaks stables : calculés une fois par cat+annee+level, jamais recalculés sur un pan
        const bKey = cacheKey(cat, annee) + '_' + renderLevel;
        if (!breaksCache[bKey]) {
            const values = fc.features.map(f => +f.properties.tf_estime);
            breaksCache[bKey] = quantileBreaks(values, 6);
        }
        const breaks = breaksCache[bKey];
        if (!breaks) return;
        upsert(map, fc, stepExpr('tf_estime', breaks, PAL.cfe));
        const niveauLabel = renderLevel === 'dept' ? 'moy. par département' : 'communes';
        saveLegend('tf', `TF €/m² — ${cat} ${annee} (${niveauLabel})`, breaks, PAL.cfe, ' €/m²');
    };

    const params = `categorie=${cat}&annee=${annee}`;
    if (level === 'dept') {
        const key = cacheKey(cat, annee);
        if (deptCache[key]) { render(deptCache[key], 'dept'); return; }
        fetchLayer(`/api/tf/departements?${params}`, fc => {
            deptCache[key] = fc;
            render(fc, 'dept');
        });
    } else {
        fetchLayer(`/api/tf?bbox=${bboxParam(map)}&${params}`, fc => render(fc, 'commune'));
    }
}

export function initTf(map) {
    const toggle  = document.getElementById('toggle-tf');
    const options = document.getElementById('tf-options');
    const catSel  = document.getElementById('tf-categorie');
    const anneSel = document.getElementById('tf-annee');

    function onOptionChange() {
        Object.keys(deptCache).forEach(k => delete deptCache[k]);
        Object.keys(breaksCache).forEach(k => delete breaksCache[k]);
        if (active) loadTf(map);
    }

    toggle.addEventListener('change', () => {
        active = toggle.checked;
        options.classList.toggle('hidden', !active);
        if (!active) { if (abortCtrl) abortCtrl.abort(); remove(map); dropLegend('tf'); clearInfo('tf'); }
        else loadTf(map);
    });

    if (catSel)  catSel.addEventListener('change',  onOptionChange);
    if (anneSel) anneSel.addEventListener('change', onOptionChange);

    map.on('mouseenter', 'tf-fill', () => map.getCanvas().style.cursor = 'pointer');
    map.on('mouseleave', 'tf-fill', () => map.getCanvas().style.cursor = '');

    map.on('click', 'tf-fill', e => {
        if (!active) return;
        const p    = e.features[0].properties;
        const fmtv = (v, suf) => v != null ? (+v).toFixed(4) + suf : '–';
        const fmtp = v => v != null ? (+v).toFixed(3) + ' %' : '–';
        const { cat } = getOptions();

        const fmtvN = (v, suf) => v != null ? (+v).toFixed(4) + suf : null;
        const fmtpN = v => (v != null && +v !== 0) ? (+v).toFixed(3) + ' %' : null;
        if (p.nom_dep) {
            showInfo('tf', `${p.nom_dep} (${p.code_dep})`,
                irow(`TF estimée/m² (${cat})`, fmtvN(p.tf_estime, ' €/m²')) +
                irow('Tarif moyen section', fmtvN(p.tarif_moyen, ' €/m²')) +
                `<div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez ≥ 9 pour le détail par commune</div>`
            );
        } else {
            showInfo('tf', `${p.libcom} (${p.code_insee}) — ${p.millesime}`,
                irow(`TF estimée/m² (${cat})`, fmtvN(p.tf_estime, ' €/m²')) +
                irow('Indicateur TF/m²', fmtvN(p.indicateur_tf_m2, '')) +
                irow('Tarif section', fmtvN(p.tarif_section, ' €/m²')) +
                irow('Taux TF total', fmtpN(p.taux_tf_total)) +
                irow('dont Commune', fmtpN(p.taux_com)) +
                irow('dont EPCI', fmtpN(p.taux_epci)) +
                irow('dont TSE', fmtpN(p.taux_tse)) +
                irow('dont TEOM', fmtpN(p.taux_teom))
            );
        }
    });

    return { load: () => loadTf(map), isActive: () => active };
}
