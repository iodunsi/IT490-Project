<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('mysqlconnect.php');
require('/home/paa39/git/IT490-Project/vendor/autoload.php');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

ini_set("log_errors", 1);
ini_set("error_log", "/var/log/rabbitmq_errors.log");

// Load environment variables
function loadEnv() {
    if (!file_exists('.env')) {
        error_log("Error: .env file not found");
        exit(1);
    }
    $lines = file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $keyValue = explode('=', trim($line), 2);
        if (count($keyValue) == 2) {
            putenv(trim($keyValue[0]) . '=' . trim($keyValue[1]));
        }
    }
}

loadEnv();

// Establish database connection
function getDatabaseConnection() {
    $dbHost = getenv("DB_HOST") ?: "127.0.0.1";
    $dbUser = getenv("DB_USER") ?: "testUser";
    $dbPassword = getenv("DB_PASSWORD") ?: "12345";
    $dbName = getenv("DB_NAME") ?: "login";

    $db = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
    if ($db->connect_errno) {
        error_log("Database connection failed: " . $db->connect_error);
        return null;
    }
    return $db;
}

// Request Processor
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
        "share" => shareArticle($request),
        default => ["status" => "error", "message" => "Unknown request type"]
    };
}

// Validate user login
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

// Register a new user
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
    $stmt = $db->prepare("INSERT INTO users (username, password, first_name, last_name, dob, email, phone, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    if (!$stmt) return ["status" => "error", "message" => "Database error"];

    $stmt->bind_param("ssssss", $data['username'], $data['password'], $data['first_name'], $data['last_name'], $data['dob'], $data['email'], $data['phone']);
    if ($stmt->execute()) {
        $stmt->close();
        $db->close();
        return sendWelcomeEmail($data['email'], $data['first_name']);
    } else {
        $stmt->close();
        $db->close();
        return ["status" => "error", "message" => "User registration failed"];
    }
}

// Send Welcome Email via RabbitMQ
function sendWelcomeEmail($email, $first_name) {
    try {
        error_log("[DEBUG] Initializing rabbitMQClient for emailQueue", 3, "/var/log/rabbitmq_errors.log");
        $client = new rabbitMQClient("emailRabbitMQ.ini", "emailQueue");
        $request = [
            "type" => "send_email",
            "to" => $email,
            "subject" => "Welcome to News Nexus!",
            "message" => "Hello $first_name, <br><br>Thank you for registering! We’re excited to have you on board. <br><br>Regards, <br><h2>News Nexus Team</h2>"
        ];
        error_log("[DEBUG] Sending to emailQueue: " . json_encode($request), 3, "/var/log/rabbitmq_errors.log");
        $response = $client->send_request($request);
        error_log("[DEBUG] emailQueue response: " . json_encode($response), 3, "/var/log/rabbitmq_errors.log");
        if ($response && isset($response['status']) && $response['status'] === "success") {
            return ["status" => "success", "message" => "User registered and email sent"];
        } else {
            return ["status" => "error", "message" => "User registered, but email failed: " . json_encode($response)];
        }
    } catch (Exception $e) {
        error_log("[ERROR] Email send failed: " . $e->getMessage(), 3, "/var/log/rabbitmq_errors.log");
        return ["status" => "error", "message" => "User registered, but email request failed: " . $e->getMessage()];
    }
}



// User logout
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

// Like an article
function likeArticle($request) {
    $db = getDatabaseConnection();
    if (!$db) return ["status" => "error", "message" => "Database connection failed"];

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

    $stmt = $db->prepare("INSERT INTO likes (user_id, article_id, title, url, category, liked_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("issss", $userId, $request['articleId'], $request['title'], $request['url'], $request['category']);
    $stmt->execute();
    $stmt->close();
    $db->close();

    return ["status" => "success", "message" => "Article liked successfully"];
}

// Rate an article
function rateArticle($request) {
    $db = getDatabaseConnection();
    if (!$db) return ["status" => "error", "message" => "Database connection failed"];

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

    $stmt = $db->prepare("SELECT id FROM ratings WHERE user_id = ? AND article_id = ?");
    $stmt->bind_param("is", $userId, $request['articleId']);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        $stmt = $db->prepare("UPDATE ratings SET rating = ?, rated_at = NOW() WHERE user_id = ? AND article_id = ?");
        $stmt->bind_param("iis", $request['rating'], $userId, $request['articleId']);
        $stmt->execute();
        $stmt->close();
    } else {
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

// Get average rating
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

// Save a comment
function saveComment($request) {
    $db = getDatabaseConnection();
    if (!$db) return ["status" => "error", "message" => "Database connection failed"];

    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $request['user']);
    $stmt->execute();
    $stmt->bind_result($userId);
    $stmt->fetch();
    $stmt->close();

    $stmt = $db->prepare("INSERT INTO comments (user_id, article_id, title, comment, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param("isss", $userId, $request['articleId'], $request['_title'], $request['comment']);
    $stmt->execute();
    $stmt->close();
    $db->close();

    return ["status" => "success", "message" => "Comment saved"];
}

// Fetch comments
function fetchComments($request) {
    $db = getDatabaseConnection();
    if (!$db) return ["status" => "error", "message" => "Database connection failed"];

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

// Share an article
function shareArticle($request) {
    $db = getDatabaseConnection();
    if (!$db) return ["status" => "error", "message" => "Database connection failed"];

    $stmt = $db->prepare("SELECT email FROM users WHERE username = ?");
    $stmt->bind_param("s", $request['to_user']);
    $stmt->execute();
    $stmt->bind_result($recipientEmail);
    
    if (!$stmt->fetch()) {
        $stmt->close();
        $db->close();
        return ["status" => "error", "message" => "Recipient not found"];
    }
    $stmt->close();
    $db->close();

    try {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = getenv("EMAIL_USER");
        $mail->Password = getenv("EMAIL_PASS");
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        $mail->setFrom(getenv("EMAIL_USER"), 'News Nexus');
        $mail->addAddress($recipientEmail);
        $mail->addReplyTo(getenv("EMAIL_USER"), 'News Nexus');

        $mail->isHTML(true);
        $mail->Subject = "{$request['from_user']} shared an article with you!";
        $mail->Body = "
            <h3>{$request['from_user']} thinks you'll like this:</h3>
            <p><strong>{$request['title']}</strong></p>
            <p><a href=\"{$request['url']}\">Read Article</a></p>
            <p><em>Shared via News Nexus</em></p>
        ";
        $mail->AltBody = "{$request['from_user']} shared an article: {$request['url']}";

        $mail->send();
        return ["status" => "success", "message" => "Article shared successfully"];
    } catch (Exception $e) {
        error_log("[SHARE ERROR] ❌ PHPMailer error: " . $mail->ErrorInfo);
        return ["status" => "error", "message" => "Failed to send email"];
    }
}

// Server startup
echo "[RABBITMQ VM] 🚀 RabbitMQ Server is waiting for messages...\n";

$loginServer = new rabbitMQServer("testRabbitMQ.ini", "loginQueue");
$registerServer = new rabbitMQServer("testRabbitMQ.ini", "registerQueue");
$newsServer = new rabbitMQServer("testRabbitMQ.ini", "newsQueue");

pcntl_fork() == 0 && $loginServer->process_requests("requestProcessor") && exit();
pcntl_fork() == 0 && $registerServer->process_requests("requestProcessor") && exit();
pcntl_fork() == 0 && $newsServer->process_requests("requestProcessor") && exit();

while (true) {
    pcntl_wait($status);
    sleep(1);
}
?>


