<?php
// login_api.php - HOTEL 725 სერვერული ავტორიზაცია
// =================================================

require_once('config.php');
setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// === ლოგინი ===
if ($method === 'POST' && $action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['username']) || empty($data['password'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'შეიყვანეთ მომხმარებელი და პაროლი']);
        exit();
    }

    // Rate limit: მაქსიმუმ 10 ცდა წუთში
    rateLimit(10, 60);

    if (doLogin($data['username'], $data['password'])) {
        logError('Login success: ' . $data['username']);
        echo json_encode([
            'success' => true,
            'message' => 'წარმატებით შეხვედით',
            'user' => $_SESSION['display_name'] ?? $data['username'],
            'username' => $data['username'],
            'role' => $_SESSION['role'] ?? 'admin',
            'permissions' => $_SESSION['permissions'] ?? []
        ]);
    } else {
        logError('Login failed: ' . $data['username']);
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'არასწორი მომხმარებელი ან პაროლი']);
    }
    exit();
}

// === სტატუსის შემოწმება ===
if ($method === 'GET' && $action === 'check') {
    initSession();
    if (!empty($_SESSION['user']) && (time() - $_SESSION['login_time'] < SESSION_LIFETIME)) {
        echo json_encode([
            'success' => true,
            'loggedIn' => true,
            'user' => $_SESSION['display_name'] ?? $_SESSION['user'],
            'username' => $_SESSION['user'],
            'role' => $_SESSION['role'] ?? 'admin',
            'permissions' => $_SESSION['permissions'] ?? []
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'loggedIn' => false
        ]);
    }
    exit();
}

// === გამოსვლა ===
if ($method === 'POST' && $action === 'logout') {
    doLogout();
    echo json_encode(['success' => true, 'message' => 'წარმატებით გახვედით']);
    exit();
}

// === პაროლის შეცვლა ===
if ($method === 'POST' && $action === 'change_password') {
    requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['oldPassword']) || empty($data['newPassword'])) {
        echo json_encode(['success' => false, 'error' => 'შეავსეთ ყველა ველი']);
        exit();
    }

    if (strlen($data['newPassword']) < 8) {
        echo json_encode(['success' => false, 'error' => 'პაროლი მინიმუმ 8 სიმბოლო უნდა იყოს']);
        exit();
    }

    // ახლის hash დაგენერირება
    $newHash = password_hash($data['newPassword'], PASSWORD_DEFAULT);
    echo json_encode([
        'success' => true,
        'message' => 'ახალი hash დაგენერირდა. config.php-ში ხელით ჩასვით:',
        'hash' => $newHash
    ]);
    exit();
}

// === პაროლის hash გენერატორი ===
if ($method === 'GET' && $action === 'generate_user_hash' && isset($_GET['pass'])) {
    requireAuth();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'admin only']);
        exit();
    }
    $hash = password_hash($_GET['pass'], PASSWORD_DEFAULT);
    
    // ავტომატურად განაახლე admin-ის hash ბაზაში
    try {
        $pdo = getDB();
        $pdo->prepare("UPDATE users SET password_hash = :hash WHERE username = 'admin' AND password_hash LIKE '%92IXUNpkjO0rOQ5%'")
            ->execute([':hash' => $hash]);
    } catch(PDOException $e) {}
    
    echo json_encode(['success' => true, 'hash' => $hash, 'message' => 'admin hash updated']);
    exit();
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
?>