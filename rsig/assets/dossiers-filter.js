/**
 * dossiers-filter.js — Filtre dynamique pour la couche Dossiers
 * Filtre les features GeoJSON en mémoire sans re-fetch.
 * Usage : initDossiersFilter(map) → { getActiveFilters, applyFilters }
 */
import { makeAutocomplete } from './utils.js';

// ── Définition des champs filtrables ──────────────────────────────────────
const FIELDS = [
    { key: 'auditeur',    label: 'Auditeur',       type: 'select' },
    { key: 'phase',       label: 'Phase',           type: 'select' },
    { key: 'etat',        label: 'État',            type: 'select' },
    { key: 'produit',     label: 'Produit',          type: 'select' },
    { key: 'client_name', label: 'Client',          type: 'text'   },
    { key: 'montant_tf',  label: 'Taxe foncière',   type: 'number' },
    { key: 'ville',       label: 'Ville',           type: 'text'   },
    { key: 'rtx_code',    label: 'Réf. client',     type: 'text'   },
    { key: 'date_remise', label: 'Date remise',     type: 'date'   },
];

// Valeurs uniques extraites du GeoJSON (peuplées dans setFullData)
const _selectOptions = { produit: [], auditeur: [], phase: [], etat: [] };
// Valeurs uniques pour autocomplete sur champs texte
const _textOptions = { client_name: [], ville: [], rtx_code: [] };
// Champs texte avec autocomplete
const AC_TEXT_FIELDS = new Set(['client_name', 'ville', 'rtx_code']);

const OPERATORS = {
    text:   [
        { value: 'contains',       label: 'contient' },
        { value: 'not_contains',   label: 'ne contient pas' },
        { value: 'startswith',     label: 'commence par' },
        { value: 'not_startswith', label: 'ne commence pas par' },
        { value: 'exact',          label: 'est exactement' },
        { value: 'not_exact',      label: 'est différent de' },
        { value: 'in',             label: "est l'une de" },
        { value: 'not_in',         label: "n'est pas l'une de" },
    ],
    number: [
        { value: 'eq',     label: '=' },
        { value: 'neq',    label: '≠' },
        { value: 'gt',     label: '>' },
        { value: 'lt',     label: '<' },
        { value: 'gte',    label: '>=' },
        { value: 'lte',    label: '<=' },
        { value: 'in',     label: "est l'un de" },
        { value: 'not_in', label: "n'est pas l'un de" },
    ],
    date: [
        { value: 'on',      label: 'le' },
        { value: 'after',   label: 'après le' },
        { value: 'before',  label: 'avant le' },
        { value: 'between', label: 'entre' },
    ],
    select: [
        { value: 'exact',     label: 'est' },
        { value: 'not_exact', label: 'est différent de' },
        { value: 'in',        label: "est l'un de" },
        { value: 'not_in',    label: "n'est pas l'un de" },
    ],
};

const MULTI_OPS = new Set(['in', 'not_in']);

// ── État ───────────────────────────────────────────────────────────────────
let _rules = [];          // { id, field, operator, value, value2? }
let _ruleIdSeq = 0;
let _map = null;
let _fullGeoJSON = null;  // GeoJSON complet (référence mise à jour par setFullData)
let _container = null;
let _rulesListEl = null;
let _formWrap = null;

