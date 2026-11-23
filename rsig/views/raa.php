<?php
/** @var bool $connected  @var bool $publie  @var int $annee  @var int[] $annees
 *  @var array $deps  @var array $recueils  @var array $suivi
 *  @var ?array $veille  @var bool $isAdmin  @var array $frequences */
$h = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
$fmtDate = fn($d) => $d ? date('d/m/Y', strtotime($d)) : '';

$libStatut = ['fourni' => 'Fourni par le Cabinet', 'ok' => 'Fonctionne', 'observe' => 'Lu par la veille',
              'confirmer' => 'À confirmer', 'corriger' => 'À corriger', 'manquant' => 'Lien à fournir'];

// Constat par département pour l'année affichée
$parDep = [];
foreach ($recueils as $r) $parDep[$r['code']][] = $r;
$lignes = [];
$compte = ['trouve' => 0, 'verifier' => 0, 'attente' => 0, 'jamais' => 0];
foreach ($deps as $d) {
    $rs       = $parDep[$d['code']] ?? [];
    $trouves  = array_values(array_filter($rs, fn($r) => $r['resultat'] === 'trouve'));
    $aVerif   = array_values(array_filter($rs, fn($r) => in_array($r['resultat'], ['illisible', 'incomplet'], true)));
    $s        = $suivi[$d['code']] ?? null;
    $constat  = $trouves ? 'trouve' : ($aVerif ? 'verifier' : ($s ? 'attente' : 'jamais'));
    $compte[$constat]++;
    $lignes[] = compact('d', 'trouves', 'aVerif', 's', 'constat');
}
$libConstat = ['trouve' => 'Décision repérée', 'verifier' => 'À vérifier à la main',
               'attente' => 'Pas encore publiée', 'jamais' => 'Jamais examiné'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RSig — RAA</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= ASSET_VER ?>">
    <style>
        .tab-bar { display: flex; gap: 4px; border-bottom: 2px solid var(--border); margin-bottom: 18px; }
        .tab { padding: 8px 16px; font-size: 0.85rem; font-weight: 600; cursor: pointer;
               color: var(--text3); border: none; background: none; border-bottom: 2px solid transparent;
               margin-bottom: -2px; transition: color .15s; }
        .tab.active { color: var(--blue); border-bottom-color: var(--blue); }
        .tab-panel { display: none; } .tab-panel.active { display: block; }

        .raa-intro { color: var(--text2); max-width: 90ch; margin-bottom: 14px; line-height: 1.5; }
        .compteurs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
        @media (max-width: 760px) { .compteurs { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .compteur { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
                    padding: 10px 14px; text-align: left; cursor: pointer; font: inherit; color: inherit; }
        .compteur b { display: block; font-size: 1.4rem; font-variant-numeric: tabular-nums; }
        .compteur span { font-size: 0.78rem; color: var(--text3); }
        .compteur[aria-pressed="true"] { border-color: var(--blue); box-shadow: inset 0 -3px 0 var(--blue); }

        .outils { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px; }
        .outils input[type=search] { width: 240px; max-width: 100%; }
        .outils .nb { color: var(--text3); font-size: 0.8rem; margin-left: auto; }

        .table-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: var(--radius);
                      box-shadow: var(--shadow-sm); background: var(--surface); }
        .table-wrap table { font-size: 0.8rem; width: 100%; min-width: 900px; border-collapse: collapse; }
        .table-wrap th, .table-wrap td { padding: 7px 10px; border-bottom: 1px solid var(--border2); text-align: left; vertical-align: top; }
        .table-wrap th { background: var(--surface2); color: var(--text3); font-size: 0.72rem; text-transform: uppercase; letter-spacing: .3px; }
        td.code { color: var(--text3); font-variant-numeric: tabular-nums; width: 3.5em; }
        td.nom { font-weight: 600; white-space: nowrap; }
        .lien { display: block; color: var(--blue-light); word-break: break-all; }
        .lien:hover { text-decoration: underline; }
        .muet { color: var(--text3); }
        .detail { display: block; color: var(--text3); font-size: 0.75rem; margin-top: 2px; }
        .extrait { color: var(--text2); font-size: 0.76rem; }

        .pastille { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
        .p-trouve, .p-ok, .p-fourni { background: #e3f2e8; color: var(--green); }
        .p-verifier, .p-confirmer   { background: #f4eed9; color: #6b5a1f; }
        .p-attente, .p-observe      { background: var(--blue-hover); color: var(--blue); }
        .p-jamais                   { background: var(--surface2); color: var(--text3); }
        .p-corriger, .p-manquant    { background: #f8e6dc; color: #a2471c; }

        .veille { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
                  box-shadow: var(--shadow-sm); padding: 14px 16px; margin-bottom: 18px;
                  display: flex; flex-wrap: wrap; gap: 12px 24px; align-items: center; justify-content: space-between; }
        .veille-etat { display: flex; gap: 12px; align-items: flex-start; flex: 1 1 420px; }
        .veille-titre { font-weight: 700; font-size: 0.95rem; }
        .veille-detail { color: var(--text2); font-size: 0.8rem; margin-top: 3px; line-height: 1.45; }
        .voyant { width: 12px; height: 12px; border-radius: 50%; margin-top: 4px; flex: none; background: var(--text3); }
        .v-active  { background: var(--green); }
        .v-arretee { background: var(--text3); }
        .v-encours { background: var(--blue-light); animation: pulse 1.2s ease-in-out infinite; }
        @keyframes pulse { 50% { opacity: .35; } }
        @media (prefers-reduced-motion: reduce) { .v-encours { animation: none; } }
        .veille-commandes { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; font-size: 0.8rem; color: var(--text2); }
        .veille-commandes select { margin-left: 6px; }
        .btn-veille { padding: 6px 12px; border: 1px solid var(--blue); background: var(--surface); color: var(--blue);
                      border-radius: var(--radius); font: inherit; font-weight: 600; cursor: pointer; }
        .btn-veille:hover:not(:disabled) { background: var(--blue-hover); }
        .btn-veille:disabled { opacity: .5; cursor: default; }
        .btn-arret { border-color: var(--accent); color: var(--accent); }
        .veille-alerte { flex-basis: 100%; background: #f8e6dc; color: #a2471c; border-radius: var(--radius); padding: 8px 12px; font-size: 0.8rem; }

        .decisions { margin-bottom: 22px; }
        .decisions h2 { font-size: 1rem; margin-bottom: 8px; }
        .decisions ul { list-style: none; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); }
        .decisions li { padding: 9px 14px; border-bottom: 1px solid var(--border2); font-size: 0.82rem; }
        .decisions li:last-child { border-bottom: 0; }
        .dec-dep { font-weight: 700; margin-right: 8px; }
        .decisions .lien { display: inline; }
    </style>
    <script>const _P={carte:'/',donnees:'/donnees',requetes:'/requetes',crm:'/crm',raa:'/raa'};function navTo(p){window.parent?.showPage?window.parent.showPage(p):location.href=_P[p]||'/';}if(window.self!==window.top)document.addEventListener('DOMContentLoaded',()=>{const n=document.querySelector('nav');if(n)n.style.display='none';document.body.style.paddingTop='0';});</script>
</head>
<body>

<nav>
    <span class="nav-logo">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="white" stroke-width="1.5"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10A15.3 15.3 0 0 1 8 12 15.3 15.3 0 0 1 12 2z" stroke="white" stroke-width="1.5"/></svg>
        RSig
    </span>
    <a href="#" onclick="navTo('carte');return false">Carte</a>
    <a href="#" onclick="navTo('requetes');return false">Requêtes</a>
    <a href="#" class="active">RAA</a>
</nav>

<div class="page-content" style="max-width:1400px">
    <h1>Recueils des actes administratifs</h1>

    <?php if (!$connected): ?>
    <div class="alert alert-error">Base PostgreSQL non disponible. <a href="/api/db-check" target="_blank">→ Diagnostic</a></div>
    <?php else: ?>

    <!-- ═══ ÉTAT ET PILOTAGE DE LA VEILLE ═════════════════════ -->
    <section class="veille" id="veille" aria-live="polite">
        <div class="veille-etat">
            <span class="voyant" id="veille-voyant"></span>
            <div>
                <div class="veille-titre" id="veille-titre"></div>
                <div class="veille-detail" id="veille-detail"></div>
            </div>
        </div>
        <?php if ($isAdmin): ?>
        <div class="veille-commandes">
            <button class="btn-veille" id="veille-lancer" type="button">Lancer maintenant</button>
            <button class="btn-veille" id="veille-bascule" type="button"></button>
        </div>
        <?php endif; ?>
        <div class="veille-alerte" id="veille-alerte" hidden></div>
    </section>

    <!-- ═══ DÉCISIONS TROUVÉES ════════════════════════════════ -->
    <section class="decisions">
        <h2>Décisions repérées <span class="muet" id="decisions-nb"></span></h2>
        <ul id="decisions"></ul>
    </section>

    <?php if (!$publie): ?>
    <div class="alert">Le registre des liens et le détail par département apparaîtront ici après le premier passage de la veille.</div>
    <?php else: ?>

    <div class="tab-bar">
        <button class="tab active" data-tab="parametres">Paramètres locaux professionnels <?= $annee ?></button>
        <button class="tab" data-tab="liens">Liens RAA des préfectures</button>
    </div>

    <!-- ═══ VEILLE DES DÉCISIONS ═══════════════════════════════ -->
    <div class="tab-panel active" id="tab-parametres">
        <p class="raa-intro">
            Veille hebdomadaire des recueils publiés par les préfectures : repérage de la décision de mise à jour
            des paramètres départementaux d'évaluation des locaux professionnels (art. 1518 ter du CGI).
            Seul le sommaire des recueils est lu ; un recueil dont le sommaire n'est pas lisible est signalé
            « à vérifier à la main ».
            <?php if (count($annees) > 1): ?>
            Année :
            <?php foreach ($annees as $a): ?>
                <?= $a === $annee ? "<b>$a</b>" : '<a class="lien" style="display:inline" href="?annee=' . $a . '">' . $a . '</a>' ?>
            <?php endforeach; ?>
            <?php endif; ?>
        </p>

        <div class="compteurs" role="group" aria-label="Filtrer par constat" data-cible="table-parametres">
            <?php foreach ($libConstat as $k => $lib): ?>
            <button class="compteur" data-f="<?= $k ?>" aria-pressed="false"><b><?= $compte[$k] ?></b><span><?= $h($lib) ?></span></button>
            <?php endforeach; ?>
        </div>

        <div class="outils">
            <input type="search" placeholder="Département ou numéro" data-cible="table-parametres">
            <span class="nb" id="nb-table-parametres"></span>
        </div>

        <div class="table-wrap">
            <table id="table-parametres">
                <thead><tr><th>N°</th><th>Département</th><th>Constat</th><th>Recueil</th><th>Intitulé repéré</th><th>Dernière veille</th></tr></thead>
                <tbody>
                <?php foreach ($lignes as $l): $d = $l['d']; ?>
                    <tr data-f="<?= $l['constat'] ?>" data-q="<?= $h($d['code'] . ' ' . $d['nom']) ?>">
                        <td class="code"><?= $h($d['code']) ?></td>
                        <td class="nom"><?= $h($d['nom']) ?></td>
                        <td><span class="pastille p-<?= $l['constat'] ?>"><?= $h($libConstat[$l['constat']]) ?></span></td>
                        <td>
                            <?php // Une fois la décision repérée, les recueils illisibles du département ne sont plus utiles
                            foreach ($l['trouves'] ?: $l['aVerif'] as $r): ?>
                            <a class="lien" href="<?= $h($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= $h($r['titre']) ?></a>
                            <span class="detail">publié le <?= $fmtDate($r['date_recueil']) ?><?= $r['resultat'] !== 'trouve' ? ' · ' . $h($r['extrait'] ?: 'sommaire lu en partie') : '' ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="extrait">
                            <?php foreach ($l['trouves'] as $r): ?>
                            <?= $h($r['extrait']) ?><span class="detail">repéré le <?= $fmtDate($r['vu_le']) ?> par le <?= $h($r['source']) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php if ($l['s']): ?>
                            <?= $fmtDate($l['s']['derniere_veille']) ?><span class="detail"><?= $h($l['s']['bilan']) ?></span>
                            <?php else: ?><span class="muet">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══ REGISTRE DES LIENS ═════════════════════════════════ -->
    <div class="tab-panel" id="tab-liens">
        <p class="raa-intro">
            Page du recueil des actes administratifs de chaque préfecture : novembre (ou liste annuelle) et décembre,
            avec la preuve dont on dispose sur le bon fonctionnement du lien. Les pages de décembre sont construites
            d'après le modèle de novembre tant qu'elles n'existent pas.
        </p>

        <div class="outils">
            <input type="search" placeholder="Département ou numéro" data-cible="table-liens">
            <span class="nb" id="nb-table-liens"></span>
        </div>

        <div class="table-wrap">
            <table id="table-liens">
                <thead><tr><th>N°</th><th>Département</th><th>Rangement</th><th>Novembre</th><th>Décembre</th><th>Statut du lien</th></tr></thead>
                <tbody>
                <?php foreach ($deps as $d): ?>
                    <tr data-q="<?= $h($d['code'] . ' ' . $d['nom']) ?>">
                        <td class="code"><?= $h($d['code']) ?></td>
                        <td class="nom"><?= $h($d['nom']) ?></td>
                        <td class="muet"><?= $h($d['rangement']) ?></td>
                        <td><?php foreach ($d['url_novembre'] as $u): ?><a class="lien" href="<?= $h($u) ?>" target="_blank" rel="noopener noreferrer"><?= $h(preg_replace('#^https://(www\.)?#', '', $u)) ?></a><?php endforeach; ?>
                            <?= $d['url_novembre'] ? '' : '<span class="muet">—</span>' ?></td>
                        <td><?php foreach ($d['url_decembre'] as $u): ?><a class="lien" href="<?= $h($u) ?>" target="_blank" rel="noopener noreferrer"><?= $h(preg_replace('#^https://(www\.)?#', '', $u)) ?></a><?php endforeach; ?>
                            <?= $d['url_decembre'] ? '' : '<span class="muet">' . ($d['url_novembre'] ? 'même page (liste annuelle)' : '—') . '</span>' ?></td>
                        <td><span class="pastille p-<?= $h($d['statut']) ?>"><?= $h($libStatut[$d['statut']] ?? $d['statut']) ?></span>
                            <span class="detail"><?= $h($d['preuve']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; /* $publie */ ?>
    <?php endif; /* $connected */ ?>
</div>

<script>
// ── État et pilotage de la veille ─────────────────────────
const VEILLE_INITIALE = <?= json_encode($veille, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const FREQUENCES = <?= json_encode($frequences, JSON_UNESCAPED_UNICODE) ?>;
const STATUTS = { termine: 'cycle terminé', pause: 'en pause (plafond de requêtes atteint)', bloque: 'interrompu par un blocage des sites (nouvel essai toutes les heures)',
                  arrete: 'arrêté', erreur: 'erreur', interrompu: 'interrompu' };
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const dateFr = (d, heure = true) => d ? new Date(d.replace(' ', 'T')).toLocaleString('fr-FR',
    heure ? { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' } : { day: '2-digit', month: '2-digit', year: 'numeric' }) : '';
let minuteur = null;

function afficherVeille(v) {
    if (!v || !document.getElementById('veille')) return;
    const e = v.execution, voyant = document.getElementById('veille-voyant');
    let titre, detail;
    if (v.en_cours) {
        voyant.className = 'voyant v-encours';
        titre = 'Veille en cours';
        detail = `Département ${esc(e.departement || '…')} · ${e.departements_traites} département(s) traité(s) sur ce passage · `
               + `${e.requetes} requête(s) · démarré le ${dateFr(e.debut)}`;
    } else if (v.actif) {
        voyant.className = 'voyant v-active';
        titre = 'Veille active — 1 département par minute, 5 PDF par minute au plus ; nouveau cycle une semaine après la fin du précédent';
        detail = (v.prochain ? 'Prochain passage : ' + esc(v.prochain) + '.' : '')
               + (e ? ` Dernier passage le ${dateFr(e.debut)} : ${esc(STATUTS[e.statut] || e.statut)}, ${e.requetes} requête(s), ${e.trouves} décision(s) trouvée(s).` : '');
    } else {
        voyant.className = 'voyant v-arretee';
        titre = 'Veille arrêtée';
        detail = e ? `Dernier passage le ${dateFr(e.debut)} : ${esc(STATUTS[e.statut] || e.statut)}${e.message ? ' — ' + esc(e.message) : ''}.` : 'Aucun passage pour le moment.';
    }
    if (!v.cycle.termine && !v.en_cours) detail += ` Cycle commencé le ${dateFr(v.cycle.debut)} : ${v.cycle.curseur} département(s) sur 101 passés en revue.`;
    document.getElementById('veille-titre').innerHTML = titre;
    document.getElementById('veille-detail').innerHTML = detail;

    const alerte = document.getElementById('veille-alerte');
    alerte.hidden = !((v.actif || v.lancement_demande) && !v.declencheur_ok && !v.en_cours);
    alerte.innerHTML = 'Le déclencheur automatique ne s\'est pas manifesté depuis plus de 20 minutes'
        + (v.dernier_declenchement ? ` (dernier signe le ${dateFr(v.dernier_declenchement)})` : '')
        + ' : la veille ne partira pas seule. Vérifier la tâche planifiée « Veille RAA » sur le poste ou le serveur.';

    if (document.getElementById('veille-bascule')) {
        const b = document.getElementById('veille-bascule');
        b.textContent = v.actif ? 'Arrêter la veille' : 'Activer la veille';
        b.dataset.action = v.actif ? 'arreter' : 'activer';
        b.classList.toggle('btn-arret', v.actif);
        document.getElementById('veille-lancer').disabled = v.en_cours;
    }

    const liste = document.getElementById('decisions');
    document.getElementById('decisions-nb').textContent = `(${v.decisions.length} — année ${v.annee})`;
    liste.innerHTML = v.decisions.length ? v.decisions.map(d => `<li>
        <span class="dec-dep">${esc(d.code)} ${esc(d.nom || '')}</span>
        <a class="lien" href="${esc(d.url)}" target="_blank" rel="noopener noreferrer">${esc(d.titre)}</a>
        <span class="detail">publié le ${dateFr(d.date_recueil, false)} · ${esc(d.extrait)} · repéré le ${dateFr(d.vu_le)}</span></li>`).join('')
        : '<li class="muet">Aucune décision repérée pour le moment.</li>';

    // Rafraîchissement : rapide pendant un passage, lent sinon.
    clearTimeout(minuteur);
    minuteur = setTimeout(rafraichir, v.en_cours ? 10000 : 60000);
}

let etaitEnCours = VEILLE_INITIALE?.en_cours;
function rafraichir() {
    fetch('/api/raa/etat').then(r => r.ok ? r.json() : null).then(v => {
        if (!v) return;
        // Fin d'un passage : on recharge pour mettre à jour les tableaux par département.
        if (etaitEnCours && !v.en_cours) { location.reload(); return; }
        etaitEnCours = v.en_cours;
        afficherVeille(v);
    }).catch(() => { minuteur = setTimeout(rafraichir, 60000); });
}

function commander(action, extra = {}) {
    return fetch('/api/raa/veille', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...extra }),
    }).then(r => r.json()).then(v => {
        if (v.error) { alert(v.error); return; }
        afficherVeille(v);
    });
}
document.getElementById('veille-lancer')?.addEventListener('click', () => commander('lancer'));
document.getElementById('veille-bascule')?.addEventListener('click', e => {
    if (e.target.dataset.action === 'arreter' && !confirm('Arrêter la veille ? Un passage en cours s\'arrêtera avant sa prochaine requête.')) return;
    commander(e.target.dataset.action);
});
afficherVeille(VEILLE_INITIALE);

// Onglets
document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(x => x.classList.toggle('active', x === t));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + t.dataset.tab));
}));

// Filtres : recherche texte + compteur cliquable, par tableau
const filtres = {};
function filtrer(id) {
    const f = filtres[id] || {};
    const t = (f.terme || '').trim().toLowerCase();
    let n = 0;
    document.querySelectorAll('#' + id + ' tbody tr').forEach(tr => {
        const ok = (!f.constat || tr.dataset.f === f.constat) && (!t || tr.dataset.q.toLowerCase().includes(t));
        tr.hidden = !ok;
        n += ok;
    });
    const nb = document.getElementById('nb-' + id);
    if (nb) nb.textContent = n + ' département' + (n > 1 ? 's' : '');
}
document.querySelectorAll('input[type=search][data-cible]').forEach(i => i.addEventListener('input', () => {
    (filtres[i.dataset.cible] ??= {}).terme = i.value;
    filtrer(i.dataset.cible);
}));
document.querySelectorAll('.compteurs').forEach(g => g.querySelectorAll('.compteur').forEach(b => b.addEventListener('click', () => {
    const f = (filtres[g.dataset.cible] ??= {});
    f.constat = f.constat === b.dataset.f ? null : b.dataset.f;
    g.querySelectorAll('.compteur').forEach(x => x.setAttribute('aria-pressed', String(x.dataset.f === f.constat)));
    filtrer(g.dataset.cible);
})));
['table-parametres', 'table-liens'].forEach(id => document.getElementById(id) && filtrer(id));
</script>
</body>
</html>
