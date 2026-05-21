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

$db = new PDO(DB_DSN, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

try {
    $result = crmSync($db);

    $db->prepare("UPDATE crm_sync_log SET finished_at=now(), status='ok',
                  sites_count=:s, dossiers_count=:d, geocoded=:g, message=:m WHERE id=:id")
       ->execute([
           ':s' => $result['sites'],
           ':d' => $result['dossiers'],
           ':g' => $result['geocoded'],
           ':m' => 'Sync OK — ' . $result['sites'] . ' sites, ' . $result['dossiers'] . ' dossiers',
           ':id'=> $logId,
       ]);
} catch (\Throwable $e) {
    $db->prepare("UPDATE crm_sync_log SET finished_at=now(), status='error', message=:m WHERE id=:id")
       ->execute([':m' => $e->getMessage(), ':id' => $logId]);
}