// ── Matching ───────────────────────────────────────────────────────────────
function matchRule(props, rule) {
    const fieldDef = FIELDS.find(f => f.key === rule.field);
    if (!fieldDef) return true;

    const raw = props[rule.field];
    const op  = rule.operator;

    if (fieldDef.type === 'text') {
        const val  = (raw ?? '').toString().toLowerCase();
        const term = (rule.value ?? '').toLowerCase();
        if (op === 'contains')       return val.includes(term);
        if (op === 'not_contains')   return !val.includes(term);
        if (op === 'startswith')     return val.startsWith(term);
        if (op === 'not_startswith') return !val.startsWith(term);
        if (op === 'exact')          return val === term;
        if (op === 'not_exact')      return val !== term;
        if (op === 'in')     return Array.isArray(rule.value) && rule.value.some(v => val === v.toLowerCase());
        if (op === 'not_in') return !Array.isArray(rule.value) || rule.value.every(v => val !== v.toLowerCase());
        return true;
    }

    if (fieldDef.type === 'number') {
        const num = parseFloat(raw);
        const ref = parseFloat(rule.value);
        if (op === 'in')     return Array.isArray(rule.value) && rule.value.some(v => isFinite(num) && num === parseFloat(v));
        if (op === 'not_in') return !Array.isArray(rule.value) || rule.value.every(v => !isFinite(num) || num !== parseFloat(v));
        if (!isFinite(num) || !isFinite(ref)) return true;
        if (op === 'eq')  return num === ref;
        if (op === 'neq') return num !== ref;
        if (op === 'gt')  return num > ref;
        if (op === 'lt')  return num < ref;
        if (op === 'gte') return num >= ref;
        if (op === 'lte') return num <= ref;
        return true;
    }

    if (fieldDef.type === 'date') {
        if (!raw) return true;
        const d = new Date(raw);
        if (isNaN(d)) return true;
        if (op === 'on') {
            const ref = new Date(rule.value);
            return d.toDateString() === ref.toDateString();
        }
        if (op === 'after')  return d > new Date(rule.value);
        if (op === 'before') return d < new Date(rule.value);
        if (op === 'between') {
            const d1 = new Date(rule.value);
            const d2 = new Date(rule.value2);
            return d >= d1 && d <= d2;
        }
        return true;
    }

    if (fieldDef.type === 'select') {
        if (op === 'exact')     return (raw ?? '') === rule.value;
        if (op === 'not_exact') return (raw ?? '') !== rule.value;
        if (op === 'in')        return Array.isArray(rule.value) && rule.value.includes(raw ?? '');
        if (op === 'not_in')    return !Array.isArray(rule.value) || !rule.value.includes(raw ?? '');
        return true;
    }

    return true;
}

function applyFilters(fc) {
    if (!_rules.length || !fc) return fc;
    return {
        type: 'FeatureCollection',
        features: fc.features.filter(f =>
            _rules.every(rule => matchRule(f.properties || {}, rule))
        ),
    };
}

function getActiveFilters() {
    return _rules.slice();
}

// ── Mise à jour source MapLibre ────────────────────────────────────────────
function pushToMap() {
    if (!_map) return;
    const src = _map.getSource('dossiers-src');
    if (!src || !_fullGeoJSON) return;
    src.setData(applyFilters(_fullGeoJSON));
}

// ── Render des règles (tags) ───────────────────────────────────────────────
function ruleLabel(rule) {
    const fieldDef = FIELDS.find(f => f.key === rule.field);
    const fieldLabel = fieldDef ? fieldDef.label : rule.field;
    const opDef = fieldDef
        ? (OPERATORS[fieldDef.type] || []).find(o => o.value === rule.operator)
        : null;
    const opLabel = opDef ? opDef.label : rule.operator;
    if (Array.isArray(rule.value)) {
        const shown = rule.value.length > 2
            ? rule.value.slice(0, 2).join(', ') + ` +${rule.value.length - 2}`
            : rule.value.join(', ');
        return `${fieldLabel} ${opLabel} [${shown}]`;
    }
    const val2 = rule.value2 ? ` → ${rule.value2}` : '';
    return `${fieldLabel} ${opLabel} ${rule.value}${val2}`;
}

function renderRules() {
    if (!_rulesListEl) return;
    _rulesListEl.innerHTML = '';

    if (!_rules.length) {
        _rulesListEl.innerHTML = '<span class="df-no-rules">Aucun filtre actif</span>';
        return;
    }

    _rules.forEach(rule => {
        const tag = document.createElement('span');
        tag.className = 'df-rule-tag';
        tag.innerHTML = `<span class="df-rule-text">${escHtml(ruleLabel(rule))}</span>
            <button class="df-rule-remove" title="Supprimer ce filtre" data-id="${rule.id}">×</button>`;
        tag.querySelector('.df-rule-remove').addEventListener('click', () => {
            _rules = _rules.filter(r => r.id !== rule.id);
            renderRules();
            pushToMap();
        });
        _rulesListEl.appendChild(tag);
    });
}

