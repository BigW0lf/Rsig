import { showSpinner, hideSpinner, stepExpr, PAL, computeBreaks, bddOnTop, apiFetch, EMPTY_FC } from '../utils.js';
import { saveLegend, dropLegend } from '../legend.js';
import { showInfo, clearInfo, irow } from '../panel.js';

let active   = false;
let abortCtrl = null;
let loadId    = 0;
const cache = {};

const DEPT_ZOOM = 9;

// Palette divergente pour l'évolution : vert (baisse) → blanc → rouge (hausse)
const PAL_EVOL = ['#1a9641','#a6d96a','#ffffbf','#fdae61','#d7191c'];

const CHAMP_LABELS = {
    taux_fb_commune_vote:  'TFPB Commune',
    taux_fb_syndicats_net: 'TFPB Syndicat',
    taux_fb_gfp_vote:      'TFPB EPCI',
    taux_tse_net:          'TFPB TSE',
    taux_tafnb_commune_net:'TFPB TASA',
    taux_teom_plein:       'TFPB TEOM',
    taux_tse_gemapi_net:   'TFPB GEMAPI',
    taux_fnb_commune:      'TFPNB Commune',
    taux_fnb_syndicats_net:'TFPNB Syndicat',
    taux_fnb_gfp_vote:     'TFPNB EPCI',
    taux_tafnb_gfp_net:    'TFPNB TASA EPCI',
};

function bboxParam(map) {
    const b = map.getBounds();
    return `${b.getWest()},${b.getSouth()},${b.getEast()},${b.getNorth()}`;
}

function fetchLayer(url, onData) {
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();
    showSpinner();
    apiFetch(url, { signal: abortCtrl.signal })
        .then(r => r.json())
        .then(d => { hideSpinner(); onData(d); })
        .catch(e => { hideSpinner(); });
}

