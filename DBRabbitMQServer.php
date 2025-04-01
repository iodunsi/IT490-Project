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
        "rate" => rateArticle($request),
        "get_average_rating" => getAverageRating($request),
        "comment" => saveComment($request),
        "get_comments" => fetchComments($request),

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

function rateArticle($request) {
    $db = getDatabaseConnection();
    if (!$db) return ["status" => "error", "message" => "Database connection failed"];

    // Get user ID
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $request['user']);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
        $stmt->close();
        $db->close();
        return ["status" => "error", "message" => "User not found"];
    }

    $stmt->bind_result($userId);
    $stmt->fetch();
    $stmt->close();

    // Check if rating exists
    $stmt = $db->prepare("SELECT id FROM ratings WHERE user_id = ? AND article_id = ?");
    $stmt->bind_param("is", $userId, $request['articleId']);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        // Update existing rating
        $stmt->close();
        $stmt = $db->prepare("UPDATE ratings SET rating = ?, rated_at = NOW() WHERE user_id = ? AND article_id = ?");
        $stmt->bind_param("iis", $request['rating'], $userId, $request['articleId']);
        $stmt->execute();
        $stmt->close();
    } else {
        // Insert new rating
        $stmt->close();
        $stmt = $db->prepare("INSERT INTO ratings (user_id, article_id, title, url, rating, rated_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("isssi", $userId, $request['articleId'], $request['title'], $request['url'], $request['rating']);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $db->prepare("SELECT AVG(rating) FROM ratings WHERE article_id = ?");
    $stmt->bind_param("s", $request['articleId']);
    $stmt->execute();
    $stmt->bind_result($avgRating);
    $stmt->fetch();
    $stmt->close();

    $db->close();
    return [
        "status" => "success",
        "message" => "Article rated successfully",
        "article_id" => $request['articleId'],
        "timestamp" => date("c"),
        "averageRating" => round($avgRating, 1)
        ];

}


function getAverageRating($request) {
    $db = getDatabaseConnection();
    if (!$db) return ["status" => "error", "message" => "Database connection failed"];

    $stmt = $db->prepare("SELECT AVG(rating) AS avg_rating FROM ratings WHERE article_id = ?");
    $stmt->bind_param("s", $request['articleId']);
    $stmt->execute();
    $stmt->bind_result($average);
    $stmt->fetch();
    $stmt->close();
    $db->close();

    return [
        "status" => "success",
        "averageRating" => round($average, 1)
    ];
}


// ✅ Like an article
function likeArticle($request) {
    $db = getDatabaseConnection();
    if (!$db) return ["status" => "error", "message" => "Database connection failed"];

    // Fetch the user ID based on the username
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    if (!$stmt) {
        error_log("Database error while preparing statement: " . $db->error);
        return ["status" => "error", "message" => "Database error"];
    }

    $stmt->bind_param("s", $request['user']);
    $stmt->execute();
    $stmt->store_result();
    
    // Check if the user exists
    if ($stmt->num_rows === 0) {
        $stmt->close();
        $db->close();
        return ["status" => "error", "message" => "User not found"];
    }

    // Bind the result to the user ID variable
    $stmt->bind_result($userId);
    $stmt->fetch();
    $stmt->close();

    $stmt = $db->prepare("SELECT id FROM likes WHERE user_id = ? AND article_id = ?");
    
    $stmt->bind_param("is", $userId, $request['articleId']);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        $db->close();
        return ["status" => "success", "message" => "Article already liked"];
    }
    $stmt->close();

    // Insert the like record
    $stmt = $db->prepare("INSERT INTO likes (user_id, article_id, title, url, category, liked_at) VALUES (?, ?, ?, ?, ?, NOW())");
    if (!$stmt) {
        error_log("Database error while preparing insert statement: " . $db->error);
        return ["status" => "error", "message" => "Database error"];
    }

    $stmt->bind_param("issss", $userId, $request['articleId'], $request['title'], $request['url'], $request['category']);
    if (!$stmt->execute()) {
        error_log("Database error while executing insert statement: " . $stmt->error);
        $stmt->close();
        $db->close();
        return ["status" => "error", "message" => "Error inserting like record"];
    }

    $stmt->close();
    $db->close();

    return ["status" => "success", "message" => "Article liked successfully"];
}

function saveComment($request) {
    $db = getDatabaseConnection();
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $request['user']);
    $stmt->execute();
    $stmt->bind_result($userId);
    $stmt->fetch();
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO comments (user_id, article_id, title, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("isss", $userId, $request['articleId'], $request['title'], $request['comment']);
    $stmt->execute();
    $stmt->close();
    $db->close();

    return ["status" => "success", "message" => "Comment saved"];
}

function fetchComments($request) {
    $db = getDatabaseConnection();
    $stmt = $db->prepare("SELECT u.username, c.comment FROM comments c JOIN users u ON c.user_id = u.id WHERE c.article_id = ? ORDER BY c.created_at DESC");
    $stmt->bind_param("s", $request['articleId']);
    $stmt->execute();
    $result = $stmt->get_result();

    $comments = [];
    while ($row = $result->fetch_assoc()) {
        $comments[] = ["user" => $row['username'], "comment" => $row['comment']];
    }

    $stmt->close();
    $db->close();
    return ["status" => "success", "comments" => $comments];
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
