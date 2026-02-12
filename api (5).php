<?php
// api.php - HOTEL 725 Booking API (უსაფრთხო ვერსია)
// ===================================================

require_once('config.php');
setCorsHeaders();
requireAuth();   // ← ყველა მოთხოვნა ავტორიზებული უნდა იყოს
rateLimit(120, 60);

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Method Override — shared hosting-ზე DELETE/PUT ხშირად დაბლოკილია
if ($method === 'POST' && isset($_GET['_method'])) {
    $method = strtoupper($_GET['_method']);
}
$request = isset($_GET['action']) ? $_GET['action'] : '';

// Route handling
switch($method) {
    case 'GET':
        if ($request === 'bookings') {
            getAllBookings($pdo);
        } elseif ($request === 'booking' && isset($_GET['id'])) {
            getBooking($pdo, intval($_GET['id']));
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
        }
        break;
        
    case 'POST':
        if ($request === 'create') {
            createBooking($pdo);
        } elseif ($request === 'update' && isset($_GET['id'])) {
            updateBooking($pdo, intval($_GET['id']));
        } elseif ($request === 'delete' && isset($_GET['id'])) {
            deleteBooking($pdo, intval($_GET['id']));
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
        }
        break;
        
    case 'PUT':
    case 'PATCH':
        if ($request === 'update' && isset($_GET['id'])) {
            updateBooking($pdo, intval($_GET['id']));
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
        }
        break;
        
    case 'DELETE':
        if ($request === 'delete' && isset($_GET['id'])) {
            deleteBooking($pdo, intval($_GET['id']));
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        break;
}

// Function: Create new booking
function createBooking($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        return;
    }
    
    $invoiceNumber = 'INV-' . time() . rand(100, 999);
    
    $clientType = isset($data['client']['type']) ? cleanInput($data['client']['type']) : 'individual';
    $clientName = isset($data['client']['name']) ? cleanInput($data['client']['name']) : cleanInput($data['fullName'] ?? '');
    $clientIdCode = isset($data['client']['idCode']) ? cleanInput($data['client']['idCode']) : cleanInput($data['clientIdCode'] ?? '');
    $clientEmail = isset($data['client']['email']) ? cleanInput($data['client']['email']) : cleanInput($data['email'] ?? '');
    $clientPhone = isset($data['client']['phone']) ? cleanInput($data['client']['phone']) : cleanInput($data['phone'] ?? '');
    
    $guests = isset($data['guests']) ? $data['guests'] : json_encode([]);
    $rooms = isset($data['rooms']) ? $data['rooms'] : json_encode([]);
    $services = isset($data['services']) ? json_encode($data['services']) : json_encode([]);

    // === CONFLICT DETECTION ===
    if (!empty($data['rooms']) && !empty($data['checkIn']) && !empty($data['checkOut'])) {
        $roomsList = is_string($data['rooms']) ? json_decode($data['rooms'], true) : $data['rooms'];
        if (is_array($roomsList)) {
            $conflicts = [];
            foreach ($roomsList as $room) {
                $roomNum = $room['number'] ?? $room['roomNumber'] ?? '';
                if (empty($roomNum)) continue;

                // realizations ცხრილში
                $chk = $pdo->prepare("
                    SELECT r.invoice_number, r.client_name FROM realizations r
                    JOIN realization_rooms rr ON r.id = rr.realization_id
                    WHERE rr.room_number = :room AND r.check_in_date < :co AND r.check_out_date > :ci
                ");
                $chk->execute([':room' => $roomNum, ':ci' => $data['checkIn'], ':co' => $data['checkOut']]);
                $ex = $chk->fetch();
                if ($ex) $conflicts[] = "ოთახი {$roomNum} დაკავებულია ({$ex['invoice_number']})";

                // bookings ცხრილში
                $chk2 = $pdo->prepare("
                    SELECT invoice_number, client_name FROM bookings
                    WHERE JSON_SEARCH(rooms, 'one', :room) IS NOT NULL
                    AND check_in < :co AND check_out > :ci AND status != 'cancelled'
                ");
                $chk2->execute([':room' => $roomNum, ':ci' => $data['checkIn'], ':co' => $data['checkOut']]);
                $ex2 = $chk2->fetch();
                if ($ex2) $conflicts[] = "ოთახი {$roomNum} დაჯავშნილია ({$ex2['invoice_number']})";

                // blocked_rooms
                $chk3 = $pdo->prepare("
                    SELECT reason FROM blocked_rooms
                    WHERE room_number = :room AND block_from < :co AND block_to > :ci
                ");
                $chk3->execute([':room' => $roomNum, ':ci' => $data['checkIn'], ':co' => $data['checkOut']]);
                $bl = $chk3->fetch();
                if ($bl) $conflicts[] = "ოთახი {$roomNum} დაბლოკილია ({$bl['reason']})";
            }
            if (!empty($conflicts)) {
                echo json_encode(['success' => false, 'message' => 'კონფლიქტი: ' . implode('; ', $conflicts), 'conflicts' => $conflicts]);
                return;
            }
        }
    }
    
    try {
        $sql = "INSERT INTO bookings 
                (invoice_number, client_type, client_name, client_id_code, client_email, client_phone, 
                 guests, rooms, check_in, check_out, services, total_price, 
                 booking_date, status, full_name, email, phone, room_type)
                VALUES 
                (:invoice_number, :client_type, :client_name, :client_id_code, :client_email, :client_phone,
                 :guests, :rooms, :check_in, :check_out, :services, :total_price,
                 NOW(), 'confirmed', :full_name, :email, :phone, :room_type)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':invoice_number' => $invoiceNumber,
            ':client_type' => $clientType,
            ':client_name' => $clientName,
            ':client_id_code' => $clientIdCode,
            ':client_email' => $clientEmail,
            ':client_phone' => $clientPhone,
            ':guests' => $guests,
            ':rooms' => $rooms,
            ':check_in' => $data['checkIn'],
            ':check_out' => $data['checkOut'],
            ':services' => $services,
            ':total_price' => floatval($data['totalPrice']),
            ':full_name' => $clientName,
            ':email' => $clientEmail,
            ':phone' => $clientPhone,
            ':room_type' => isset($data['roomType']) ? cleanInput($data['roomType']) : ''
        ]);
        
        logError('Booking created: ' . $invoiceNumber, ['user' => $_SESSION['user']]);

        // კლიენტის ავტომატური შენახვა
        try {
            $existingClient = null;
            if (!empty($clientIdCode)) {
                $chk = $pdo->prepare("SELECT id FROM clients WHERE id_code = :code");
                $chk->execute([':code' => $clientIdCode]);
                $existingClient = $chk->fetch();
            }
            if (!$existingClient && !empty($clientName)) {
                $chk2 = $pdo->prepare("SELECT id FROM clients WHERE client_name = :name AND (id_code = '' OR id_code IS NULL)");
                $chk2->execute([':name' => $clientName]);
                $existingClient = $chk2->fetch();
            }
            if ($existingClient) {
                $pdo->prepare("UPDATE clients SET booking_count = booking_count + 1,
                    id_code = COALESCE(NULLIF(:code,''), id_code),
                    email = COALESCE(NULLIF(:email,''), email),
                    phone = COALESCE(NULLIF(:phone,''), phone)
                    WHERE id = :id")
                    ->execute([':code' => $clientIdCode, ':email' => $clientEmail, ':phone' => $clientPhone, ':id' => $existingClient['id']]);
            } else {
                $pdo->prepare("INSERT INTO clients (client_type, client_name, id_code, email, phone, booking_count) VALUES (:type, :name, :code, :email, :phone, 1)")
                    ->execute([':type' => $clientType, ':name' => $clientName, ':code' => $clientIdCode, ':email' => $clientEmail, ':phone' => $clientPhone]);
            }
        } catch(PDOException $e) {
            logError('Client auto-save warning: ' . $e->getMessage());
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'დაჯავშნა წარმატებით შეინახა',
            'invoiceNumber' => $invoiceNumber,
            'bookingId' => $pdo->lastInsertId()
        ]);
        
    } catch(PDOException $e) {
        logError('Booking create error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'შეცდომა ჯავშნის შექმნისას']);
    }
}

function getAllBookings($pdo) {
    try {
        $sql = "SELECT * FROM bookings ORDER BY booking_date DESC";
        $stmt = $pdo->query($sql);
        echo json_encode(['success' => true, 'bookings' => $stmt->fetchAll()]);
    } catch(PDOException $e) {
        logError('Bookings list error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'მონაცემების ჩატვირთვის შეცდომა']);
    }
}

function getBooking($pdo, $id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $booking = $stmt->fetch();
        
        if ($booking) {
            echo json_encode(['success' => true, 'booking' => $booking]);
        } else {
            echo json_encode(['success' => false, 'message' => 'ჯავშანი ვერ მოიძებნა']);
        }
    } catch(PDOException $e) {
        logError('Booking fetch error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'შეცდომა']);
    }
}

function updateBooking($pdo, $id) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['status'])) {
        echo json_encode(['success' => false, 'message' => 'სტატუსი აუცილებელია']);
        return;
    }
    
    // ვალიდაცია
    $allowedStatuses = ['confirmed', 'checked_in', 'checked_out', 'cancelled'];
    if (!in_array($data['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => 'არასწორი სტატუსი']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("UPDATE bookings SET 
                status = :status,
                check_in = COALESCE(:check_in, check_in),
                check_out = COALESCE(:check_out, check_out),
                notes = :notes
                WHERE id = :id");
        
        $stmt->execute([
            ':status' => $data['status'],
            ':check_in' => isset($data['checkIn']) ? $data['checkIn'] : null,
            ':check_out' => isset($data['checkOut']) ? $data['checkOut'] : null,
            ':notes' => isset($data['notes']) ? cleanInput($data['notes']) : null,
            ':id' => $id
        ]);
        
        logError('Booking updated: ID=' . $id, ['user' => $_SESSION['user'], 'status' => $data['status']]);
        echo json_encode(['success' => true, 'message' => 'დაჯავშნა წარმატებით განახლდა']);
    } catch(PDOException $e) {
        logError('Booking update error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'განახლების შეცდომა']);
    }
}

function deleteBooking($pdo, $id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM bookings WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        logError('Booking deleted: ID=' . $id, ['user' => $_SESSION['user']]);
        echo json_encode(['success' => true, 'message' => 'დაჯავშნა წარმატებით წაიშალა']);
    } catch(PDOException $e) {
        logError('Booking delete error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'წაშლის შეცდომა']);
    }
}
?>