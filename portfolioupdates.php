<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$apiKey = 'd3jbk1hr01qkv9juvbh0d3jbk1hr01qkv9juvbhg';
$rabbitHost = '10.147.17.197';
$rabbitUser = 'rey';
$rabbitPass = 'rey';
$vhost      = 'projectVhost';

echo "[DMZ] Starting sell stock consumer\n";

try {
    $connection = new AMQPStreamConnection($rabbitHost, 5672, $rabbitUser, $rabbitPass, $vhost);
    $channel = $connection->channel();

    $channel->queue_declare('request_sell_stock', false, true, false, false);
    $channel->queue_declare('response_sell_stock', false, true, false, false);

    echo "[DMZ] Waiting for messages on 'request_sell_stock'...\n";

    $callback = function ($msg) use ($channel, $apiKey) {
        echo "[DMZ] Received sell stock request: {$msg->body}\n";

        $data = json_decode($msg->body, true);
        if (!is_array($data) || empty($data['symbol'])) {
            echo "[DMZ] Invalid message format\n";
            return;
        }

        $symbol = strtoupper(trim($data['symbol']));
        $corrId = $msg->get('correlation_id');
        $replyTo = $msg->get('reply_to') ?: 'response_sell_stock';

        $price = null;
        $error = null;
        try {
            $url = "https://finnhub.io/api/v1/quote?symbol={$symbol}&token={$apiKey}";
            $response = @file_get_contents($url);
            if ($response !== false) {
                $json = json_decode($response, true);
                if (isset($json['c']) && $json['c'] > 0) {
                    $price = floatval($json['c']);
                } else {
                    $error = 'Invalid price data from API';
                }
            } else {
                $error = 'API request failed';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $payload = [
            'status' => $price ? 'success' : 'error',
            'symbol' => $symbol,
            'price'  => $price,
            'message'=> $price ? "Live price fetched for {$symbol}" : ($error ?? 'Unknown error')
        ];

        $responseMsg = new AMQPMessage(
            json_encode($payload),
            [
                'correlation_id' => $corrId,
                'content_type'   => 'application/json'
            ]
        );

        $channel->basic_publish($responseMsg, '', $replyTo);
        echo "[DMZ] Published response to '{$replyTo}' for {$symbol}: " . json_encode($payload) . "\n";
    };

    $channel->basic_consume('request_sell_stock', '', false, true, false, false, $callback);

    while ($channel->is_consuming()) {
        $channel->wait();
    }

} catch (Throwable $e) {
    echo "[DMZ] Error: {$e->getMessage()}\n";
    exit(1);
}

