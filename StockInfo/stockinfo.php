<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// === CONFIGURATION ===
$rabbitHost = '10.147.17.197';
$rabbitUser = 'benji';
$rabbitPass = 'benji';
$rabbitVhost = 'projectVhost';
$finnhubKey = 'd3mio7pr01qmso340tq0d3mio7pr01qmso340tqg'; // replace with valid key

// === CONNECT TO RABBITMQ ===
try {
    $connection = new AMQPStreamConnection($rabbitHost, 5672, $rabbitUser, $rabbitPass, $rabbitVhost);
    $channel = $connection->channel();

    $channel->queue_declare('stock_requests', false, true, false, false);
    $channel->queue_declare('stock_updates', false, true, false, false);

    echo "[*] DMZ Consumer started — waiting for stock_requests...\n";
} catch (Throwable $e) {
    echo "[!] RabbitMQ connection failed: {$e->getMessage()}\n";
    exit(1);
}

// === MESSAGE HANDLER ===
$callback = function ($msg) use ($channel, $finnhubKey) {
    $corrId = $msg->get('correlation_id');
    $replyQueue = $msg->get('reply_to') ?: 'stock_updates';

    $body = $msg->body;
    $data = json_decode($body, true);

    echo "\n[>] Received message: $body\n";

    $response = ['status' => 'error', 'message' => 'Invalid request'];

    if ($data && isset($data['action']) && $data['action'] === 'fetch_stock') {
        $symbol = strtoupper(trim($data['symbol'] ?? ''));

        if ($symbol === '') {
            $response = ['status' => 'error', 'message' => 'No symbol provided'];
            echo "[x] No symbol provided.\n";
        } else {
            $url = "https://finnhub.io/api/v1/quote?symbol=$symbol&token=$finnhubKey";
            echo "[~] Fetching $symbol from Finnhub...\n";

            $opts = [
                "http" => [
                    "timeout" => 5,
                    "ignore_errors" => true,
                    "header" => "User-Agent: PivotPointDMZ/1.0\r\n"
                ]
            ];
            $context = stream_context_create($opts);
            $result = @file_get_contents($url, false, $context);

            if ($result === false) {
                $response = ['status' => 'error', 'message' => 'Finnhub unreachable'];
                echo "[x] Finnhub unreachable for $symbol.\n";
            } else {
                $quote = json_decode($result, true);
                if (isset($quote['c'])) {
                    $response = [
                        'status'  => 'success',
                        'symbol'  => $symbol,
                        'price'   => $quote['c'],
                        'change'  => $quote['d'] ?? 0,
                        'percent' => $quote['dp'] ?? 0
                    ];
                    echo "[✓] $symbol => Price: {$quote['c']} Change: {$quote['d']} ({$quote['dp']}%)\n";
                } else {
                    $response = ['status' => 'error', 'message' => 'Invalid API data'];
                    echo "[x] Invalid API response for $symbol.\n";
                }
            }
        }
    } else {
        echo "[x] Unknown or missing action field.\n";
    }

    // === PUBLISH REPLY ===
    $outMsg = new AMQPMessage(
        json_encode($response),
        [
            'correlation_id' => $corrId,
            'content_type' => 'application/json'
        ]
    );

    $channel->basic_publish($outMsg, '', $replyQueue);
    echo "[<] Sent response to '$replyQueue': " . json_encode($response) . "\n";
};

// === START CONSUMING ===
$channel->basic_consume('stock_requests', '', false, true, false, false, $callback);

// === MAIN LOOP ===
while (true) {
    try {
        $channel->wait();
    } catch (Throwable $e) {
        echo "[!] Error: {$e->getMessage()}\n";
        sleep(2); // slight backoff to prevent crash loops
    }
}
?>