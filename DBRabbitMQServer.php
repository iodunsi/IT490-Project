#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('mysqlconnect.php');

ini_set("log_errors", 1);
ini_set("error_log", "/var/log/rabbitmq_errors.log");

// 🔧 Load Environment Variables
function loadEnv() {
    if (!file_exists('.env')) {
        error_log("Error: .env file not found");
        return;
    }
    foreach (file('.env') as $line) {
        $line = trim($line);
        if ($line && strpos($line, '#') !== 0) {
            putenv($line);
        }
    }
}

// 🔗 Get Database Connection
function getDatabaseConnection() {
    $db = new mysqli(
        getenv("DB_HOST"),
        getenv("DB_USER"),
        getenv("DB_PASSWORD"),
        getenv("DB_NAME")
    );

    if ($db->connect_errno) {
        error_log("Database connection failed: " . $db->connect_error);
        return null;
    }
    return $db;
}

// 📝 Send JSON Response
function jsonResponse($status, $message) {
    return ["status" => $status, "message" => $message];
}

// 🛑 Log Error
function logError($message) {
    error_log("[ERROR] ❌ " . $message);
}

// ✅ Process RabbitMQ Request
function requestProcessor($request) {
    $sanitizedRequest = $request;
    if (isset($sanitizedRequest['password'])) {
        $sanitizedRequest['password'] = '[REDACTED]';
    }

    error_log("[RABBITMQ VM] 📩 Processing request: " . json_encode($sanitizedRequest) . "\n");

    if (!isset($request['type'])) {
        return jsonResponse("error", "Unsupported request type");
    }

    return match ($request['type']) {
        "login" => validateLogin($request['username'], $request['password']),
        "register" => registerUser($request),
        "logout" => logoutUser($request),
        "like" => likeArticle($request),
        default => jsonResponse("error", "Unknown request type: " . $request['type'])
    };
}

// 🔑 Validate User Login
function validateLogin($username, $password) {
    $db = getDatabaseConnection();
    if (!$db) return jsonResponse("error", "Database connection failed");

    $stmt = $db->prepare("SELECT password FROM users WHERE username = ?");
    if (!$stmt) return jsonResponse("error", "Database error");

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
        $stmt->close();
        return jsonResponse("error", "User not found");
    }

    $stmt->bind_result($hashedPassword);
    $stmt->fetch();
    $stmt->close();

    if (!password_verify($password, $hashedPassword)) {
        return jsonResponse("error", "Incorrect password");
    }

    return jsonResponse("success", "Login successful");
}

// 📝 Register New User
function registerUser($data) {
    $db = getDatabaseConnection();
    if (!$db) return jsonResponse("error", "Database connection failed");

    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $data['username']);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        return jsonResponse("error", "Username already exists");
    }

    $stmt->close();
    $stmt = $db->prepare("INSERT INTO users (username, password, first_name, last_name, dob, email, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) return jsonResponse("error", "Database error");

    $stmt->bind_param("ssssss", $data['username'], $data['password'], $data['first_name'], $data['last_name'], $data['dob'], $data['email']);
    $result = $stmt->execute();
    $stmt->close();

    return $result ? jsonResponse("success", "User registered successfully") : jsonResponse("error", "Registration failed");
}

// 🚪 Logout User
function logoutUser($data) {
    $db = getDatabaseConnection();
    if (!$db) return jsonResponse("error", "Database connection failed");

    $stmt = $db->prepare("UPDATE users SET session_key = NULL, session_expires = NULL WHERE username = ?");
    $stmt->bind_param("s", $data['username']);
    $result = $stmt->execute();
    $stmt->close();

    return $result ? jsonResponse("success", "User logged out successfully") : jsonResponse("error", "Logout failed");
}

// 👍 Like Article
function likeArticle($request) {
    $db = getDatabaseConnection();
    if (!$db) return jsonResponse("error", "Database connection failed");

    $userId = $request['user'];
    $articleId = $request['articleId'];
    $title = $request['title'];
    $url = $request['url'];
    $category = $request['category'] ?? "Uncategorized";
    $timestamp = date("Y-m-d H:i:s");

    $stmt = $db->prepare("SELECT id FROM likes WHERE user_id = ? AND article_id = ?");
    $stmt->bind_param("ss", $userId, $articleId);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        return jsonResponse("error", "Already liked");
    }

    $stmt->close();
    $stmt = $db->prepare("INSERT INTO likes (user_id, article_id, title, url, category, liked_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $userId, $articleId, $title, $url, $category, $timestamp);
    $result = $stmt->execute();
    $stmt->close();

    return $result ? jsonResponse("success", "Article liked successfully") : jsonResponse("error", "Failed to save like");
}

// 🚀 RabbitMQ Server Initialization
echo "[RABBITMQ VM] 🚀 RabbitMQ Server is waiting for messages...\n";
error_log("[RABBITMQ VM] 🚀 RabbitMQ Server is waiting for messages...\n", 3, "/var/log/rabbitmq_errors.log");

$loginServer = new rabbitMQServer("testRabbitMQ.ini", "loginQueue");
$registerServer = new rabbitMQServer("testRabbitMQ.ini", "registerQueue");
$likeServer = new rabbitMQServer("testRabbitMQ.ini", "newsQueue");

// 🛑 Handle Forks for Each Server
foreach ([$loginServer, $registerServer, $likeServer] as $server) {
    $pid = pcntl_fork();
    if ($pid == 0) {
        $server->process_requests("requestProcessor");
        exit();
    }
}

// ✅ Parent process waits for child processes
pcntl_wait($status);
pcntl_wait($status);
pcntl_wait($status);

exit();
?>
