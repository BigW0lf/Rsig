<?php
/**
 * Synchronisation Dataverse → PostgreSQL
 * Table cible unique : crm_dossiers (dossier + site + account fusionnés)
 *
 * Règles géom :
 *  1. apo_x / apo_y (SRID 3857) depuis apo_sites → transform WGS84
 *  2. Coords depuis dossier_acc_geo (SRID 2154) via site_id
 *  3. geom jamais écrasée une fois définie
 *
 * Règles UPSERT :
 *  - numero jamais modifié
 *  - geom jamais écrasée
 *  - tous les autres champs mis à jour si modifiedon a changé
 */

function crmSync(PDO $db): array {
    $log = ['dossiers' => 0, 'errors' => []];

    $token = getAccessToken();
    if (!$token) throw new \RuntimeException("Impossible d'obtenir un token Dataverse");

    // Filtre incrémental sur modifiedon
    $lastOk = $db->query("SELECT MAX(started_at) FROM crm_sync_log WHERE status='ok'")->fetchColumn();
    $since  = $lastOk ? '&$filter=modifiedon gt ' . date('Y-m-d\TH:i:s\Z', strtotime($lastOk)) : '';

    // ── Migration DDL (hors transaction) ─────────────────────────────────────
    $db->exec("CREATE TABLE IF NOT EXISTS crm_dossiers (
        dossierid        UUID PRIMARY KEY,
        numero           TEXT,
        reference_client TEXT,
        client_name      TEXT,
        account_id       TEXT,
        account_rtx_code TEXT,
        account_cp       TEXT,
        auditeur         TEXT,
        produit          TEXT,
        phase            TEXT,
        etat             TEXT,
        date_demande     DATE,
        date_remise      DATE,
        date_preetudie   DATE,
        modifiedon       TIMESTAMPTZ,
        site_id          UUID,
        adresse          TEXT,
        adresse_norm     TEXT,
        ville            TEXT,
        code_postal      TEXT,
        code_insee       TEXT,
        section          TEXT,
        parcelle         TEXT,
        lot              TEXT,
        montant_tf       NUMERIC,
        type_activite    TEXT,
        geom             GEOMETRY(Point,4326),
        synced_at        TIMESTAMPTZ DEFAULT now()
    )");
    foreach (['crm_dossiers_geom_idx'=>'USING GIST(geom)', 'crm_dossiers_insee_idx'=>'(code_insee)'] as $idx => $def) {
        try { $db->exec("CREATE INDEX IF NOT EXISTS $idx ON crm_dossiers $def"); } catch (\Throwable) {}
    }

    // ── Cache coords fallback depuis dossier_acc_geo ───────────────────────
    $existingCoords = [];
    try {
        $rows = $db->query("
            SELECT site_id,
                   ST_X(ST_Transform(geom::geometry, 4326)) AS lon,
                   ST_Y(ST_Transform(geom::geometry, 4326)) AS lat
            FROM dossier_acc_geo
            WHERE site_id IS NOT NULL AND geom IS NOT NULL
        ")->fetchAll();
        foreach ($rows as $r) $existingCoords[$r['site_id']] = [(float)$r['lon'], (float)$r['lat']];
    } catch (\Throwable) {}

    // ── Cache accounts ────────────────────────────────────────────────────────
    $accountsMap = [];
    foreach (_fetchAllPages($token, 'https://rtaxes.api.crm4.dynamics.com/api/data/v9.2/accounts?$select=accountid,rtx_code,address1_postalcode,name') as $acc) {
        $aid = $acc['accountid'] ?? null;
        if (!$aid) continue;
        $accountsMap[$aid] = [
            'name'     => $acc['name']                ?? null,
            'rtx_code' => $acc['rtx_code']            ?? null,
            'cp'       => $acc['address1_postalcode'] ?? null,
        ];
    }

    // ── Cache sites depuis Dataverse ──────────────────────────────────────────
    $sitesMap = [];
    $siteFields = 'apo_siteid,apo_name,apo_adresse,apo_adressenormalisee,apo_ville,apo_codepostal,apo_codeinsee,apo_section,apo_parcelle,apo_lot,apo_montanttaxefonciere,apo_typeactivite,apo_x,apo_y';
    foreach (_fetchAllPages($token, "https://rtaxes.api.crm4.dynamics.com/api/data/v9.2/apo_sites?\$select={$siteFields}") as $site) {
        $sid = $site['apo_siteid'] ?? null;
        if (!$sid) continue;
        $sitesMap[$sid] = $site;
    }

    // ── UPSERT dossiers ───────────────────────────────────────────────────────
    $phaseMap = [
        '921160000' => '01 - Ouverture',
        '921160001' => '02a - Attente docs cadastraux',
        '921160002' => '03 - Pré étude',
        '921160003' => '04a - Visite',
        '921160005' => '05 - Etude',
        '921160006' => '06 - Validation client',
        '921160007' => '11 - Suspension',
        '921160008' => '12 - Clôture',
        '921160009' => '07a - Instruction CX',
        '921160010' => '09 - Vérif N+1',
        '921160011' => '08 - En attente facturation',
        '921160012' => '10 - En attente encaissement',
        '921160013' => '07b - Instruction Juridictionnelle',
        '921160014' => '02b - Attente docs clients',
        '921160015' => '13 - Annulé',
    ];
    $etatMap = [
        '921160000' => '+',
        '921160001' => '-',
        '921160002' => 'Ind.',
    ];
    $produitMap = [
        '921160000' => 'TF',       '921160001' => 'TF',    '921160002' => 'CFE',
        '921160003' => 'TF + CFE', '921160004' => 'TA',    '921160005' => 'TSB',
        '921160006' => 'Mesurage', '921160007' => 'Autre',
    ];

    $stmt = $db->prepare("
        INSERT INTO crm_dossiers (
            dossierid, numero, reference_client, client_name,
            account_id, account_rtx_code, account_cp,
            auditeur, produit, phase, etat,
            date_demande, date_remise, date_preetudie, modifiedon,
            site_id, adresse, adresse_norm, ville, code_postal, code_insee,
            section, parcelle, lot, montant_tf, type_activite, geom
        ) VALUES (
            :dossierid, :numero, :reference_client, :client_name,
            :account_id, :account_rtx_code, :account_cp,
            :auditeur, :produit, :phase, :etat,
            :date_demande, :date_remise, :date_preetudie, :modifiedon,
            :site_id, :adresse, :adresse_norm, :ville, :code_postal, :code_insee,
            :section, :parcelle, :lot, :montant_tf, :type_activite,
            CASE
                WHEN :x3857::double precision IS NOT NULL AND :y3857::double precision IS NOT NULL
                THEN ST_Transform(ST_SetSRID(ST_MakePoint(:x3857::double precision, :y3857::double precision), 3857), 4326)
                WHEN :lon::double precision IS NOT NULL AND :lat::double precision IS NOT NULL
                THEN ST_SetSRID(ST_MakePoint(:lon::double precision, :lat::double precision), 4326)
                ELSE NULL
            END
        )
        ON CONFLICT (dossierid) DO UPDATE SET
            -- numero jamais modifié
            reference_client = EXCLUDED.reference_client,
            client_name      = EXCLUDED.client_name,
            account_id       = EXCLUDED.account_id,
            account_rtx_code = EXCLUDED.account_rtx_code,
            account_cp       = EXCLUDED.account_cp,
            auditeur         = EXCLUDED.auditeur,
            produit          = EXCLUDED.produit,
            phase            = EXCLUDED.phase,
            etat             = EXCLUDED.etat,
            date_demande     = EXCLUDED.date_demande,
            date_remise      = EXCLUDED.date_remise,
            date_preetudie   = EXCLUDED.date_preetudie,
            modifiedon       = EXCLUDED.modifiedon,
            site_id          = EXCLUDED.site_id,
            adresse          = EXCLUDED.adresse,
            adresse_norm     = EXCLUDED.adresse_norm,
            ville            = EXCLUDED.ville,
            code_postal      = EXCLUDED.code_postal,
            code_insee       = EXCLUDED.code_insee,
            section          = EXCLUDED.section,
            parcelle         = EXCLUDED.parcelle,
            lot              = EXCLUDED.lot,
            montant_tf       = EXCLUDED.montant_tf,
            type_activite    = EXCLUDED.type_activite,
            synced_at        = now(),
            -- geom : on ne remplace que si elle est NULL (jamais écraser une geom existante)
            geom             = COALESCE(crm_dossiers.geom, EXCLUDED.geom)
    ");

    $dFields = implode(',', [
        'apo_dossierid', 'apo_name', 'apo_numerodossier', 'apo_referenceclient', 'apo_ville',
        '_apo_site_dossier_value', '_apo_proprietaires_value', '_apo_auditeur1_value',
        'apo_datededemande', 'apo_dateremiseauclient', 'apo_datedepassageenpreetude',
        'modifiedon', 'apo_produit', 'apo_phasedossier', 'apo_etatdossier',
    ]);

    $db->beginTransaction();
    try {
        foreach (_fetchAllPages($token, "https://rtaxes.api.crm4.dynamics.com/api/data/v9.2/apo_dossiers?\$select={$dFields}{$since}") as $dos) {
            $siteId    = $dos['_apo_site_dossier_value'] ?? null;
            $accountId = $dos['_apo_proprietaires_value'] ?? null;

            // Données site
            $site = $siteId ? ($sitesMap[$siteId] ?? []) : [];
            $x3857 = isset($site['apo_x']) && $site['apo_x'] !== '' ? (float)$site['apo_x'] : null;
            $y3857 = isset($site['apo_y']) && $site['apo_y'] !== '' ? (float)$site['apo_y'] : null;
            $lon = null; $lat = null;
            if ($x3857 === null && $siteId && isset($existingCoords[$siteId])) {
                [$lon, $lat] = $existingCoords[$siteId];
            }

            // Données account
            $acc        = $accountId ? ($accountsMap[$accountId] ?? []) : [];
            $clientName = $dos['_apo_proprietaires_value@OData.Community.Display.V1.FormattedValue'] ?? null;
            $auditeur   = $dos['_apo_auditeur1_value@OData.Community.Display.V1.FormattedValue']     ?? null;
            if (!$clientName && isset($acc['name'])) $clientName = $acc['name'];

            $phaseCode = (string)($dos['apo_phasedossier'] ?? '');
            $etatCode  = (string)($dos['apo_etatdossier']  ?? '');
            $prodCode  = (string)($dos['apo_produit']      ?? '');

            $stmt->execute([
                ':dossierid'        => $dos['apo_dossierid']               ?? null,
                ':numero'           => $dos['apo_numerodossier']           ?? null,
                ':reference_client' => $dos['apo_referenceclient']         ?? null,
                ':client_name'      => _cleanStr($clientName),
                ':account_id'       => $accountId,
                ':account_rtx_code' => $acc['rtx_code'] ?? null,
                ':account_cp'       => $acc['cp']        ?? null,
                ':auditeur'         => _cleanStr($auditeur),
                ':produit'          => $prodCode  !== '' ? ($produitMap[$prodCode]  ?? null) : null,
                ':phase'            => $phaseCode !== '' ? ($phaseMap[$phaseCode]   ?? null) : null,
                ':etat'             => $etatCode  !== '' ? ($etatMap[$etatCode]     ?? null) : null,
                ':date_demande'     => isset($dos['apo_datededemande'])           ? substr($dos['apo_datededemande'], 0, 10)           : null,
                ':date_remise'      => isset($dos['apo_dateremiseauclient'])      ? substr($dos['apo_dateremiseauclient'], 0, 10)      : null,
                ':date_preetudie'   => isset($dos['apo_datedepassageenpreetude']) ? substr($dos['apo_datedepassageenpreetude'], 0, 10) : null,
                ':modifiedon'       => $dos['modifiedon'] ?? null,
                ':site_id'          => $siteId,
                ':adresse'          => _cleanStr($site['apo_adresse']           ?? null),
                ':adresse_norm'     => _cleanStr($site['apo_adressenormalisee'] ?? null),
                ':ville'            => _cleanStr($site['apo_ville']             ?? null),
                ':code_postal'      => _cleanStr($site['apo_codepostal']        ?? null),
                ':code_insee'       => _cleanStr($site['apo_codeinsee']         ?? null),
                ':section'          => _cleanStr($site['apo_section']           ?? null),
                ':parcelle'         => _cleanStr($site['apo_parcelle']          ?? null),
                ':lot'              => _cleanStr($site['apo_lot']               ?? null),
                ':montant_tf'       => isset($site['apo_montanttaxefonciere']) ? (float)$site['apo_montanttaxefonciere'] : null,
                ':type_activite'    => _cleanStr($site['apo_typeactivite']      ?? null),
                ':x3857'            => $x3857,
                ':y3857'            => $y3857,
                ':lon'              => $lon,
                ':lat'              => $lat,
            ]);
            $log['dossiers']++;
        }
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $log;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function _cleanStr(?string $s): ?string {
    if ($s === null) return null;
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    return iconv('UTF-8', 'UTF-8//IGNORE', $s) ?: null;
}

function _fetchAllPages(string $token, string $url): \Generator {
    $next = $url;
    while ($next) {
        $ch = curl_init($next);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                "Accept: application/json",
                "OData-Version: 4.0",
                "Prefer: odata.maxpagesize=1000,odata.include-annotations=\"OData.Community.Display.V1.FormattedValue\"",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) break;
        if (!mb_check_encoding($body, 'UTF-8')) $body = iconv('UTF-8', 'UTF-8//IGNORE', $body);
        $data = json_decode($body, true);
        foreach ($data['value'] ?? [] as $record) yield $record;
        $next = $data['@odata.nextLink'] ?? null;
    }
}
