<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$session = $_GET['session'] ?? '';
if (!$session) {
    die("No session provided. Please login again.");
}

$connection = new AMQPStreamConnection('10.147.17.197', 5672, 'rey', 'rey', 'projectVhost');
$channel = $connection->channel();

$channel->queue_declare('auth_request', false, true, false, false);
$channel->queue_declare('auth_response', false, true, false, false);

$data = ["action" => "validate", "session" => $session];

$corrId = uniqid();
$msg = new AMQPMessage(json_encode($data), ['correlation_id' => $corrId, 'reply_to' => 'auth_response']);

$channel->basic_publish($msg, '', 'auth_request');

$response = null;
$callback = function ($msg) use (&$response, $corrId) {
    if ($msg->get('correlation_id') == $corrId) {
        $response = $msg->body;
    }
};

$channel->basic_consume('auth_response', '', false, true, false, false, $callback);

$start = time();
while ($response === null && (time() - $start) < 5) {
    $channel->wait(null, false, 1);
}

if ($response === null) {
    die("No response from authentication server.");
}

$result = json_decode($response, true);

$channel->close();
$connection->close();

if (!$result || $result['status'] !== 'success') {
    die("Invalid or expired session. Please login again.");
}
?>

<html>
<head>
  <title>Home</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background: #eef2f7;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
    }

    .home-container {
      background: #fff;
      padding: 2rem;
      border-radius: 10px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
      text-align: center;
    }

    button {
      padding: 10px 16px;
      border: none;
      border-radius: 6px;
      background: #007BFF;
      color: #fff;
      cursor: pointer;
      font-size: 14px;
    }

    button:hover {
      background: #0056b3;
    }
  </style>
</head>
<body>
  <div class="home-container">
    <h1>Welcome To Pivot Point!</h1>
    <p>You are successfully logged in.</p>
    <p>Your Session Key: <b><?php echo htmlspecialchars($session); ?></b></p>
    <button onclick="window.location.href='index.html'">Logout</button>
  </div>
</body>
</html>

