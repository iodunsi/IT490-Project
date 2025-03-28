#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('mysqlconnect.php');

ini_set("log_errors", 1);
ini_set("error_log", "/var/log/rabbitmq_errors.log");

// ✅ Load environment variables
function loadEnv() {
    if (!file_exists('.env')) {
        error_log("Error: .env file not found");
        return;
    }
    $lines = file('.env');
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $keyValue = explode('=', trim($line), 2);
        if (count($keyValue) == 2) {
            putenv(trim($keyValue[0]) . '=' . trim($keyValue[1]));
        }
    }
}

loadEnv();

// ✅ Establish database connection
function getDatabaseConnection() {
    $dbHost = getenv("DB_HOST");
    $dbUser = getenv("DB_USER");
    $dbPassword = getenv("DB_PASSWORD");
    $dbName = getenv("DB_NAME");

    $db = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
    if ($db->connect_errno) {
        error_log("Database connection failed: " . $db->connect_error);
        return null;
    }
    return $db;
}

// ✅ Request Processor
function requestProcessor($request) {
    $sanitizedRequest = $request;
    if (isset($sanitizedRequest['password'])) {
        $sanitizedRequest['password'] = '[REDACTED]';
    }

    echo "[RABBITMQ VM] 📩 Processing request: " . json_encode($sanitizedRequest) . "\n";
    error_log("[RABBITMQ VM] 📩 Processing request: " . json_encode($sanitizedRequest) . "\n", 3, "/var/log/rabbitmq_errors.log");

    if (!isset($request['type'])) {
        return ["status" => "error", "message" => "Unsupported request type"];
    }

    return match ($request['type']) {
        "login" => validateLogin($request['username'], $request['password']),
        "register" => registerUser($request),
        "logout" => logoutUser($request),
        "like" => likeArticle($request),
        default => ["status" => "error", "message" => "Unknown request type"]
    };
}

// ✅ Validate user login
function validateLogin($username, $password) {
    $db = getDatabaseConnection();
    if (!$db) return ["status" => "error", "message" => "Database connection failed"];

    $stmt = $db->prepare("SELECT password FROM users WHERE username = ?");
    if (!$stmt) return ["status" => "error", "message" => "Database error"];

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
        $stmt->close();
        $db->close();
        return ["status" => "error", "message" => "User not found"];
    }

    $stmt->bind_result($hashedPassword);
    $stmt->fetch();
    $stmt->close();

    if (!password_verify($password, $hashedPassword)) {
        $db->close();
        return ["status" => "error", "message" => "Incorrect password"];
    }

    $sessionKey = bin2hex(random_bytes(32));
    $sessionExpiration = date("Y-m-d H:i:s", strtotime("+1 hour"));

    $stmt = $db->prepare("UPDATE users SET session_key = ?, session_expires = ? WHERE username = ?");
    if (!$stmt) return ["status" => "error", "message" => "Failed to create session"];

    $stmt->bind_param("sss", $sessionKey, $sessionExpiration, $username);
    $stmt->execute();
    $stmt->close();
    $db->close();

    return [
        "status" => "success",
        "message" => "Login successful",
        "user_id" => $username,
        "session_key" => $sessionKey,
        "expires_at" => $sessionExpiration
    ];
}

// ✅ Register a new user
function registerUser($data) {
    $db = getDatabaseConnection();
    if (!$db) return ["status" => "error", "message" => "Database connection failed"];

    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $data['username']);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        $db->close();
        return ["status" => "error", "message" => "Username already exists"];
    }

    $stmt->close();
    $stmt = $db->prepare("INSERT INTO users (username, password, first_name, last_name, dob, email, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) return ["status" => "error", "message" => "Database error"];

    $stmt->bind_param("ssssss", $data['username'], $data['password'], $data['first_name'], $data['last_name'], $data['dob'], $data['email']);
    $stmt->execute();
    $stmt->close();
    $db->close();

    return ["status" => "success", "message" => "User registered successfully"];
}

// ✅ User logout
function logoutUser($data) {
    $db = getDatabaseConnection();
    if (!$db) return ["status" => "error", "message" => "Database connection failed"];

    $stmt = $db->prepare("UPDATE users SET session_key = NULL, session_expires = NULL WHERE username = ?");
    if (!$stmt) return ["status" => "error", "message" => "Database error"];

    $stmt->bind_param("s", $data['username']);
    $stmt->execute();
    $stmt->close();
    $db->close();

    return ["status" => "success", "message" => "User logged out successfully"];
}

// ✅ Like an article
function likeArticle($request) {
    $db = getDatabaseConnection();
    if (!$db) return ["status" => "error", "message" => "Database connection failed"];

    $stmt = $db->prepare("INSERT INTO likes (user_id, article_id, title, url, category, liked_at) VALUES (?, ?, ?, ?, ?, NOW())");
    if (!$stmt) return ["status" => "error", "message" => "Database error"];

    $stmt->bind_param("issss", $request['user'], $request['articleId'], $request['title'], $request['url'], $request['category']);
    $stmt->execute();
    $stmt->close();
    $db->close();

    return ["status" => "success", "message" => "Article liked successfully"];
}

// ✅ Server startup
echo "[RABBITMQ VM] 🚀 RabbitMQ Server is waiting for messages...\n";

$loginServer = new rabbitMQServer("testRabbitMQ.ini", "loginQueue");
$registerServer = new rabbitMQServer("testRabbitMQ.ini", "registerQueue");
$likeServer = new rabbitMQServer("testRabbitMQ.ini", "newsQueue");

pcntl_fork() == 0 && $loginServer->process_requests("requestProcessor") && exit();
pcntl_fork() == 0 && $registerServer->process_requests("requestProcessor") && exit();
pcntl_fork() == 0 && $likeServer->process_requests("requestProcessor") && exit();

pcntl_wait($status);
pcntl_wait($status);
pcntl_wait($status);

exit();
?>
