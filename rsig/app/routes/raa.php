<?php
// ── RAA — liens des préfectures et veille des paramètres locaux professionnels ──
// Les résultats sont écrits par le projet de veille RAA (veille_tick.py, lancé toutes les 5 minutes
// par une tâche planifiée) ; le RSIG affiche l'état et pilote la veille via raa_veille_config.

const RAA_FREQUENCES = ['quotidien' => '1 fois par jour', 'hebdomadaire' => '1 fois par semaine', 'bimensuel' => '2 fois par mois'];
const RAA_PERIODES   = ['quotidien' => 'P1D', 'hebdomadaire' => 'P7D', 'bimensuel' => 'P15D'];

/** Tables de pilotage (mêmes définitions que publier_bdd.py, côté veille). */
function raaTablesPilotage(PDO $db): void {
    if ($db->query("SELECT to_regclass('public.raa_veille_execution')")->fetchColumn()) return;
    $db->exec("CREATE TABLE IF NOT EXISTS raa_veille_config (
        id                INTEGER PRIMARY KEY DEFAULT 1 CHECK (id = 1),
        actif             BOOLEAN NOT NULL DEFAULT false,
        frequence         TEXT NOT NULL DEFAULT 'hebdomadaire',
        annee             INTEGER NOT NULL DEFAULT 2026,
        publies_depuis    DATE DEFAULT '2026-11-15',
        lancer_maintenant BOOLEAN NOT NULL DEFAULT false,
        cycle_debut       TIMESTAMP,
        cycle_curseur     INTEGER NOT NULL DEFAULT 0,
        cycle_termine     BOOLEAN NOT NULL DEFAULT true,
        reprise_apres     TIMESTAMP,
        dernier_declenchement TIMESTAMP,
        maj_le            TIMESTAMP,
        maj_par           TEXT
    )");
    $db->exec("INSERT INTO raa_veille_config (id) VALUES (1) ON CONFLICT DO NOTHING");
    $db->exec("CREATE TABLE IF NOT EXISTS raa_veille_execution (
        id                   SERIAL PRIMARY KEY,
        debut                TIMESTAMP NOT NULL DEFAULT now(),
        fin                  TIMESTAMP,
        statut               TEXT NOT NULL,
        heartbeat            TIMESTAMP,
        departement          TEXT,
        departements_traites INTEGER NOT NULL DEFAULT 0,
        requetes             INTEGER NOT NULL DEFAULT 0,
        trouves              INTEGER NOT NULL DEFAULT 0,
        message              TEXT
    )");
}

