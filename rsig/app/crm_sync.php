<?php
/**
 * Synchronisation Dataverse → PostgreSQL (UPSERT incrémental)
 *
 * Coordonnées priorité :
 *  1. apo_x / apo_y (Web Mercator SRID 3857) dans apo_sites → ST_Transform vers WGS84
 *  2. Coords de dossier_acc_geo (SRID 2154, Lambert 93) → site_id comme clé
 *  3. Géocodage IGN uniquement si aucune source n'a de coord
 *
 * Client : FormattedValue OData — aucun appel HTTP supplémentaire
 */

function crmSync(PDO $db): array {
    $log = ['sites' => 0, 'dossiers' => 0, 'geocoded' => 0, 'errors' => []];

    $token = getAccessToken();
    if (!$token) throw new \RuntimeException("Impossible d'obtenir un token Dataverse");

    // Date de la dernière sync réussie (pour filtre incrémental)
    $lastOk = $db->query("SELECT MAX(started_at) FROM crm_sync_log WHERE status='ok'")->fetchColumn();
    $since  = $lastOk ? '&$filter=modifiedon gt ' . date('Y-m-d\TH:i:s\Z', strtotime($lastOk)) : '';

    // Cache coords WGS84 depuis dossier_acc_geo (géom SRID 2154) : site_id → [lon, lat]
    $existingCoords = [];
    try {
        $rows = $db->query("
            SELECT site_id,
                   ST_X(ST_Transform(geom::geometry, 4326)) AS lon,
                   ST_Y(ST_Transform(geom::geometry, 4326)) AS lat
            FROM dossier_acc_geo
            WHERE site_id IS NOT NULL AND geom IS NOT NULL
        ")->fetchAll();
        foreach ($rows as $r) {
            $existingCoords[$r['site_id']] = [(float)$r['lon'], (float)$r['lat']];
        }
    } catch (\Throwable) {}

    // Cache accounts GUID → name (pour le fallback si FormattedValue absent)
    $accountCache = [];

    $db->beginTransaction();
    try {

        // ── 1. SITES (UPSERT incrémental) ─────────────────────
        $siteStmt = $db->prepare("
            INSERT INTO crm_sites_mirror
                (siteid, nom, adresse, adresse_norm, ville, code_postal, code_insee,
                 section, parcelle, lot, montant_tf, type_activite, geom, raw)
            VALUES (
                :siteid, :nom, :adresse, :adresse_norm, :ville, :code_postal, :code_insee,
                :section, :parcelle, :lot, :montant_tf, :type_activite,
                CASE
                    WHEN :x_l93::double precision IS NOT NULL
                     AND :y_l93::double precision IS NOT NULL
                    THEN ST_Transform(
                             ST_SetSRID(ST_MakePoint(:x_l93::double precision, :y_l93::double precision), 3857),
                             4326
                         )
                    WHEN :lon::double precision IS NOT NULL
                     AND :lat::double precision IS NOT NULL
                    THEN ST_SetSRID(ST_MakePoint(:lon::double precision, :lat::double precision), 4326)
                    ELSE NULL
                END,
                :raw
            )
            ON CONFLICT (siteid) DO UPDATE SET
                nom           = EXCLUDED.nom,
                adresse       = EXCLUDED.adresse,
                adresse_norm  = EXCLUDED.adresse_norm,
                ville         = EXCLUDED.ville,
                code_postal   = EXCLUDED.code_postal,
                code_insee    = EXCLUDED.code_insee,
                section       = EXCLUDED.section,
                parcelle      = EXCLUDED.parcelle,
                lot           = EXCLUDED.lot,
                montant_tf    = EXCLUDED.montant_tf,
                type_activite = EXCLUDED.type_activite,
                raw           = EXCLUDED.raw
                -- geom intentionnellement exclu : jamais écrasée une fois définie
        ");

        $siteFields = implode(',', [
            'apo_siteid', 'apo_name', 'apo_adresse', 'apo_adressenormalisee',
            'apo_ville', 'apo_codepostal', 'apo_codeinsee',
            'apo_section', 'apo_parcelle', 'apo_lot',
            'apo_montanttaxefonciere', 'apo_typeactivite',
            'apo_x', 'apo_y', 'modifiedon',
        ]);
        $url = "https://rtaxes.api.crm4.dynamics.com/api/data/v9.2/apo_sites?\$select={$siteFields}{$since}";

        foreach (_fetchAllPages($token, $url) as $site) {
            $siteId = $site['apo_siteid'] ?? null;
            // apo_x/apo_y Dataverse : Web Mercator SRID 3857
            $x3857 = isset($site['apo_x']) && $site['apo_x'] !== '' ? (float)$site['apo_x'] : null;
            $y3857 = isset($site['apo_y']) && $site['apo_y'] !== '' ? (float)$site['apo_y'] : null;

            $lat = null; $lon = null;

            if ($x3857 === null || $y3857 === null) {
                if ($siteId && isset($existingCoords[$siteId])) {
                    // Source 2 : coords de dossier_acc_geo (déjà en WGS84)
                    [$lon, $lat] = $existingCoords[$siteId];
                } else {
                    // Source 3 : géocodage IGN
                    $adresse = $site['apo_adresse']    ?? null;
                    $ville   = $site['apo_ville']      ?? null;
                    $cp      = $site['apo_codepostal'] ?? null;
                    if ($adresse && ($ville || $cp)) {
                        [$lat, $lon] = _geocode($adresse, $ville, $cp);
                        if ($lat !== null) $log['geocoded']++;
                    }
                }
            }

            $siteStmt->execute([
                ':siteid'        => _cleanStr($siteId),
                ':nom'           => _cleanStr($site['apo_name']              ?? null),
                ':adresse'       => _cleanStr($site['apo_adresse']           ?? null),
                ':adresse_norm'  => _cleanStr($site['apo_adressenormalisee'] ?? null),
                ':ville'         => _cleanStr($site['apo_ville']             ?? null),
                ':code_postal'   => _cleanStr($site['apo_codepostal']        ?? null),
                ':code_insee'    => _cleanStr($site['apo_codeinsee']         ?? null),
                ':section'       => _cleanStr($site['apo_section']           ?? null),
                ':parcelle'      => _cleanStr($site['apo_parcelle']          ?? null),
                ':lot'           => _cleanStr($site['apo_lot']               ?? null),
                ':montant_tf'    => isset($site['apo_montanttaxefonciere'])
                                        ? (float)$site['apo_montanttaxefonciere'] : null,
                ':type_activite' => _cleanStr($site['apo_typeactivite']      ?? null),
                ':x_l93'         => $x3857,
                ':y_l93'         => $y3857,
                ':lat'           => $lat,
                ':lon'           => $lon,
                ':raw'           => json_encode($site, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
            ]);
            $log['sites']++;
        }

        // ── 2. DOSSIERS (UPSERT incrémental) ──────────────────
        try {
            $db->exec("ALTER TABLE crm_dossiers_mirror ADD COLUMN IF NOT EXISTS client_name TEXT");
        } catch (\Throwable) {}

        $dossierStmt = $db->prepare("
            INSERT INTO crm_dossiers_mirror
                (dossierid, numero, nom, reference_client, ville, site_id,
                 client_name, date_demande, date_remise, date_preetudie, modifiedon, raw)
            VALUES (
                :dossierid, :numero, :nom, :reference_client, :ville, :site_id,
                :client_name, :date_demande, :date_remise, :date_preetudie, :modifiedon, :raw
            )
            ON CONFLICT (dossierid) DO UPDATE SET
                -- numero intentionnellement exclu : jamais modifié
                nom              = EXCLUDED.nom,
                reference_client = EXCLUDED.reference_client,
                ville            = EXCLUDED.ville,
                site_id          = EXCLUDED.site_id,
                client_name      = EXCLUDED.client_name,
                date_demande     = EXCLUDED.date_demande,
                date_remise      = EXCLUDED.date_remise,
                date_preetudie   = EXCLUDED.date_preetudie,
                modifiedon       = EXCLUDED.modifiedon,
                raw              = EXCLUDED.raw
        ");

        $dFields = implode(',', [
            'apo_dossierid', 'apo_name', 'apo_numerodossier',
            'apo_referenceclient', 'apo_ville',
            '_apo_site_dossier_value', '_apo_proprietaires_value',
            'apo_datededemande', 'apo_dateremiseauclient',
            'apo_datedepassageenpreetude', 'modifiedon',
        ]);
        $dUrl = "https://rtaxes.api.crm4.dynamics.com/api/data/v9.2/apo_dossiers?\$select={$dFields}{$since}";

        foreach (_fetchAllPages($token, $dUrl) as $dos) {
            $siteId = $dos['_apo_site_dossier_value'] ?? null;

            // Nom du client via FormattedValue OData — zéro appel HTTP supplémentaire
            $clientName = $dos['_apo_proprietaires_value@OData.Community.Display.V1.FormattedValue'] ?? null;
            if ($clientName === null) {
                $accountId = $dos['_apo_proprietaires_value'] ?? null;
                if ($accountId) {
                    if (!array_key_exists($accountId, $accountCache)) {
                        $accountCache[$accountId] = _fetchAccountName($token, $accountId);
                    }
                    $clientName = $accountCache[$accountId];
                }
            }

            $dossierStmt->execute([
                ':dossierid'        => _cleanStr($dos['apo_dossierid']           ?? null),
                ':numero'           => _cleanStr($dos['apo_numerodossier']       ?? null),
                ':nom'              => _cleanStr($dos['apo_name']                ?? null),
                ':reference_client' => _cleanStr($dos['apo_referenceclient']    ?? null),
                ':ville'            => _cleanStr($dos['apo_ville']               ?? null),
                ':site_id'          => _cleanStr($siteId),
                ':client_name'      => _cleanStr($clientName),
                ':date_demande'     => isset($dos['apo_datededemande'])
                                           ? substr($dos['apo_datededemande'], 0, 10) : null,
                ':date_remise'      => isset($dos['apo_dateremiseauclient'])
                                           ? substr($dos['apo_dateremiseauclient'], 0, 10) : null,
                ':date_preetudie'   => isset($dos['apo_datedepassageenpreetude'])
                                           ? substr($dos['apo_datedepassageenpreetude'], 0, 10) : null,
                ':modifiedon'       => $dos['modifiedon'] ?? null,
                ':raw'              => json_encode($dos, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
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

// ── Helpers ───────────────────────────────────────────────

function _cleanStr(?string $s): ?string {
    if ($s === null) return null;
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
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

        if (!mb_check_encoding($body, 'UTF-8')) {
            $body = iconv('UTF-8', 'UTF-8//IGNORE', $body);
        }

        $data = json_decode($body, true);
        foreach ($data['value'] ?? [] as $record) {
            yield $record;
        }
        $next = $data['@odata.nextLink'] ?? null;
    }
}

function _fetchAccountName(string $token, string $accountId): ?string {
    $ch = curl_init("https://rtaxes.api.crm4.dynamics.com/api/data/v9.2/accounts({$accountId})?\$select=name");
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            "Accept: application/json",
            "OData-Version: 4.0",
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    return json_decode($body, true)['name'] ?? null;
}

function _geocode(string $adresse, ?string $ville, ?string $cp): array {
    $query = trim("$adresse $cp $ville");
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://data.geopf.fr/geocodage/search?q=" . urlencode($query) . "&limit=1",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'MiniSIG/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $data = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $coords = $data['features'][0]['geometry']['coordinates'] ?? null;
    return $coords ? [(float)$coords[1], (float)$coords[0]] : [null, null];
}
