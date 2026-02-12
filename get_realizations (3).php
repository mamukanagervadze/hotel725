<?php
// get_realizations.php - რეალიზაციების წამოღება (უსაფრთხო ვერსია)
// =================================================================

require_once('config.php');
setCorsHeaders();
requireAuth();
rateLimit(120, 60);

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Method Override — shared hosting-ზე DELETE/PUT ხშირად დაბლოკილია
if ($method === 'POST' && isset($_GET['_method'])) {
    $method = strtoupper($_GET['_method']);
}

// GET - ყველა რეალიზაცია
if ($method === 'GET' && $action === 'list') {
    try {
        $stmt = $pdo->query("
            SELECT r.*,
                GROUP_CONCAT(DISTINCT CONCAT(rg.guest_name, '|', rg.id_number) SEPARATOR ';;') as guests_data,
                GROUP_CONCAT(DISTINCT CONCAT(rr.room_type, '|', rr.room_number, '|', rr.price) SEPARATOR ';;') as rooms_data
            FROM realizations r
            LEFT JOIN realization_guests rg ON r.id = rg.realization_id
            LEFT JOIN realization_rooms rr ON r.id = rr.realization_id
            GROUP BY r.id
            ORDER BY r.id DESC
        ");
        $realizations = $stmt->fetchAll();

        foreach ($realizations as &$r) {
            $r['guests'] = [];
            if ($r['guests_data']) {
                foreach (explode(';;', $r['guests_data']) as $g) {
                    $parts = explode('|', $g);
                    if (count($parts) === 2) {
                        $r['guests'][] = ['name' => $parts[0], 'idNumber' => $parts[1]];
                    }
                }
            }
            unset($r['guests_data']);

            $r['rooms'] = [];
            if ($r['rooms_data']) {
                foreach (explode(';;', $r['rooms_data']) as $rm) {
                    $parts = explode('|', $rm);
                    if (count($parts) === 3) {
                        $r['rooms'][] = ['type' => $parts[0], 'number' => $parts[1], 'price' => $parts[2]];
                    }
                }
            }
            unset($r['rooms_data']);
        }

        echo json_encode(['success' => true, 'data' => $realizations, 'count' => count($realizations)]);
    } catch(PDOException $e) {
        logError('Realizations list error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'მონაცემების ჩატვირთვის შეცდომა']);
    }
    exit();
}

// GET - ერთი ჩანაწერი
if ($method === 'GET' && $action === 'detail' && isset($_GET['id'])) {
    try {
        $id = intval($_GET['id']);
        $stmt = $pdo->prepare("SELECT * FROM realizations WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $r = $stmt->fetch();

        if (!$r) {
            echo json_encode(['success' => false, 'error' => 'ჩანაწერი ვერ მოიძებნა']);
            exit();
        }

        $gStmt = $pdo->prepare("SELECT guest_name, id_number FROM realization_guests WHERE realization_id = :id");
        $gStmt->execute([':id' => $id]);
        $r['guests'] = $gStmt->fetchAll();

        $rmStmt = $pdo->prepare("SELECT room_type, room_number, price FROM realization_rooms WHERE realization_id = :id");
        $rmStmt->execute([':id' => $id]);
        $r['rooms'] = $rmStmt->fetchAll();

        echo json_encode(['success' => true, 'data' => $r]);
    } catch(PDOException $e) {
        logError('Realization detail error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// DELETE realizations
if (($method === 'DELETE' || $method === 'POST') && $action === 'delete' && isset($_GET['id'])) {
    try {
        $id = intval($_GET['id']);
        $stmt = $pdo->prepare("DELETE FROM realizations WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        logError('Realization deleted: ID=' . $id, ['user' => $_SESSION['user']]);
        echo json_encode(['success' => true, 'message' => 'ჩანაწერი წაიშალა']);
    } catch(PDOException $e) {
        logError('Realization delete error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'წაშლის შეცდომა']);
    }
    exit();
}

// =============================================
// BLOCKED ROOMS API
// =============================================

// GET - ბლოკირებული ოთახების სია
if ($method === 'GET' && $action === 'blocks') {
    try {
        $stmt = $pdo->query("SELECT * FROM blocked_rooms ORDER BY block_from DESC");
        $blocks = $stmt->fetchAll();
        echo json_encode(['success' => true, 'data' => $blocks]);
    } catch(PDOException $e) {
        logError('Blocks list error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// POST - ოთახის დაბლოკვა
if ($method === 'POST' && $action === 'block') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['roomNumber']) || empty($data['blockFrom']) || empty($data['blockTo'])) {
        echo json_encode(['success' => false, 'error' => 'აუცილებელი ველები: roomNumber, blockFrom, blockTo']);
        exit();
    }

    if ($data['blockTo'] <= $data['blockFrom']) {
        echo json_encode(['success' => false, 'error' => 'დასრულების თარიღი უნდა იყოს დაწყების შემდეგ']);
        exit();
    }

    try {
        // კონფლიქტის შემოწმება
        $checkStmt = $pdo->prepare("
            SELECT id FROM blocked_rooms 
            WHERE room_number = :room 
            AND block_from < :block_to 
            AND block_to > :block_from
        ");
        $checkStmt->execute([
            ':room' => $data['roomNumber'],
            ':block_from' => $data['blockFrom'],
            ':block_to' => $data['blockTo']
        ]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'ეს ოთახი უკვე დაბლოკილია ამ პერიოდში']);
            exit();
        }

        $stmt = $pdo->prepare("
            INSERT INTO blocked_rooms (room_number, room_type, block_from, block_to, reason, created_by)
            VALUES (:room_number, :room_type, :block_from, :block_to, :reason, :created_by)
        ");
        $stmt->execute([
            ':room_number' => cleanInput($data['roomNumber']),
            ':room_type'   => cleanInput($data['roomType'] ?? ''),
            ':block_from'  => $data['blockFrom'],
            ':block_to'    => $data['blockTo'],
            ':reason'      => cleanInput($data['reason'] ?? 'დაბლოკილი'),
            ':created_by'  => $_SESSION['user'] ?? 'admin'
        ]);

        logError('Room blocked: ' . $data['roomNumber'], ['user' => $_SESSION['user']]);
        echo json_encode(['success' => true, 'message' => 'ოთახი დაბლოკილია', 'id' => $pdo->lastInsertId()]);
    } catch(PDOException $e) {
        logError('Block room error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// DELETE - ბლოკის მოხსნა
if (($method === 'DELETE' || $method === 'POST') && $action === 'unblock' && isset($_GET['id'])) {
    try {
        $id = intval($_GET['id']);
        $stmt = $pdo->prepare("DELETE FROM blocked_rooms WHERE id = :id");
        $stmt->execute([':id' => $id]);

        logError('Room unblocked: ID=' . $id, ['user' => $_SESSION['user']]);
        echo json_encode(['success' => true, 'message' => 'ბლოკი მოხსნილია']);
    } catch(PDOException $e) {
        logError('Unblock error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// =============================================
// CLIENTS API
// =============================================

// GET - კლიენტების ძებნა (autocomplete)
if ($method === 'GET' && $action === 'clients_search') {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (strlen($q) < 2) {
        echo json_encode(['success' => true, 'data' => []]);
        exit();
    }
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM clients 
            WHERE client_name LIKE :q1 OR id_code LIKE :q2 OR phone LIKE :q3
            ORDER BY booking_count DESC, client_name ASC
            LIMIT 10
        ");
        $search = '%' . $q . '%';
        $stmt->execute([':q1' => $search, ':q2' => $search, ':q3' => $search]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch(PDOException $e) {
        logError('Client search error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// GET - ყველა კლიენტი
if ($method === 'GET' && $action === 'clients') {
    try {
        $stmt = $pdo->query("SELECT * FROM clients ORDER BY booking_count DESC, client_name ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch(PDOException $e) {
        logError('Clients list error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// POST - კლიენტის დამატება ან განახლება (upsert by id_code)
if ($method === 'POST' && $action === 'client_save') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['client_name'])) {
        echo json_encode(['success' => false, 'error' => 'სახელი აუცილებელია']);
        exit();
    }

    try {
        $idCode = cleanInput($data['id_code'] ?? '');
        $existing = null;

        // ჯერ id_code-ით ვეძებთ, შემდეგ სახელით
        if (!empty($idCode)) {
            $chk = $pdo->prepare("SELECT id FROM clients WHERE id_code = :code");
            $chk->execute([':code' => $idCode]);
            $existing = $chk->fetch();
        }
        if (!$existing && !empty($data['client_name'])) {
            $chk2 = $pdo->prepare("SELECT id FROM clients WHERE client_name = :name AND (id_code = '' OR id_code IS NULL)");
            $chk2->execute([':name' => cleanInput($data['client_name'])]);
            $existing = $chk2->fetch();
        }

        if ($existing) {
            // განახლება + booking_count++
            $stmt = $pdo->prepare("UPDATE clients SET 
                client_type = COALESCE(NULLIF(:type,''), client_type),
                client_name = :name,
                id_code = COALESCE(NULLIF(:code,''), id_code),
                email = COALESCE(NULLIF(:email,''), email),
                phone = COALESCE(NULLIF(:phone,''), phone),
                booking_count = booking_count + 1
                WHERE id = :id");
            $stmt->execute([
                ':type'  => cleanInput($data['client_type'] ?? ''),
                ':name'  => cleanInput($data['client_name']),
                ':code'  => $idCode,
                ':email' => cleanInput($data['email'] ?? ''),
                ':phone' => cleanInput($data['phone'] ?? ''),
                ':id'    => $existing['id']
            ]);
            echo json_encode(['success' => true, 'id' => $existing['id'], 'action' => 'updated']);
        } else {
            // ახალი
            $stmt = $pdo->prepare("INSERT INTO clients (client_type, client_name, id_code, email, phone, booking_count)
                VALUES (:type, :name, :code, :email, :phone, 1)");
            $stmt->execute([
                ':type'  => cleanInput($data['client_type'] ?? 'individual'),
                ':name'  => cleanInput($data['client_name']),
                ':code'  => $idCode,
                ':email' => cleanInput($data['email'] ?? ''),
                ':phone' => cleanInput($data['phone'] ?? '')
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'action' => 'created']);
        }
    } catch(PDOException $e) {
        logError('Client save error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// DELETE - კლიენტის წაშლა
if (($method === 'DELETE' || $method === 'POST') && $action === 'client_delete' && isset($_GET['id'])) {
    try {
        $id = intval($_GET['id']);
        $stmt = $pdo->prepare("DELETE FROM clients WHERE id = :id");
        $stmt->execute([':id' => $id]);
        logError('Client deleted: ID=' . $id, ['user' => $_SESSION['user']]);
        echo json_encode(['success' => true, 'message' => 'კლიენტი წაშლილია']);
    } catch(PDOException $e) {
        logError('Client delete error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// =============================================
// ROOMS API
// =============================================

// GET - ოთახების სია
if ($method === 'GET' && $action === 'rooms') {
    try {
        $activeOnly = isset($_GET['active']) ? (bool)$_GET['active'] : false;
        $sql = "SELECT * FROM rooms";
        if ($activeOnly) $sql .= " WHERE is_active = 1";
        $sql .= " ORDER BY floor ASC, room_number ASC";
        $stmt = $pdo->query($sql);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch(PDOException $e) {
        logError('Rooms list error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// POST - ოთახის დამატება
if ($method === 'POST' && $action === 'room_add') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['room_number']) || empty($data['room_type']) || !isset($data['floor'])) {
        echo json_encode(['success' => false, 'error' => 'აუცილებელი ველები: room_number, room_type, floor']);
        exit();
    }

    try {
        // დუბლიკატის შემოწმება
        $check = $pdo->prepare("SELECT id FROM rooms WHERE room_number = :num");
        $check->execute([':num' => $data['room_number']]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'ოთახი ' . $data['room_number'] . ' უკვე არსებობს']);
            exit();
        }

        $stmt = $pdo->prepare("INSERT INTO rooms (room_number, room_type, floor, default_price, is_active) 
                                VALUES (:num, :type, :floor, :price, 1)");
        $stmt->execute([
            ':num'   => cleanInput($data['room_number']),
            ':type'  => cleanInput($data['room_type']),
            ':floor' => intval($data['floor']),
            ':price' => floatval($data['default_price'] ?? 0)
        ]);

        logError('Room added: ' . $data['room_number'], ['user' => $_SESSION['user']]);
        echo json_encode(['success' => true, 'message' => 'ოთახი დამატებულია', 'id' => $pdo->lastInsertId()]);
    } catch(PDOException $e) {
        logError('Room add error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// PUT - ოთახის რედაქტირება
if (($method === 'PUT' || $method === 'PATCH' || $method === 'POST') && $action === 'room_update' && isset($_GET['id'])) {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = intval($_GET['id']);

    try {
        // დუბლიკატის შემოწმება (სხვა ოთახის)
        if (!empty($data['room_number'])) {
            $check = $pdo->prepare("SELECT id FROM rooms WHERE room_number = :num AND id != :id");
            $check->execute([':num' => $data['room_number'], ':id' => $id]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'ოთახი ' . $data['room_number'] . ' უკვე არსებობს']);
                exit();
            }
        }

        $stmt = $pdo->prepare("UPDATE rooms SET 
            room_number = COALESCE(:num, room_number),
            room_type = COALESCE(:type, room_type),
            floor = COALESCE(:floor, floor),
            default_price = COALESCE(:price, default_price),
            is_active = COALESCE(:active, is_active)
            WHERE id = :id");
        $stmt->execute([
            ':num'    => isset($data['room_number']) ? cleanInput($data['room_number']) : null,
            ':type'   => isset($data['room_type']) ? cleanInput($data['room_type']) : null,
            ':floor'  => isset($data['floor']) ? intval($data['floor']) : null,
            ':price'  => isset($data['default_price']) ? floatval($data['default_price']) : null,
            ':active' => isset($data['is_active']) ? intval($data['is_active']) : null,
            ':id'     => $id
        ]);

        logError('Room updated: ID=' . $id, ['user' => $_SESSION['user']]);
        echo json_encode(['success' => true, 'message' => 'ოთახი განახლებულია']);
    } catch(PDOException $e) {
        logError('Room update error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// DELETE - ოთახის წაშლა
if (($method === 'DELETE' || $method === 'POST') && $action === 'room_delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    try {
        // შევამოწმოთ აქვს თუ არა ჯავშნები
        $check = $pdo->prepare("SELECT COUNT(*) as cnt FROM realization_rooms rr 
            JOIN rooms r ON r.room_number = rr.room_number WHERE r.id = :id");
        $check->execute([':id' => $id]);
        $result = $check->fetch();
        
        if ($result && $result['cnt'] > 0) {
            // ჯავშნები აქვს — ვთიშავთ, არ ვშლით
            $stmt = $pdo->prepare("UPDATE rooms SET is_active = 0 WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'ოთახი გაითიშა (ჯავშნები აქვს, წაშლა შეუძლებელია)']);
        } else {
            $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = :id");
            $stmt->execute([':id' => $id]);
            echo json_encode(['success' => true, 'message' => 'ოთახი წაშლილია']);
        }

        logError('Room deleted/deactivated: ID=' . $id, ['user' => $_SESSION['user']]);
    } catch(PDOException $e) {
        logError('Room delete error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// =============================================
// USERS API (admin only)
// =============================================

// GET - მომხმარებლების სია
if ($method === 'GET' && $action === 'users') {
    if (!empty($_SESSION['role']) && $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'არ გაქვთ უფლება']);
        exit();
    }
    try {
        $stmt = $pdo->query("SELECT id, username, display_name, role, is_active, permissions, last_login, created_at FROM users ORDER BY id ASC");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
    } catch(PDOException $e) {
        logError('Users list error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// POST - მომხმარებლის დამატება
if ($method === 'POST' && $action === 'user_add') {
    if (!empty($_SESSION['role']) && $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'არ გაქვთ უფლება']);
        exit();
    }
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['username']) || empty($data['password']) || empty($data['display_name'])) {
        echo json_encode(['success' => false, 'error' => 'აუცილებელი: username, password, display_name']);
        exit();
    }
    if (strlen($data['password']) < 6) {
        echo json_encode(['success' => false, 'error' => 'პაროლი მინიმუმ 6 სიმბოლო']);
        exit();
    }

    try {
        $check = $pdo->prepare("SELECT id FROM users WHERE username = :u");
        $check->execute([':u' => $data['username']]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'მომხმარებელი ' . $data['username'] . ' უკვე არსებობს']);
            exit();
        }

        $hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $perms = isset($data['permissions']) ? json_encode($data['permissions']) : '{}';

        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, display_name, role, is_active, permissions)
            VALUES (:u, :p, :d, :r, 1, :perms)");
        $stmt->execute([
            ':u' => cleanInput($data['username']),
            ':p' => $hash,
            ':d' => cleanInput($data['display_name']),
            ':r' => in_array($data['role'] ?? '', ['admin','manager','receptionist']) ? $data['role'] : 'receptionist',
            ':perms' => $perms
        ]);

        logError('User added: ' . $data['username'], ['by' => $_SESSION['user']]);
        echo json_encode(['success' => true, 'message' => 'მომხმარებელი დამატებულია', 'id' => $pdo->lastInsertId()]);
    } catch(PDOException $e) {
        logError('User add error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// PUT - მომხმარებლის რედაქტირება
if (($method === 'PUT' || $method === 'PATCH' || $method === 'POST') && $action === 'user_update' && isset($_GET['id'])) {
    if (!empty($_SESSION['role']) && $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'არ გაქვთ უფლება']);
        exit();
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $id = intval($_GET['id']);

    try {
        // პაროლის ცვლილება
        if (!empty($data['password'])) {
            if (strlen($data['password']) < 6) {
                echo json_encode(['success' => false, 'error' => 'პაროლი მინიმუმ 6 სიმბოლო']);
                exit();
            }
            $hash = password_hash($data['password'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash = :p WHERE id = :id")
                ->execute([':p' => $hash, ':id' => $id]);
        }

        // სხვა ველების განახლება
        $updates = [];
        $params = [':id' => $id];

        if (isset($data['display_name'])) { $updates[] = "display_name = :dn"; $params[':dn'] = cleanInput($data['display_name']); }
        if (isset($data['role'])) { $updates[] = "role = :role"; $params[':role'] = $data['role']; }
        if (isset($data['is_active'])) { $updates[] = "is_active = :active"; $params[':active'] = intval($data['is_active']); }
        if (isset($data['permissions'])) { $updates[] = "permissions = :perms"; $params[':perms'] = json_encode($data['permissions']); }

        if (!empty($updates)) {
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
            $pdo->prepare($sql)->execute($params);
        }

        logError('User updated: ID=' . $id, ['by' => $_SESSION['user']]);
        echo json_encode(['success' => true, 'message' => 'მომხმარებელი განახლებულია']);
    } catch(PDOException $e) {
        logError('User update error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

// DELETE - მომხმარებლის წაშლა
if (($method === 'DELETE' || $method === 'POST') && $action === 'user_delete' && isset($_GET['id'])) {
    if (!empty($_SESSION['role']) && $_SESSION['role'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'არ გაქვთ უფლება']);
        exit();
    }
    $id = intval($_GET['id']);
    if ($id === intval($_SESSION['user_id'] ?? 0)) {
        echo json_encode(['success' => false, 'error' => 'საკუთარ თავს ვერ წაშლით']);
        exit();
    }
    try {
        $pdo->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
        logError('User deleted: ID=' . $id, ['by' => $_SESSION['user']]);
        echo json_encode(['success' => true, 'message' => 'მომხმარებელი წაშლილია']);
    } catch(PDOException $e) {
        logError('User delete error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'შეცდომა']);
    }
    exit();
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
?>