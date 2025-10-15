<?php
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPTimeoutException;

/* ---- CONFIG ---- */
$rabbitHost = '10.147.17.197';
$rabbitUser = 'rey';
$rabbitPass = 'rey';
$vhost      = 'projectVhost';
$apiKey     = 'd3jbk1hr01qkv9juvbh0d3jbk1hr01qkv9juvbhg';

/* ---- CONNECT ---- */
$conn = new AMQPStreamConnection($rabbitHost, 5672, $rabbitUser, $rabbitPass, $vhost);
$ch   = $conn->channel();

$ch->queue_declare('request_sell_limit_trade', false, true, false, false);
$ch->queue_declare('response_sell_limit_trade', false, true, false, false);
$ch->queue_declare('request_trade_execution',  false, true, false, false);

$sellLimitOrders = [];

echo "[DMZ] Sell Limit Consumer started...\n";

/* ---- Handle incoming limit sell orders ---- */
$callback = function ($msg) use (&$sellLimitOrders, $ch) {
    $data = json_decode($msg->body, true);
    if (!$data || empty($data['symbol']) || empty($data['limit_price'])) {
        echo "[!] Invalid sell limit message: {$msg->body}\n";
        return;
    }

    $data['placed_at'] = time();
    $sellLimitOrders[] = $data;

    echo "[+] Added sell limit order: " . json_encode($data) . "\n";

    $resp = new AMQPMessage(
        json_encode(['status' => 'success', 'message' => 'Sell limit order placed']),
        ['correlation_id' => $msg->get('correlation_id')]
    );
    $ch->basic_publish($resp, '', 'response_sell_limit_trade');
};

/* ---- Start listening for new limit orders ---- */
$ch->basic_consume('request_sell_limit_trade', '', false, true, false, false, $callback);

/* ---- Periodic Price Checker ---- */
$lastCheck = 0;  // timestamp of last Finnhub price check

while (true) {
    // 1️⃣  Always listen for new messages (non-blocking)
    try {
        $ch->wait(null, false, 1); // Wait 1s for new RabbitMQ message
    } catch (AMQPTimeoutException $e) {
        // No message this second — totally fine
    } catch (Throwable $e) {
        echo "[!] Error while waiting for message: {$e->getMessage()}\n";
    }

    // 2️⃣  Every 60 seconds, check open limit orders
    if (time() - $lastCheck >= 60 && count($sellLimitOrders) > 0) {
        echo "[DMZ] Checking " . count($sellLimitOrders) . " active sell limit orders...\n";

        foreach ($sellLimitOrders as $i => $order) {
            $symbol  = strtoupper(trim($order['symbol']));
            $limit   = floatval($order['limit_price']);
            $qty     = floatval($order['quantity']);
            $session = $order['session'];

            $url = "https://finnhub.io/api/v1/quote?symbol={$symbol}&token={$apiKey}";
            $quote = json_decode(@file_get_contents($url), true);
            if (!$quote || !isset($quote['c'])) continue;

            $current = floatval($quote['c']);
            echo "[~] {$symbol}: current={$current}, limit={$limit}\n";

            // 3️⃣  Trigger sell when current price ≥ limit
            if ($current >= $limit) {
                echo "[!] Triggering SELL {$symbol} @ {$current} (limit {$limit})\n";

                $exec = [
                    'action'   => 'request_trade_execution',
                    'session'  => $session,
                    'symbol'   => $symbol,
                    'quantity' => $qty,
                    'side'     => 'sell',
                    'price'    => $current
                ];

                $ch->basic_publish(
                    new AMQPMessage(json_encode($exec), ['content_type' => 'application/json']),
                    '',
                    'request_trade_execution'
                );

                unset($sellLimitOrders[$i]);
            }
        }

        // Re-index array after removals
        $sellLimitOrders = array_values($sellLimitOrders);
        $lastCheck = time();
    }
}
?>

