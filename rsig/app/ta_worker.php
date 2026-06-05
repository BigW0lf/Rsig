<?php
/**
 * Worker TA — surveille l'exécution de update_ta.py et met à jour ta_update_log
 * Usage : php ta_worker.php <log_id> <log_file_path>
 */
$logId   = (int)($argv[1] ?? 0);
$logFile = $argv[2] ?? '';
if (!$logId) exit(1);

require __DIR__ . '/../config.php';
require __DIR__ . '/helpers.php';

$db = new PDO(DB_DSN, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Attendre la fin du script Python (max 10 min)
$start   = time();
$maxWait = 600;

while (time() - $start < $maxWait) {
    sleep(3);

    // Vérifier si le process Python est terminé en lisant le log
    if (!file_exists($logFile)) continue;
    $content = file_get_contents($logFile);

    if (str_contains($content, 'Terminé.') || str_contains($content, 'Termine.')) {
        // Extraire le nombre de communes mises à jour
        $communes = 0;
        if (preg_match('/(\d+) communes upsert/i', $content, $m)) {
            $communes = (int)$m[1];
        }
        // Extraire la dernière ligne de bilan
        $lines   = array_filter(explode("\n", trim($content)));
        $lastMsg = end($lines) ?: 'OK';

        $db->prepare("UPDATE ta_update_log SET finished_at=now(), status='ok', communes_updated=:c, message=:m WHERE id=:id")
           ->execute([':c' => $communes, ':m' => substr($lastMsg, 0, 500), ':id' => $logId]);
        exit(0);
    }

    // Détecter une erreur Python
    if (str_contains($content, 'Traceback') || str_contains($content, 'Error:')) {
        $lines  = array_filter(explode("\n", trim($content)));
        $errMsg = implode(' | ', array_slice($lines, -3));
        $db->prepare("UPDATE ta_update_log SET finished_at=now(), status='error', message=:m WHERE id=:id")
           ->execute([':m' => substr($errMsg, 0, 500), ':id' => $logId]);
        exit(1);
    }
}

// Timeout
$db->prepare("UPDATE ta_update_log SET finished_at=now(), status='error', message='Timeout (10 min)' WHERE id=:id")
   ->execute([':id' => $logId]);
