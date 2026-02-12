<?php
// save_realization.php - HOTEL 725 რეალიზაციის API (უსაფრთხო ვერსია)
// =====================================================================

require_once('config.php');
setCorsHeaders();
requireAuth();
rateLimit(120, 60);

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Method Override
if ($method === 'POST' && isset($_GET['_method'])) {
    $method = strtoupper($_GET['_method']);
}
$action = isset($_GET['action']) ? $_GET['action'] : '';

// ========== GET ==========
if ($method === 'GET') {

    // შემდეგი ინვოისის ნომერი
    if ($action === 'next_invoice') {
        try {
            $stmt = $pdo->query("SELECT invoice_number FROM realizations ORDER BY id DESC LIMIT 1");
            $last = $stmt->fetch();
            
            $nextNumber = 1;
            if ($last) {
                $lastNum = intval(str_replace('INV-', '', $last['invoice_number']));
                $nextNumber = $lastNum + 1;
            }
            
            echo json_encode([
                'success' => true,
                'invoice_number' => 'INV-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT)
            ]);
        } catch(PDOException $e) {
            logError('Next invoice error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'შეცდომა']);
        }
        exit();
    }

    // ჩანაწერების რაოდენობა
    if ($action === 'count') {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM realizations");
            $result = $stmt->fetch();
            echo json_encode(['success' => true, 'count' => (int)$result['total']]);
        } catch(PDOException $e) {
            logError('Count error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'შეცდომა']);
        }
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Invalid GET action']);
    exit();
}

// ========== POST - ჩანაწერის შენახვა ==========
if ($method === 'POST') {
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'არასწორი JSON მონაცემები']);
        exit();
    }

    // ძირითადი ვალიდაცია
    $required = ['invoiceNumber', 'clientName', 'checkInDate', 'checkOutDate', 'paymentType'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            echo json_encode(['success' => false, 'error' => "აუცილებელი ველი: $field"]);
            exit();
        }
    }

    // === CONFLICT DETECTION ===
    // შევამოწმოთ ოთახები ხომ არ არის უკვე დაკავებული ამ პერიოდში
    if (!empty($data['rooms']) && is_array($data['rooms'])) {
        $conflicts = [];
        foreach ($data['rooms'] as $room) {
            if (empty($room['number'])) continue;
            
            // რეალიზაციებში შემოწმება
            $checkStmt = $pdo->prepare("
                SELECT r.invoice_number, r.client_name, rr.room_number
                FROM realizations r
                JOIN realization_rooms rr ON r.id = rr.realization_id
                WHERE rr.room_number = :room_number
                AND r.check_in_date < :check_out
                AND r.check_out_date > :check_in
            ");
            $checkStmt->execute([
                ':room_number' => $room['number'],
                ':check_in'    => $data['checkInDate'],
                ':check_out'   => $data['checkOutDate']
            ]);
            $existing = $checkStmt->fetch();
            if ($existing) {
                $conflicts[] = "ოთახი {$room['number']} დაკავებულია ({$existing['invoice_number']} - {$existing['client_name']})";
            }

            // ბლოკირებულებში შემოწმება
            $blockStmt = $pdo->prepare("
                SELECT room_number, reason FROM blocked_rooms
                WHERE room_number = :room_number
                AND block_from < :check_out
                AND block_to > :check_in
            ");
            $blockStmt->execute([
                ':room_number' => $room['number'],
                ':check_in'    => $data['checkInDate'],
                ':check_out'   => $data['checkOutDate']
            ]);
            $blocked = $blockStmt->fetch();
            if ($blocked) {
                $conflicts[] = "ოთახი {$room['number']} დაბლოკილია ({$blocked['reason']})";
            }
        }

        if (!empty($conflicts)) {
            echo json_encode([
                'success' => false, 
                'error' => 'კონფლიქტი: ' . implode('; ', $conflicts),
                'conflicts' => $conflicts
            ]);
            exit();
        }
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO realizations 
            (invoice_number, client_name, client_id_code, nationality, check_in_date, check_out_date, 
             nights_count, room_count, total_cost, payment_type, document_number, 
             has_breakfast, has_transfer)
            VALUES 
            (:invoice_number, :client_name, :client_id_code, :nationality, :check_in_date, :check_out_date,
             :nights_count, :room_count, :total_cost, :payment_type, :document_number,
             :has_breakfast, :has_transfer)
        ");
        
        $stmt->execute([
            ':invoice_number'  => cleanInput($data['invoiceNumber']),
            ':client_name'     => cleanInput($data['clientName']),
            ':client_id_code'  => cleanInput($data['clientIdCode'] ?? ''),
            ':nationality'     => cleanInput($data['nationality'] ?? ''),
            ':check_in_date'   => $data['checkInDate'],
            ':check_out_date'  => $data['checkOutDate'],
            ':nights_count'    => (int)($data['nightsCount'] ?? 0),
            ':room_count'      => (int)($data['roomCount'] ?? 0),
            ':total_cost'      => (float)($data['totalCost'] ?? 0),
            ':payment_type'    => cleanInput($data['paymentType']),
            ':document_number' => !empty($data['documentNumber']) ? cleanInput($data['documentNumber']) : null,
            ':has_breakfast'   => !empty($data['hasBreakfast']) ? 1 : 0,
            ':has_transfer'    => !empty($data['hasTransfer']) ? 1 : 0
        ]);
        
        $realizationId = $pdo->lastInsertId();
        
        // სტუმრები
        if (!empty($data['guests']) && is_array($data['guests'])) {
            $guestStmt = $pdo->prepare("
                INSERT INTO realization_guests (realization_id, guest_name, id_number)
                VALUES (:realization_id, :guest_name, :id_number)
            ");
            
            foreach ($data['guests'] as $guest) {
                if (!empty($guest['name'])) {
                    $guestStmt->execute([
                        ':realization_id' => $realizationId,
                        ':guest_name'     => cleanInput($guest['name']),
                        ':id_number'      => cleanInput($guest['idNumber'] ?? '')
                    ]);
                }
            }
        }
        
        // ოთახები
        if (!empty($data['rooms']) && is_array($data['rooms'])) {
            $roomStmt = $pdo->prepare("
                INSERT INTO realization_rooms (realization_id, room_type, room_number, price)
                VALUES (:realization_id, :room_type, :room_number, :price)
            ");
            
            foreach ($data['rooms'] as $room) {
                if (!empty($room['type'])) {
                    $roomStmt->execute([
                        ':realization_id' => $realizationId,
                        ':room_type'      => cleanInput($room['type']),
                        ':room_number'    => cleanInput($room['number'] ?? ''),
                        ':price'          => (float)($room['price'] ?? 0)
                    ]);
                }
            }
        }
        
        $pdo->commit();
        
        // კლიენტის ავტომატური შენახვა/განახლება
        try {
            $clientName = cleanInput($data['clientName']);
            $clientIdCode = cleanInput($data['clientIdCode'] ?? '');
            
            $existingClient = null;
            if (!empty($clientIdCode)) {
                $chk = $pdo->prepare("SELECT id FROM clients WHERE id_code = :code");
                $chk->execute([':code' => $clientIdCode]);
                $existingClient = $chk->fetch();
            }
            if (!$existingClient) {
                $chk2 = $pdo->prepare("SELECT id FROM clients WHERE client_name = :name AND (id_code = '' OR id_code IS NULL)");
                $chk2->execute([':name' => $clientName]);
                $existingClient = $chk2->fetch();
            }

            if ($existingClient) {
                $pdo->prepare("UPDATE clients SET booking_count = booking_count + 1, 
                    id_code = COALESCE(NULLIF(:code,''), id_code) WHERE id = :id")
                    ->execute([':code' => $clientIdCode, ':id' => $existingClient['id']]);
            } else {
                $pdo->prepare("INSERT INTO clients (client_type, client_name, id_code, booking_count) VALUES ('individual', :name, :code, 1)")
                    ->execute([':name' => $clientName, ':code' => $clientIdCode]);
            }
        } catch(PDOException $e) {
            // კლიენტის შენახვის შეცდომა არ უნდა დააბრუნოს მთლიანი error
            logError('Client auto-save warning: ' . $e->getMessage());
        }
        
        logError('Realization created: ' . $data['invoiceNumber'], ['user' => $_SESSION['user']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'ჩანაწერი წარმატებით შეინახა ბაზაში!',
            'id' => (int)$realizationId,
            'invoice_number' => $data['invoiceNumber']
        ]);
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        logError('Realization save error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა შენახვისას']);
    }
    exit();
}

echo json_encode(['success' => false, 'error' => 'Method not allowed']);
?>