/** État de la veille : réglages, passage en cours ou dernier passage, prochaine échéance, décisions trouvées. */
function raaEtatVeille(PDO $db): array {
    raaTablesPilotage($db);
    $cfg  = $db->query("SELECT * FROM raa_veille_config WHERE id = 1")->fetch();
    $exec = $db->query("SELECT * FROM raa_veille_execution ORDER BY id DESC LIMIT 1")->fetch() ?: null;
    // Passage silencieux depuis 15 min : le poste a été éteint ou le processus a été tué.
    $enCours = $exec && $exec['statut'] === 'en_cours'
        && strtotime($exec['heartbeat'] ?? $exec['debut']) > time() - 15 * 60;

    $fmt = fn($d) => date('d/m/Y à H\hi', is_int($d) ? $d : strtotime($d));
    if ($enCours) {
        $prochain = null;
    } elseif ($cfg['lancer_maintenant']) {
        $prochain = 'lancement demandé : départ dans les 5 minutes';
    } elseif (!$cfg['actif']) {
        $prochain = null;
    } elseif (!$cfg['cycle_termine']) {
        $prochain = $cfg['reprise_apres'] ? 'suite du cycle le ' . $fmt($cfg['reprise_apres'])
                                          : 'suite du cycle au prochain déclenchement';
    } elseif ($cfg['cycle_debut']) {
        $d = (new DateTime($cfg['cycle_debut']))->add(new DateInterval(RAA_PERIODES[$cfg['frequence']] ?? 'P7D'));
        $prochain = $d->getTimestamp() <= time() ? 'nouveau cycle au prochain déclenchement' : 'nouveau cycle le ' . $fmt($d->getTimestamp());
    } else {
        $prochain = 'premier cycle au prochain déclenchement';
    }

    $decisions = [];
    if ($db->query("SELECT to_regclass('public.raa_recueils')")->fetchColumn()) {
        $stmt = $db->prepare("SELECT r.code, d.nom, r.titre, r.url, r.date_recueil, r.extrait, r.vu_le
                              FROM raa_recueils r LEFT JOIN raa_departements d USING (code)
                              WHERE r.resultat = 'trouve' AND EXTRACT(YEAR FROM r.date_recueil) = :a
                              ORDER BY r.vu_le DESC");
        $stmt->execute([':a' => $cfg['annee']]);
        $decisions = $stmt->fetchAll();
    }
    $tick = $cfg['dernier_declenchement'] ? strtotime($cfg['dernier_declenchement']) : null;
    return [
        'actif'          => (bool)$cfg['actif'],
        'frequence'      => $cfg['frequence'],
        'annee'          => (int)$cfg['annee'],
        'en_cours'       => $enCours,
        'execution'      => $exec,
        'cycle'          => ['debut' => $cfg['cycle_debut'], 'curseur' => (int)$cfg['cycle_curseur'], 'termine' => (bool)$cfg['cycle_termine']],
        'prochain'       => $prochain,
        // Déclencheur (tâche planifiée, toutes les 5 min) muet depuis 20 min : la veille ne partira pas.
        'declencheur_ok' => $tick && $tick > time() - 1200,
        'lancement_demande' => (bool)$cfg['lancer_maintenant'],
        'dernier_declenchement' => $cfg['dernier_declenchement'],
        'maj_par'        => $cfg['maj_par'],
        'decisions'      => $decisions,
    ];
}

Flight::route('GET /raa', function () {
    requireAuth();
    trackVisit('raa');
    $db = getDb();
    $vue = ['connected' => $db !== null, 'publie' => false, 'annee' => (int)date('Y'), 'annees' => [],
            'deps' => [], 'recueils' => [], 'suivi' => [], 'veille' => null, 'isAdmin' => isAdmin(),
            'frequences' => RAA_FREQUENCES];
    if (!$db) { Flight::render('raa', $vue); return; }

    $vue['veille'] = raaEtatVeille($db);
    $vue['annee']  = $vue['veille']['annee'];
    if ($db->query("SELECT to_regclass('public.raa_departements')")->fetchColumn()) {
        $vue['publie'] = true;
        $annees = $db->query("SELECT DISTINCT annee FROM raa_veille_suivi ORDER BY annee DESC")->fetchAll(PDO::FETCH_COLUMN);
        $vue['annees'] = array_values(array_unique(array_merge([$vue['annee']], array_map('intval', $annees))));
        $demandee = (int)(Flight::request()->query['annee'] ?? 0);
        if (in_array($demandee, $vue['annees'], true)) $vue['annee'] = $demandee;

        $deps = $db->query("SELECT code, nom, rangement, statut, preuve,
                                   array_to_json(url_novembre) AS url_novembre, array_to_json(url_decembre) AS url_decembre
                            FROM raa_departements ORDER BY ordre")->fetchAll();
        foreach ($deps as &$d) {
            $d['url_novembre'] = json_decode($d['url_novembre'] ?? '[]', true) ?: [];
            $d['url_decembre'] = json_decode($d['url_decembre'] ?? '[]', true) ?: [];
        }
        unset($d);
        $vue['deps'] = $deps;

        $stmt = $db->prepare("SELECT url, code, titre, date_recueil, resultat, source, extrait, vu_le
                              FROM raa_recueils
                              WHERE resultat IN ('trouve', 'illisible', 'incomplet')
                                AND EXTRACT(YEAR FROM date_recueil) = :annee
                              ORDER BY date_recueil");
        $stmt->execute([':annee' => $vue['annee']]);
        $vue['recueils'] = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT code, derniere_veille, bilan FROM raa_veille_suivi WHERE annee = :annee");
        $stmt->execute([':annee' => $vue['annee']]);
        foreach ($stmt as $s) $vue['suivi'][$s['code']] = $s;
    }
    Flight::render('raa', $vue);
});

// État de la veille, interrogé régulièrement par l'onglet pendant un passage.
Flight::route('GET /api/raa/etat', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    Flight::json(raaEtatVeille($db));
});

// Pilotage — POST : réservé à l'administrateur par le filtre global d'index.php.
Flight::route('POST /api/raa/veille', function () {
    $db = getDb(); if (!$db) { Flight::json(['error' => 'DB KO'], 503); return; }
    raaTablesPilotage($db);
    $data   = Flight::request()->data;
    $action = (string)($data['action'] ?? '');
    $par    = $_SESSION['user_email'] ?? 'admin';
    $maj    = $db->prepare("UPDATE raa_veille_config SET maj_le = now(), maj_par = :par WHERE id = 1");

    switch ($action) {
        case 'frequence':
            $f = (string)($data['frequence'] ?? '');
            if (!isset(RAA_FREQUENCES[$f])) { Flight::json(['error' => 'Fréquence inconnue'], 400); return; }
            $db->prepare("UPDATE raa_veille_config SET frequence = :f WHERE id = 1")->execute([':f' => $f]);
            break;
        case 'activer':
            $db->exec("UPDATE raa_veille_config SET actif = true WHERE id = 1");
            break;
        case 'arreter':
            // Le passage en cours le constate avant sa requête suivante et s'arrête proprement.
            $db->exec("UPDATE raa_veille_config SET actif = false, lancer_maintenant = false WHERE id = 1");
            break;
        case 'lancer':
            // Pris en charge par le déclencheur à son prochain passage (toutes les 5 minutes) :
            // PHP ne lance pas Python lui-même (bloquant sous Apache/Windows, absent du conteneur en production).
            $db->exec("UPDATE raa_veille_config SET actif = true, lancer_maintenant = true WHERE id = 1");
            break;
        default:
            Flight::json(['error' => 'Action inconnue'], 400);
            return;
    }
    $maj->execute([':par' => $par]);
    Flight::json(['ok' => true] + raaEtatVeille($db));
});
