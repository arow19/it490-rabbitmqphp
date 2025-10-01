<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$rabbitHost = "10.147.17.197";
$rabbitUser = "latch";
$rabbitPass = "latch";
$rabbitVhost = "projectVhost";

$dbHost = "localhost";
$dbUser = "latch";
$dbPass = "lath@123";   // make sure this is the real MySQL password
$dbName = "auth_db";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // RabbitMQ
    $conn = new AMQPStreamConnection($rabbitHost, 5672, $rabbitUser, $rabbitPass, $rabbitVhost);
    $ch = $conn->channel();
    // durable queues
    $ch->queue_declare('auth_request', false, true, false, false);
    $ch->queue_declare('auth_response', false, true, false, false);
    echo "[OK] RabbitMQ connected & queues declared\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERR] RabbitMQ: " . $e->getMessage() . "\n");
    exit(1);
}

try {
    // MySQL
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    $mysqli->set_charset('utf8mb4');
    echo "[OK] MySQL connected\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERR] MySQL: " . $e->getMessage() . "\n");
    exit(1);
}

echo "[*] Waiting for auth requests...\n";

$callback = function ($msg) use ($ch, $mysqli) {
    echo "[x] Received: ", $msg->body, "\n";
    $data = json_decode($msg->body, true);
    $response = ["status" => "error", "message" => "Invalid request"];

    if ($data && isset($data['action'])) {
        if ($data['action'] === "register") {
            $username = $mysqli->real_escape_string($data['username'] ?? '');
            $password = $data['password'] ?? '';
            $email    = $mysqli->real_escape_string($data['email'] ?? '');

            if ($username && $password) {
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                $sql = "INSERT INTO users (username, password_hash, email) VALUES ('$username', '$passwordHash', '$email')";
                if ($mysqli->query($sql)) {
                    $response = ["status" => "success", "message" => "User registered"];
                } else {
                    $response = ["status" => "error", "message" => "Registration failed: " . $mysqli->error];
                }
            } else {
                $response = ["status" => "error", "message" => "Missing username or password"];
            }
        } elseif ($data['action'] === "login") {
            $username = $mysqli->real_escape_string($data['username'] ?? '');
            $password = $data['password'] ?? '';

            $sql = "SELECT id, password_hash FROM users WHERE username='$username'";
            $result = $mysqli->query($sql);
            if ($result && $row = $result->fetch_assoc()) {
                if (password_verify($password, $row['password_hash'])) {
                    $sessionKey = bin2hex(random_bytes(16));
                    $userId = (int)$row['id'];
                    $mysqli->query("INSERT INTO sessions (user_id, session_key, expires_at)
                                    VALUES ($userId, '$sessionKey', DATE_ADD(NOW(), INTERVAL 1 HOUR))");
                    $response = ["status" => "success", "session" => $sessionKey];
                } else {
                    $response = ["status" => "error", "message" => "Invalid password"];
                }
            } else {
                $response = ["status" => "error", "message" => "User not found"];
            }
        }
    }

    $msgOut = new AMQPMessage(json_encode($response, JSON_UNESCAPED_SLASHES));
    $ch->basic_publish($msgOut, '', 'auth_response');
    echo "[x] Sent response: " . json_encode($response) . "\n";
};

// auto-ack = true (your original choice)
$ch->basic_consume('auth_request', '', false, true, false, false, $callback);

while ($ch->is_consuming()) {
    $ch->wait();
}

