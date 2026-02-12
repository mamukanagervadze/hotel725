<?php
// config.php - HOTEL 725 ცენტრალური კონფიგურაცია
// ============================================
// ყველა PHP ფაილმა ეს უნდა ჩართოს: require_once('config.php');

// === ბაზის კონფიგურაცია ===
define('DB_HOST', 'localhost');
define('DB_NAME', 'travelba_hotel_booking');
define('DB_USER', 'travelba_hotel_user');
define('DB_PASS', '557367967mN');

// === ადმინის მომხმარებლები ===
// პაროლი hash-ით ინახება (password_hash + password_verify)
// პირველი გაშვებისას hash ავტომატურად გენერირდება
define('ADMIN_USERS', serialize([
    'admin' => password_hash('hotel725', PASSWORD_DEFAULT)
    // მეორე ადმინის დასამატებლად:
    // 'manager' => password_hash('manager_password', PASSWORD_DEFAULT)
]));

// === Session კონფიგურაცია ===
define('SESSION_LIFETIME', 3600 * 8); // 8 საათი
define('SESSION_NAME', 'HOTEL725_SESSION');

// === CORS - დაშვებული დომენები ===
// შეცვალე შენი რეალური დომენით
define('ALLOWED_ORIGINS', serialize([
    'https://travel.batumi.ge',
    'https://www.travel.batumi.ge',
    'http://localhost',
    'http://127.0.0.1'
]));

// ==========================================
// ფუნქციები
// ==========================================

/**
 * ბაზასთან კავშირი (Singleton pattern)
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'DB Connection Failed']);
            error_log('HOTEL725 DB Error: ' . $e->getMessage());
            exit();
        }
    }
    return $pdo;
}

/**
 * Session-ის დაწყება
 */
function initSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Strict'
            // 'secure' => true  // ← HTTPS-ზე გადასვლისას uncomment გააკეთე
        ]);
        session_start();
    }
}

/**
 * ავტორიზაციის შემოწმება
 * API endpoint-ების დასაცავად
 */
function requireAuth() {
    initSession();

    // შევამოწმოთ session
    if (empty($_SESSION['user']) || empty($_SESSION['login_time'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'არაავტორიზებული. გთხოვთ შეხვიდეთ სისტემაში.']);
        exit();
    }

    // შევამოწმოთ session ვადა
    if (time() - $_SESSION['login_time'] > SESSION_LIFETIME) {
        session_destroy();
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'სესია ამოიწურა. ხელახლა შედით.']);
        exit();
    }

    // განვაახლოთ აქტივობის დრო
    $_SESSION['last_activity'] = time();
}

/**
 * ლოგინის ფუნქცია — ბაზიდან ან config-იდან
 */
function doLogin($username, $password) {
    // ჯერ ბაზაში ვეძებთ
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :user AND is_active = 1");
        $stmt->execute([':user' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            initSession();
            session_regenerate_id(true);

            $_SESSION['user'] = $user['username'];
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['display_name'] = $user['display_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['permissions'] = json_decode($user['permissions'], true) ?: [];
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'];

            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id")
                ->execute([':id' => $user['id']]);

            return true;
        }
    } catch(PDOException $e) {
        // fallback to config
    }

    // Fallback: config.php
    $users = unserialize(ADMIN_USERS);
    if (isset($users[$username]) && password_verify($password, $users[$username])) {
        initSession();
        session_regenerate_id(true);

        $_SESSION['user'] = $username;
        $_SESSION['user_id'] = 0;
        $_SESSION['display_name'] = 'Admin';
        $_SESSION['role'] = 'admin';
        $_SESSION['permissions'] = ['bookings'=>true,'new_realization'=>true,'realizations'=>true,'blocking'=>true,'rooms'=>true,'system'=>true,'users'=>true];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['ip'] = $_SERVER['REMOTE_ADDR'];

        return true;
    }

    return false;
}

/**
 * უფლებების შემოწმება
 */
function hasPermission($perm) {
    if (empty($_SESSION['permissions'])) return false;
    if ($_SESSION['role'] === 'admin') return true;
    return !empty($_SESSION['permissions'][$perm]);
}

/**
 * გამოსვლა
 */
function doLogout() {
    initSession();
    $_SESSION = [];
    session_destroy();
}

/**
 * CORS headers - უსაფრთხო
 */
function setCorsHeaders() {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    $allowed = unserialize(ALLOWED_ORIGINS);

    if (in_array($origin, $allowed)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        // Same-origin requests-ისთვის (იმავე დომენიდან)
        // Origin header არ იგზავნება, ეს ნორმალურია
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Credentials: true');
    header('Content-Type: application/json; charset=utf-8');

    // Preflight
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}

/**
 * Rate limiting (ძირითადი)
 * IP-ზე დაფუძნებული, session-ში ინახება
 */
function rateLimit($maxRequests = 60, $windowSeconds = 60) {
    initSession();
    $key = 'rate_' . $_SERVER['REMOTE_ADDR'];

    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'reset' => time() + $windowSeconds];
    }

    if (time() > $_SESSION[$key]['reset']) {
        $_SESSION[$key] = ['count' => 0, 'reset' => time() + $windowSeconds];
    }

    $_SESSION[$key]['count']++;

    if ($_SESSION[$key]['count'] > $maxRequests) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'ძალიან ბევრი მოთხოვნა. სცადეთ მოგვიანებით.']);
        exit();
    }
}

/**
 * Input sanitization helper
 */
function cleanInput($value) {
    if (is_string($value)) {
        return trim(htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
    }
    return $value;
}

/**
 * Error logging
 */
function logError($message, $context = []) {
    $logFile = __DIR__ . '/logs/error.log';
    $logDir = dirname($logFile);

    if (!is_dir($logDir)) {
        mkdir($logDir, 0750, true);
    }

    $entry = date('Y-m-d H:i:s') . ' | '
        . $_SERVER['REMOTE_ADDR'] . ' | '
        . $_SERVER['REQUEST_METHOD'] . ' '
        . $_SERVER['REQUEST_URI'] . ' | '
        . $message;

    if (!empty($context)) {
        $entry .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }

    error_log($entry . "\n", 3, $logFile);
}

// ==========================================
// ინსტალაციის helper
// ==========================================

/**
 * პაროლის hash-ის გენერაცია
 * ბრაუზერში გახსენი: config.php?action=generate_hash&pass=your_password
 */
if (isset($_GET['action']) && $_GET['action'] === 'generate_hash' && isset($_GET['pass'])) {
    // მხოლოდ localhost-იდან
    if (in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])) {
        header('Content-Type: text/plain');
        echo "Password: " . $_GET['pass'] . "\n";
        echo "Hash: " . password_hash($_GET['pass'], PASSWORD_DEFAULT) . "\n";
        exit();
    }
    http_response_code(403);
    echo "Forbidden";
    exit();
}

/**
 * ინიციალიზაცია: admin hash-ის ავტო-გენერაცია
 * პირველი გაშვებისას ქმნის სწორ hash-ს
 */
function init_admin_hash() {
    return password_hash('hotel725', PASSWORD_DEFAULT);
}
?>