function upsert(map, fc, color) {
    if (map.getLayer('taux-fill')) {
        map.getSource('taux-src').setData(fc);
        map.setPaintProperty('taux-fill', 'fill-color', color);
    } else {
        ['taux-fill','taux-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
        if (map.getSource('taux-src')) map.removeSource('taux-src');
        map.addSource('taux-src', { type: 'geojson', data: fc });
        map.addLayer({ id: 'taux-fill', type: 'fill', source: 'taux-src', paint: { 'fill-color': color, 'fill-opacity': 0.5 } });
        map.addLayer({ id: 'taux-line', type: 'line', source: 'taux-src', paint: { 'line-color': '#334', 'line-width': 0.5 } });
        map.on('mouseenter', 'taux-fill', () => map.getCanvas().style.cursor = 'pointer');
        map.on('mouseleave', 'taux-fill', () => map.getCanvas().style.cursor = '');
    }
    bddOnTop(map);
}

function remove(map) {
    ['taux-fill','taux-line'].forEach(id => { if (map.getLayer(id)) map.removeLayer(id); });
    if (map.getSource('taux-src')) map.removeSource('taux-src');
}

function getLevel(zoom) { return zoom < DEPT_ZOOM ? 'dept' : 'commune'; }
function getMode() { return document.getElementById('taux-mode')?.value ?? 'normal'; }

export function loadTaux(map) {
    if (!active) return;
    const champ  = document.getElementById('taux-champ').value;
    const mode   = getMode();
    const level  = getLevel(map.getZoom());
    const myId   = ++loadId;

    if (mode === 'evolution') {
        const milDe = document.getElementById('taux-evol-de')?.value ?? '2021';
        const milA  = document.getElementById('taux-evol-a')?.value  ?? '2025';
        const label = (CHAMP_LABELS[champ] ?? champ) + ` — Évol. ${milDe}→${milA}`;

        const renderEvol = (fc, lvl) => {
            if (myId !== loadId || !active) return;
            if (!fc?.features?.length) { if (map.getSource('taux-src')) map.getSource('taux-src').setData(EMPTY_FC); return; }
            const vals   = fc.features.map(f => +f.properties.delta).filter(v => isFinite(v));
            const breaks = computeBreaks(vals, 5);
            upsert(map, fc, stepExpr('delta', breaks, PAL_EVOL));
            const sfx = lvl === 'dept' ? ' pts (moy. dep.)' : ' pts';
            saveLegend('taux', label + (lvl === 'dept' ? ' — moy. par département' : ' — communes'), breaks, PAL_EVOL, sfx);
        };

        if (level === 'dept') {
            const key = `evol|${champ}|${milDe}|${milA}|dept`;
            if (cache[key]) { renderEvol(cache[key], 'dept'); return; }
            fetchLayer(`/api/taux/evolution?champ=${champ}&de=${milDe}&a=${milA}&level=dept`, fc => { cache[key] = fc; renderEvol(fc, 'dept'); });
        } else {
            fetchLayer(`/api/taux/evolution?champ=${champ}&de=${milDe}&a=${milA}&bbox=${bboxParam(map)}`, fc => renderEvol(fc, 'commune'));
        }
        return;
    }

    // Mode normal
    const millesime = document.getElementById('taux-millesime').value;
    const render = (fc, renderLevel) => {
        if (myId !== loadId || !active) return;
        if (getLevel(map.getZoom()) !== renderLevel) return;
        if (!fc?.features?.length) {
            if (map.getSource('taux-src')) map.getSource('taux-src').setData(EMPTY_FC);
            return;
        }
        const breaks = computeBreaks(fc.features.map(f => +f.properties.valeur_affichee).filter(v => isFinite(v) && v > 0), 5);
        upsert(map, fc, stepExpr('valeur_affichee', breaks, PAL.taux));
        const label       = CHAMP_LABELS[champ] ?? champ;
        const niveauLabel = renderLevel === 'dept' ? 'moy. par département' : 'communes';
        saveLegend('taux', `${label} ${millesime} — ${niveauLabel}`, breaks, PAL.taux, ' %');
    };

    if (level === 'dept') {
        const key = `${champ}|${millesime}|dept`;
        if (cache[key]) { render(cache[key], 'dept'); return; }
        fetchLayer(`/api/taux/departements?champ=${champ}&millesime=${millesime}`, fc => { cache[key] = fc; render(fc, 'dept'); });
    } else {
        fetchLayer(`/api/taux?bbox=${bboxParam(map)}&champ=${champ}&millesime=${millesime}`, fc => render(fc, 'commune'));
    }
}

export function initTaux(map) {
    const toggle      = document.getElementById('toggle-taux');
    const options     = document.getElementById('taux-options');
    const champEl     = document.getElementById('taux-champ');
    const millesimeEl = document.getElementById('taux-millesime');
    const modeEl      = document.getElementById('taux-mode');
    const normalOpts  = document.getElementById('taux-normal-opts');
    const evolOpts    = document.getElementById('taux-evol-opts');
    const evolDeEl    = document.getElementById('taux-evol-de');
    const evolAEl     = document.getElementById('taux-evol-a');

    fetch('/api/taux/millesimes')
        .then(r => r.json())
        .then(ms => {
            if (!ms?.length) return;
            millesimeEl.innerHTML = ms.map(m => `<option value="${m}">${m}</option>`).join('');
            // Peupler aussi les selects évolution
            if (evolDeEl) evolDeEl.innerHTML = ms.slice().reverse().map(m => `<option value="${m}">${m}</option>`).join('');
            if (evolAEl)  { evolAEl.innerHTML = ms.map(m => `<option value="${m}">${m}</option>`).join(''); }
            // Défaut : de = plus ancien, a = plus récent
            if (evolDeEl && ms.length > 1) evolDeEl.value = ms[ms.length - 1];
            if (evolAEl  && ms.length > 0) evolAEl.value  = ms[0];
            if (active) loadTaux(map);
        })
        .catch(e => console.warn('[taux] millesimes', e));

    modeEl?.addEventListener('change', () => {
        const isEvol = modeEl.value === 'evolution';
        normalOpts?.classList.toggle('hidden', isEvol);
        evolOpts?.classList.toggle('hidden', !isEvol);
        Object.keys(cache).forEach(k => delete cache[k]);
        clearInfo('taux');
        loadTaux(map);
    });

    toggle.addEventListener('change', () => {
        active = toggle.checked;
        options.classList.toggle('hidden', !active);
        if (!active) { if (abortCtrl) abortCtrl.abort(); remove(map); dropLegend('taux'); clearInfo('taux'); }
        else loadTaux(map);
    });
    champEl.addEventListener('change', () => { clearInfo('taux'); loadTaux(map); });
    millesimeEl.addEventListener('change', () => {
        Object.keys(cache).forEach(k => delete cache[k]);
        clearInfo('taux');
        loadTaux(map);
    });
    evolDeEl?.addEventListener('change', () => { Object.keys(cache).forEach(k => delete cache[k]); clearInfo('taux'); loadTaux(map); });
    evolAEl?.addEventListener('change',  () => { Object.keys(cache).forEach(k => delete cache[k]); clearInfo('taux'); loadTaux(map); });

    map.on('click', 'taux-fill', e => {
        if (!active) return;
        const p     = e.features[0].properties;
        const champ = champEl.value;
        const mode  = getMode();
        const label = CHAMP_LABELS[champ] ?? champ;
        const fmt   = val => val != null ? (+val).toFixed(4) + ' %' : '–';
        const fmtPts = val => val != null ? (val > 0 ? '+' : '') + (+val).toFixed(4) + ' pts' : '–';

        if (p.nom_dep) {
            // Clic sur département
            const milDe = evolDeEl?.value ?? '2021';
            const milA  = evolAEl?.value  ?? millesimeEl.value;
            showInfo('taux', `${p.nom_dep} (${p.code_dep})`,
                mode === 'evolution'
                    ? irow(`${label} évol. ${milDe}→${milA}`, fmtPts(p.delta)) + irow(`Taux moy. ${milDe}`, fmt(p.val_de)) + irow(`Taux moy. ${milA}`, fmt(p.val_a))
                    : irow(label + ' moyen ' + millesimeEl.value, fmt(p.valeur_affichee)) +
                      `<div class="info-row" style="font-size:11px;color:var(--text3)">Zoomez ≥ 9 pour voir le détail par commune</div>`
            );
        } else if (mode === 'evolution') {
            // Clic commune mode évolution
            const milDe = evolDeEl?.value ?? '2021';
            const milA  = evolAEl?.value  ?? '2025';
            const deltaClass = +p.delta > 0 ? 'tag-up' : +p.delta < 0 ? 'tag-down' : '';
            showInfo('taux', `${p.libcom} (${p.dep})`,
                `<div class="info-row"><span class="info-label">${label} ${milDe}→${milA}</span><span class="info-value ${deltaClass}">${fmtPts(p.delta)}</span></div>` +
                irow(`Taux ${milDe}`, fmt(p.val_de)) +
                irow(`Taux ${milA}`,  fmt(p.val_a))
            );
        } else {
            // Clic commune mode normal — + benchmark
            const mil = millesimeEl.value;
            const fmtNull = val => val != null ? (+val).toFixed(4) + ' %' : null;
            const baseHtml = irow(label, fmt(p.valeur_affichee)) +
                `<details style="margin-top:6px"><summary style="cursor:pointer;font-size:11px;color:var(--text3)">Tous les taux</summary>
                ${irow('TFPB Commune',    fmtNull(p.taux_fb_commune_vote))}
                ${irow('TFPB Syndicat',  fmtNull(p.taux_fb_syndicats_net))}
                ${irow('TFPB EPCI',      fmtNull(p.taux_fb_gfp_vote))}
                ${irow('TFPB TSE',       fmtNull(p.taux_tse_net))}
                ${irow('TFPB TASA',      fmtNull(p.taux_tafnb_commune_net))}
                ${irow('TFPB TEOM',      fmtNull(p.taux_teom_plein))}
                ${irow('TFPB GEMAPI',    fmtNull(p.taux_tse_gemapi_net))}
                ${irow('TFPNB Commune',  fmtNull(p.taux_fnb_commune))}
                ${irow('TFPNB Syndicat', fmtNull(p.taux_fnb_syndicats_net))}
                ${irow('TFPNB EPCI',     fmtNull(p.taux_fnb_gfp_vote))}
                ${irow('TFPNB TASA EPCI',fmtNull(p.taux_tafnb_gfp_net))}
                </details>`;

            showInfo('taux', `${p.libcom} (${p.dep}) — ${mil}`, baseHtml + `<div id="taux-benchmark-zone" style="margin-top:6px;font-size:11px;color:var(--text3)">Calcul benchmark…</div>`);

            // Charger benchmark en asynchrone
            const dep = String(p.dep).padStart(2, '0');
            const com = p.com;
            fetch(`/api/taux/benchmark?champ=${champ}&millesime=${mil}&dep=${dep}&com=${com}`)
                .then(r => r.ok ? r.json() : null)
                .then(b => {
                    const zone = document.getElementById('taux-benchmark-zone');
                    if (!zone || !b) { if (zone) zone.remove(); return; }
                    const rang = b.rang_desc;
                    const nb   = b.nb_communes;
                    const pct  = +b.pct_rang;
                    // pct_rang = rang / nb * 100 : 1% = commune la + taxée, 99% = la - taxée
                    const tagCls = pct <= 25 ? 'tag-down' : pct >= 75 ? 'tag-up' : '';
                    const position = pct <= 10  ? 'parmi les 10% les plus élevés du dép.'
                                   : pct <= 25  ? 'parmi les 25% les plus élevés du dép.'
                                   : pct >= 90  ? 'parmi les 10% les plus bas du dép.'
                                   : pct >= 75  ? 'parmi les 25% les plus bas du dép.'
                                   : 'dans la moyenne du dép.';
                    const ecartMed = b.mediane ? ((+b.val_commune - +b.mediane) / +b.mediane * 100).toFixed(1) : null;
                    const ecartCls = ecartMed > 0 ? 'tag-down' : ecartMed < 0 ? 'tag-up' : '';
                    const evolRows = (b.evolution ?? []).map(r =>
                        `<tr><td>${r.millesime}</td><td>${(+r.val).toFixed(4)} %</td></tr>`
                    ).join('');
                    zone.outerHTML = `
                        <details open style="margin-top:6px">
                            <summary style="cursor:pointer;font-weight:600;font-size:11px">Benchmark département</summary>
                            <div class="info-row"><span class="info-label">Classement</span><span class="info-value"><span class="${tagCls}">${rang}ᵉ / ${nb} communes</span></span></div>
                            <div class="info-row"><span class="info-label" style="font-size:10px;font-style:italic">${position}</span></div>
                            ${ecartMed !== null ? `<div class="info-row"><span class="info-label">Écart à la médiane</span><span class="info-value ${ecartCls}">${ecartMed > 0 ? '+' : ''}${ecartMed} %</span></div>` : ''}
                            ${irow('Médiane dép.', fmt(b.mediane))}
                            ${irow('Min / Max', `${fmt(b.min_val)} / ${fmt(b.max_val)}`)}
                            ${evolRows ? `<table class="evol-table" style="margin-top:4px"><tr><th>Millésime</th><th>${label}</th></tr>${evolRows}</table>` : ''}
                        </details>`;
                })
                .catch(() => { const z = document.getElementById('taux-benchmark-zone'); if (z) z.remove(); });
        }
    });

    return { load: () => loadTaux(map), isActive: () => active };
}
