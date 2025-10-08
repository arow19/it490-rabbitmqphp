<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;

try{
// connect to RabbitMQ
$connection = new AMQPStreamConnection('localhost', 5672, 'andrew', 'andrew', 'projectVhost');
$channel = $connection->channel();

// make sure queue exists
$channel->queue_declare('stock_updates', false, true, false, false);

echo " [*] Waiting for messages. To exit press CTRL+C\n";

$callback = function ($msg) {
    echo ' [x] Received ', $msg->body, "\n";
};

$channel->basic_consume('stock_updates', '', false, true, false, false, $callback);

    while (true) {
        try {
            // Wait for a message for up to 1 second
            $channel->wait(null, false, 1);
        } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
            // Timeout, no message received, just continue
            continue;
        } catch (\PhpAmqLib\Exception\AMQPIOException $e) {
            // Connection closed
            echo "\n[*] Connection closed by RabbitMQ. Exiting gracefully.\n";
            break;
        } catch (\Exception $e) {
            // Any other unexpected error
            echo "\n[*] Unexpected error: " . $e->getMessage() . "\n";
            break;
        }
    }
} catch (\Exception $e) {
    echo "\n[*] Could not connect: " . $e->getMessage() . "\n";
}

// Clean up only if still connected
if (isset($channel) && $channel->is_open()) {
    $channel->close();
}
if (isset($connection) && $connection->isConnected()) {
    $connection->close();
}

?>
