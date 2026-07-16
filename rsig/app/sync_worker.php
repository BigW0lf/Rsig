<?php
/**
 * Worker de synchronisation CRM — lancé en arrière-plan par /api/crm/sync
 * Usage : php sync_worker.php <log_id>
 */
$logId = (int)($argv[1] ?? 0);
if (!$logId) exit(1);

require __DIR__ . '/../config.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/dynamics.php';
require __DIR__ . '/crm_sync.php';

set_time_limit(0);   // la sync peut prendre plusieurs minutes
ini_set('memory_limit', '256M');

$db = new PDO(DB_DSN, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

try {
    $result = crmSync($db);

    $db->prepare("UPDATE crm_sync_log SET finished_at=now(), status='ok',
                  dossiers_count=:d, message=:m WHERE id=:id")
       ->execute([
           ':d' => $result['dossiers'],
           ':m' => 'Sync OK — ' . $result['dossiers'] . ' dossiers traités',
           ':id'=> $logId,
       ]);
    // Invalider le cache GeoJSON CRM après sync réussie
    foreach (['crm_geojson_main', 'crm_geojson_fallback'] as $k) {
        @unlink(sys_get_temp_dir() . '/rsig_cache/' . $k . '.json');
    }
} catch (\Throwable $e) {
    $db->prepare("UPDATE crm_sync_log SET finished_at=now(), status='error', message=:m WHERE id=:id")
       ->execute([':m' => $e->getMessage(), ':id' => $logId]);
}