function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ── Formulaire d'ajout de règle ────────────────────────────────────────────
function buildForm() {
    const form = document.createElement('div');
    form.className = 'df-form';

    // Field select
    const fieldSel = document.createElement('select');
    fieldSel.className = 'df-select';
    FIELDS.forEach(f => {
        const opt = document.createElement('option');
        opt.value = f.key;
        opt.textContent = f.label;
        fieldSel.appendChild(opt);
    });

    // Operator select
    const opSel = document.createElement('select');
    opSel.className = 'df-select';

    // ── Inputs mono-valeur ──
    const val1 = document.createElement('input');
    val1.className = 'df-input';
    val1.placeholder = 'Valeur…';
    val1.autocomplete = 'off';

    let _acInstance = null;
    function _rebindAc() {
        if (_acInstance) { _acInstance.hide(); _acInstance = null; }
        const fk = fieldSel.value;
        if (AC_TEXT_FIELDS.has(fk) && !MULTI_OPS.has(opSel.value)) {
            _acInstance = makeAutocomplete(val1, (term) => {
                const low = term.toLowerCase();
                return (_textOptions[fk] || []).filter(v => v.toLowerCase().includes(low)).slice(0, 8);
            });
        }
    }

    const val1Sel = document.createElement('select');
    val1Sel.className = 'df-select';

    const val2Wrap = document.createElement('span');
    val2Wrap.className = 'df-val2-wrap';
    val2Wrap.innerHTML = '<span class="df-between-sep">et</span>';
    const val2 = document.createElement('input');
    val2.className = 'df-input';
    val2.placeholder = 'Valeur 2…';
    val2Wrap.appendChild(val2);
    val2Wrap.style.display = 'none';

    // ── Section multi-valeurs ──
    const multiWrap = document.createElement('div');
    multiWrap.className = 'df-multi-wrap';
    multiWrap.style.display = 'none';

    // checklist (type select)
    const checklistWrap = document.createElement('div');
    checklistWrap.className = 'df-checklist';

    // chip input (type text / number)
    const chipInputWrap = document.createElement('div');
    chipInputWrap.className = 'df-chip-input-wrap';
    const chipList = document.createElement('div');
    chipList.className = 'df-chip-list';
    const chipRow = document.createElement('div');
    chipRow.className = 'df-chip-row';
    const chipInp = document.createElement('input');
    chipInp.className = 'df-input';
    chipInp.placeholder = 'Valeur… (Entrée pour ajouter)';
    const chipAddBtn = document.createElement('button');
    chipAddBtn.type = 'button';
    chipAddBtn.className = 'df-btn-chip-add';
    chipAddBtn.textContent = '+';
    chipRow.appendChild(chipInp);
    chipRow.appendChild(chipAddBtn);
    chipInputWrap.appendChild(chipList);
    chipInputWrap.appendChild(chipRow);

    multiWrap.appendChild(checklistWrap);
    multiWrap.appendChild(chipInputWrap);

    let _multiValues = [];

    function renderChipList() {
        chipList.innerHTML = '';
        _multiValues.forEach((v, i) => {
            const chip = document.createElement('span');
            chip.className = 'df-chip';
            const txt = document.createElement('span');
            txt.textContent = v;
            const rm = document.createElement('button');
            rm.type = 'button';
            rm.textContent = '×';
            rm.addEventListener('click', () => { _multiValues.splice(i, 1); renderChipList(); });
            chip.appendChild(txt);
            chip.appendChild(rm);
            chipList.appendChild(chip);
        });
    }

    function addChipValue(v) {
        v = v.trim();
        if (!v || _multiValues.includes(v)) return;
        _multiValues.push(v);
        renderChipList();
        chipInp.value = '';
    }

    chipAddBtn.addEventListener('click', () => addChipValue(chipInp.value));
    chipInp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addChipValue(chipInp.value); } });

    function renderChecklist() {
        checklistWrap.innerHTML = '';
        const fieldDef = FIELDS.find(f => f.key === fieldSel.value);
        if (!fieldDef) return;
        const opts = _selectOptions[fieldDef.key] || [];
        opts.forEach(v => {
            const lbl = document.createElement('label');
            lbl.className = 'df-check-label';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = v;
            cb.addEventListener('change', () => {
                if (cb.checked) { if (!_multiValues.includes(v)) _multiValues.push(v); }
                else { _multiValues = _multiValues.filter(x => x !== v); }
            });
            lbl.appendChild(cb);
            lbl.appendChild(document.createTextNode(' ' + (v || '(vide)')));
            checklistWrap.appendChild(lbl);
        });
    }

    function updateInputVis() {
        const fieldDef = FIELDS.find(f => f.key === fieldSel.value);
        const type  = fieldDef ? fieldDef.type : 'text';
        const op    = opSel.value;
        const multi = MULTI_OPS.has(op);

        _multiValues = [];

        if (multi) {
            val1.style.display      = 'none';
            val1Sel.style.display   = 'none';
            val2Wrap.style.display  = 'none';
            multiWrap.style.display = '';
            if (type === 'select') {
                checklistWrap.style.display = '';
                chipInputWrap.style.display = 'none';
                renderChecklist();
            } else {
                checklistWrap.style.display = 'none';
                chipInputWrap.style.display = '';
                chipList.innerHTML = '';
                chipInp.type = type === 'number' ? 'number' : 'text';
            }
        } else {
            multiWrap.style.display = 'none';
            if (type === 'select') {
                val1Sel.innerHTML = '';
                (_selectOptions[fieldDef?.key] || []).forEach(v => {
                    const opt = document.createElement('option');
                    opt.value = v; opt.textContent = v || '(vide)';
                    val1Sel.appendChild(opt);
                });
                val1.style.display    = 'none';
                val1Sel.style.display = '';
            } else {
                val1.style.display    = '';
                val1Sel.style.display = 'none';
                val1.type = type === 'date' ? 'date' : (type === 'number' ? 'number' : 'text');
                val2.type = val1.type;
            }
            val2Wrap.style.display = op === 'between' ? 'inline-flex' : 'none';
        }
        _rebindAc();
    }

    function updateOpSel() {
        const fieldDef = FIELDS.find(f => f.key === fieldSel.value);
        const type = fieldDef ? fieldDef.type : 'text';
        opSel.innerHTML = '';
        (OPERATORS[type] || []).forEach(o => {
            const opt = document.createElement('option');
            opt.value = o.value; opt.textContent = o.label;
            opSel.appendChild(opt);
        });
        updateInputVis();
    }

    val1Sel.style.display = 'none';
    fieldSel.addEventListener('change', updateOpSel);
    opSel.addEventListener('change', updateInputVis);
    updateOpSel();

    // Buttons row
    const btnRow = document.createElement('div');
    btnRow.className = 'df-btn-row';

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'df-btn df-btn-ok';
    addBtn.textContent = 'Ajouter';
    addBtn.addEventListener('click', () => {
        const fieldDef = FIELDS.find(f => f.key === fieldSel.value);
        const op    = opSel.value;
        const multi = MULTI_OPS.has(op);

        if (multi) {
            if (!_multiValues.length) return;
            _rules.push({ id: ++_ruleIdSeq, field: fieldSel.value, operator: op, value: [..._multiValues] });
            _multiValues = [];
            chipList.innerHTML = '';
            checklistWrap.querySelectorAll('input[type=checkbox]').forEach(cb => { cb.checked = false; });
        } else {
            const isSelect = fieldDef?.type === 'select';
            const v = isSelect ? val1Sel.value : val1.value.trim();
            if (!v) { (isSelect ? val1Sel : val1).focus(); return; }
            const rule = { id: ++_ruleIdSeq, field: fieldSel.value, operator: op, value: v };
            if (op === 'between') {
                const v2 = val2.value.trim();
                if (!v2) { val2.focus(); return; }
                rule.value2 = v2;
            }
            _rules.push(rule);
            val1.value = '';
            val2.value = '';
        }
        renderRules();
        pushToMap();
        _formWrap.style.display = 'none';
    });

    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'df-btn df-btn-cancel';
    cancelBtn.textContent = 'Annuler';
    cancelBtn.addEventListener('click', () => { _formWrap.style.display = 'none'; });

    btnRow.appendChild(addBtn);
    btnRow.appendChild(cancelBtn);

    form.appendChild(fieldSel);
    form.appendChild(opSel);
    form.appendChild(val1);
    form.appendChild(val1Sel);
    form.appendChild(val2Wrap);
    form.appendChild(multiWrap);
    form.appendChild(btnRow);

    return form;
}

