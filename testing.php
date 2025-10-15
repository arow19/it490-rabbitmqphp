<?php
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// RabbitMQ info (same as your system)
$connection = new AMQPStreamConnection('localhost', 5672, 'latch', 'latch', 'projectVhost');
$channel = $connection->channel();

// Queues used by the consumer
$channel->queue_declare('portfolio_request', false, true, false, false);
$channel->queue_declare('portfolio_response', false, true, false, false);

// Example test payload — adjust session key to one that exists in your DB
$testData = [
    'action'  => 'get_portfolio_summary',
    'session' => 'e181bb389bf858349ecee31bb81e49c5'
];
$corrId = bin2hex(random_bytes(12));

$msg = new AMQPMessage(json_encode($testData), [
    'correlation_id' => $corrId,
    'reply_to' => 'portfolio_response'
]);

$channel->basic_publish($msg, '', 'portfolio_request');
echo "[>] Sent test message: " . json_encode($testData) . "\n";

// Wait for response
$response = null;
$callback = function($msg) use (&$response, $corrId) {
    if ($msg->get('correlation_id') === $corrId) {
        $response = $msg->body;
    }
};

$channel->basic_consume('portfolio_response', '', false, true, false, false, $callback);

$start = time();
while ($response === null && (time() - $start) < 6) {
    $channel->wait(null, false, 1);
}

if ($response) {
    echo "[<] Received response:\n" . $response . "\n";
} else {
    echo "[x] No response received (timeout)\n";
}

$channel->close();
$connection->close();
