<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// RabbitMQ config
$rabbitHost  = "10.147.17.197"; // RabbitMQ VM IP
$rabbitUser  = "benji";       // RabbitMQ account for DMZ
$rabbitPass  = "benji"; 
$rabbitVhost = "projectVhost";

// Finnhub config
$apiKey = "d3j89shr01qkv9jui1mgd3j89shr01qkv9jui1n0";  // Replace with your Fin>
$symbol = "AAPL";          // Apple stock for demo

// Fetch stock data from Finnhub
$url = "https://finnhub.io/api/v1/quote?symbol=$symbol&token=$apiKey";
$response = file_get_contents($url);
$data = json_decode($response, true);

if (!$data) {
    die("Error fetching data from Finnhub\n");
}

// Preprocess (only keep price and timestamp)
$payload = [
    "action"   => "stock_update",
    "symbol"   => $symbol,
    "price"    => $data['c'], // current price
    "time"     => time()
];

// Connect to RabbitMQ
$connection = new AMQPStreamConnection($rabbitHost, 5672, $rabbitUser, $rabbitP);
$channel = $connection->channel();
$channel->queue_declare('stock_updates', false, true, false, false);


// Publish message
$msg = new AMQPMessage(json_encode($payload));
$channel->basic_publish($msg, '', 'stock_updates');

echo "[x] Sent stock update: " . json_encode($payload) . "\n";

// Cleanup
$channel->close();
$connection->close();
?>