// ── Injection CSS ──────────────────────────────────────────────────────────
function injectStyles() {
    if (document.getElementById('dossiers-filter-styles')) return;
    const style = document.createElement('style');
    style.id = 'dossiers-filter-styles';
    style.textContent = `
#dossiers-filter-wrap {
    font-size: 12px;
}
.df-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
}
.df-title {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--text3);
}
.df-btn-open {
    font-size: 11px;
    font-weight: 600;
    padding: 3px 8px;
    background: var(--blue-hover);
    color: var(--blue-light);
    border: 1px solid var(--border);
    border-radius: 4px;
    cursor: pointer;
    transition: background .15s;
}
.df-btn-open:hover { background: var(--blue); color: #fff; }
.df-rules-list {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 4px;
    min-height: 20px;
}
.df-no-rules {
    font-size: 11px;
    color: var(--text3);
    font-style: italic;
}
.df-rule-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 6px 2px 7px;
    background: var(--blue-hover);
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    font-size: 11px;
    color: var(--blue);
}
.df-rule-text { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.df-rule-remove {
    background: none;
    border: none;
    color: var(--blue-light);
    cursor: pointer;
    font-size: 13px;
    line-height: 1;
    padding: 0 1px;
    opacity: .7;
    transition: opacity .1s;
}
.df-rule-remove:hover { opacity: 1; color: var(--accent); }
.df-form {
    display: flex;
    flex-direction: column;
    gap: 5px;
    padding: 8px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-top: 4px;
}
.df-select, .df-input {
    width: 100%;
    font-size: 11px;
    padding: 4px 7px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--surface);
    color: var(--text);
    outline: none;
    box-sizing: border-box;
}
.df-select:focus, .df-input:focus { border-color: var(--blue-light); }
.df-val2-wrap {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    width: 100%;
}
.df-between-sep { font-size: 11px; color: var(--text3); white-space: nowrap; }
.df-val2-wrap .df-input { flex: 1; }
.df-btn-row {
    display: flex;
    gap: 6px;
    margin-top: 2px;
}
.df-btn {
    flex: 1;
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 600;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background .15s;
}
.df-btn-ok     { background: var(--blue); color: #fff; }
.df-btn-ok:hover { background: var(--blue-light); }
.df-btn-cancel { background: var(--surface2); color: var(--text2); border: 1px solid var(--border); }
.df-btn-cancel:hover { background: var(--border); }
.df-multi-wrap { display: flex; flex-direction: column; gap: 4px; }
.df-checklist { display: flex; flex-direction: column; gap: 3px; max-height: 120px; overflow-y: auto; padding: 4px 6px; background: var(--surface); border: 1px solid var(--border); border-radius: 4px; }
.df-check-label { display: flex; align-items: center; font-size: 11px; cursor: pointer; padding: 1px 0; }
.df-check-label input[type=checkbox] { margin-right: 4px; accent-color: var(--blue); }
.df-chip-input-wrap { display: flex; flex-direction: column; gap: 4px; }
.df-chip-list { display: flex; flex-wrap: wrap; gap: 3px; min-height: 0; }
.df-chip-row { display: flex; gap: 4px; }
.df-chip-row .df-input { flex: 1; }
.df-chip {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 5px 2px 7px;
    background: var(--blue-hover); border: 1px solid #bfdbfe;
    border-radius: 10px; font-size: 11px; color: var(--blue);
}
.df-chip span { max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.df-chip button {
    background: none; border: none; color: var(--blue-light);
    cursor: pointer; font-size: 13px; line-height: 1; padding: 0; opacity: .7;
}
.df-chip button:hover { opacity: 1; color: var(--accent); }
.df-btn-chip-add {
    padding: 4px 10px; font-size: 13px; font-weight: 700;
    background: var(--blue); color: #fff; border: none;
    border-radius: 4px; cursor: pointer; flex-shrink: 0;
}
.df-btn-chip-add:hover { background: var(--blue-light); }
    `;
    document.head.appendChild(style);
}

