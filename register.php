<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection('10.147.17.197', 5672, 'rey', 'rey', 'projectVhost');
$channel = $connection->channel();

list($callbackQueue,,) = $channel->queue_declare("", false, false, true, false);

$username = $_POST['uname'] ?? '';
$password = $_POST['pword'] ?? '';
$email    = $_POST['email'] ?? '';

$data = ["action" => "register", "username" => $username, "password" => $password, "email" => $email];

$corrId = uniqid();
$msg = new AMQPMessage(json_encode($data), ['correlation_id' => $corrId, 'reply_to' => $callbackQueue]);

$channel->basic_publish($msg, '', 'auth_request');

$response = null;
$channel->basic_consume($callbackQueue, '', false, true, false, false, function($msg) use (&$response, $corrId) {
    if ($msg->get('correlation_id') === $corrId) {
        $response = $msg->body;
    }
});

$start = time();
while ($response === null && (time() - $start) < 5) {
    $channel->wait(null, false, 1);
}

if ($response === null) {
    $response = json_encode(["status" => "error", "message" => "No response from DB"]);
}

header("Content-Type: application/json");
echo $response;

$channel->close
?>
