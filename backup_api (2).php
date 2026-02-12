<?php
// backup_api.php - HOTEL 725 ბაზის Backup სისტემა
// =================================================

require_once('config.php');
setCorsHeaders();
requireAuth();

$action = isset($_GET['action']) ? $_GET['action'] : '';

// === მანუალური SQL Backup ===
if ($action === 'export') {
    $pdo = getDB();
    
    $tables = ['users', 'clients', 'rooms', 'realizations', 'realization_guests', 'realization_rooms', 'bookings', 'blocked_rooms'];
    $backup = "-- HOTEL 725 Database Backup\n";
    $backup .= "-- Date: " . date('Y-m-d H:i:s') . "\n";
    $backup .= "-- User: " . ($_SESSION['user'] ?? 'unknown') . "\n";
    $backup .= "-- ================================\n\n";
    $backup .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $table) {
        try {
            $check = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($check->rowCount() === 0) continue;

            $createStmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $createRow = $createStmt->fetch(PDO::FETCH_NUM);
            $backup .= "-- Table: $table\n";
            $backup .= "DROP TABLE IF EXISTS `$table`;\n";
            $backup .= $createRow[1] . ";\n\n";

            $rows = $pdo->query("SELECT * FROM `$table`");
            $data = $rows->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($data) > 0) {
                $columns = array_keys($data[0]);
                $colList = '`' . implode('`, `', $columns) . '`';
                
                foreach ($data as $row) {
                    $values = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = $pdo->quote($val);
                        }
                    }
                    $backup .= "INSERT INTO `$table` ($colList) VALUES (" . implode(', ', $values) . ");\n";
                }
                $backup .= "\n";
            }
        } catch(PDOException $e) {
            $backup .= "-- ERROR on $table: " . $e->getMessage() . "\n\n";
        }
    }

    $backup .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    $backup .= "-- End of backup\n";

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="hotel725_backup_' . date('Y-m-d_His') . '.sql"');
    header('Content-Length: ' . strlen($backup));
    echo $backup;

    logError('Backup exported', ['user' => $_SESSION['user'], 'size' => strlen($backup)]);
    exit();
}

// === Backup სტატისტიკა ===
if ($action === 'stats') {
    $pdo = getDB();
    $stats = [];

    $tables = ['users', 'clients', 'rooms', 'realizations', 'realization_guests', 'realization_rooms', 'bookings', 'blocked_rooms'];
    foreach ($tables as $table) {
        try {
            $check = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($check->rowCount() === 0) {
                $stats[$table] = ['count' => 0, 'exists' => false];
                continue;
            }
            $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM `$table`");
            $row = $stmt->fetch();
            $stats[$table] = ['count' => (int)$row['cnt'], 'exists' => true];
        } catch(PDOException $e) {
            $stats[$table] = ['count' => 0, 'exists' => false, 'error' => $e->getMessage()];
        }
    }

    $logFile = __DIR__ . '/logs/error.log';
    $logSize = file_exists($logFile) ? filesize($logFile) : 0;

    echo json_encode([
        'success' => true,
        'tables' => $stats,
        'logSize' => $logSize,
        'logSizeHuman' => formatBytes($logSize),
        'serverTime' => date('Y-m-d H:i:s')
    ]);
    exit();
}

// === Error log ნახვა ===
if ($action === 'logs') {
    $logFile = __DIR__ . '/logs/error.log';
    $lines = isset($_GET['lines']) ? intval($_GET['lines']) : 100;

    if (!file_exists($logFile)) {
        echo json_encode(['success' => true, 'logs' => '', 'message' => 'ლოგი ცარიელია', 'totalLines' => 0]);
        exit();
    }

    $allLines = file($logFile, FILE_IGNORE_NEW_LINES);
    $lastLines = array_slice($allLines, -$lines);
    
    echo json_encode([
        'success' => true,
        'logs' => implode("\n", array_reverse($lastLines)),
        'totalLines' => count($allLines)
    ]);
    exit();
}

// === Error log გასუფთავება ===
if ($action === 'clear_logs' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $logFile = __DIR__ . '/logs/error.log';
    if (file_exists($logFile)) {
        file_put_contents($logFile, "-- Log cleared by " . ($_SESSION['user'] ?? 'unknown') . " at " . date('Y-m-d H:i:s') . "\n");
    }
    logError('Logs cleared', ['user' => $_SESSION['user']]);
    echo json_encode(['success' => true, 'message' => 'ლოგი გასუფთავდა']);
    exit();
}

function formatBytes($bytes) {
    if ($bytes === 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
}

echo json_encode(['success' => false, 'error' => 'Invalid action. Use: export, stats, logs, clear_logs']);
?>