// ── Init ───────────────────────────────────────────────────────────────────
export function initDossiersFilter(map) {
    _map = map;
    injectStyles();

    _container = document.getElementById('dossiers-filter-wrap');
    if (!_container) {
        // Create container if absent (will be attached to card by catalogue.js)
        _container = document.createElement('div');
        _container.id = 'dossiers-filter-wrap';
    }

    // Header
    const header = document.createElement('div');
    header.className = 'df-header';

    const title = document.createElement('span');
    title.className = 'df-title';
    title.textContent = 'Filtres';

    const openBtn = document.createElement('button');
    openBtn.className = 'df-btn-open';
    openBtn.textContent = '+ Ajouter un filtre';

    header.appendChild(title);
    header.appendChild(openBtn);
    _container.appendChild(header);

    // Rules list
    _rulesListEl = document.createElement('div');
    _rulesListEl.className = 'df-rules-list';
    _container.appendChild(_rulesListEl);

    // Form (hidden initially)
    _formWrap = document.createElement('div');
    _formWrap.style.display = 'none';
    _formWrap.appendChild(buildForm());
    _container.appendChild(_formWrap);

    openBtn.addEventListener('click', () => {
        _formWrap.style.display = _formWrap.style.display === 'none' ? 'block' : 'none';
    });

    // Initial render
    renderRules();

    return {
        getActiveFilters,
        applyFilters,
        /** Call this from dossiers.js after loading GeoJSON to register the full dataset */
        setFullData(fc) {
            _fullGeoJSON = fc;
            const LS_KEY = 'dossiers_select_options';
            let cached = {};
            try { cached = JSON.parse(localStorage.getItem(LS_KEY) || '{}'); } catch (_) {}

            let changed = false;
            ['produit', 'auditeur', 'phase', 'etat'].forEach(key => {
                const vals = [...new Set(
                    fc.features.map(f => f.properties[key] ?? '').filter(Boolean)
                )].sort((a, b) => a.localeCompare(b, 'fr'));

                const cachedVals = cached[key];
                if (Array.isArray(cachedVals) && cachedVals.length === vals.length) {
                    _selectOptions[key] = cachedVals;
                } else {
                    _selectOptions[key] = vals;
                    cached[key] = vals;
                    changed = true;
                }
            });

            // Valeurs uniques pour autocomplete texte (toujours recalculées, pas besoin de cache)
            ['client_name', 'ville', 'rtx_code'].forEach(key => {
                _textOptions[key] = [...new Set(
                    fc.features.map(f => f.properties[key] ?? '').filter(Boolean)
                )].sort((a, b) => a.localeCompare(b, 'fr'));
            });

            if (changed) {
                try { localStorage.setItem(LS_KEY, JSON.stringify(cached)); } catch (_) {}
            }
        },
        /** Force re-apply filters to map (call after map source is ready) */
        refresh() {
            pushToMap();
        },
        /** Returns the filter container element */
        getContainer() {
            return _container;
        },
        /** Call when layer is deactivated — frees GeoJSON memory */
        reset() {
            _fullGeoJSON = null;
        },
    };
}
