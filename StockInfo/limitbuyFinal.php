<?php
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$APIKeyFile = fopen("../doNotPushToGit/finnhubAPIKey", "r") or die("Unable to open file!");
$apiKey = fread($APIKeyFile,filesize("../doNotPushToGit/finnhubAPIKey"));
fclose($APIKeyFile);
$conn = new AMQPStreamConnection('10.147.17.197', 5672, 'benji', 'benji', 'projectVhost');
$ch   = $conn->channel();

$ch->queue_declare('request_buy_limit_trade', false, true, false, false);
$ch->queue_declare('response_buy_limit_trade', false, true, false, false);
$ch->queue_declare('request_trade_execution',  false, true, false, false);

$buyLimitOrders = [];

echo "Buy Limit Listener started...\n";
$callback = function ($msg) use (&$buyLimitOrders, $ch) {
    $data = json_decode($msg->body, true);
    if (!$data || empty($data['symbol']) || empty($data['limit_price'])) {
        echo "Invalid buy limit message: {$msg->body}\n";
        return;
    }
    $data['placed_at'] = time();
    $buyLimitOrders[] = $data;
    echo "Added buy limit order: " . json_encode($data) . "\n";
    $resp = new AMQPMessage(
        json_encode(['status' => 'success', 'message' => 'Buy limit order placed']),
        ['correlation_id' => $msg->get('correlation_id')]
    );
    $ch->basic_publish($resp, '', 'response_buy_limit_trade');
};
$ch->basic_consume('request_buy_limit_trade', '', false, true, false, false, $callback);
$lastCheck = 0;
while (true) {
        $ch->wait(null, false, 1);
    if (time() - $lastCheck >= 20 && count($buyLimitOrders) > 0) {
        echo "Checking " . count($buyLimitOrders) . " active buy limit orders...\n";

        foreach ($buyLimitOrders as $i => $order) {
            $symbol  = strtoupper(trim($order['symbol']));
            $limit   = floatval($order['limit_price']);
            $qty     = floatval($order['quantity']);
            $session = $order['session'];
            
            $url = "https://finnhub.io/api/v1/quote?symbol={$symbol}&token={$apiKey}";
            $quote = json_decode(@file_get_contents($url), true);
            if (!$quote || !isset($quote['c'])) continue;
            $current = floatval($quote['c']);
            echo "{$symbol}: current={$current}, limit={$limit}\n";
            if ($current <= $limit) {
                echo "Triggering BUY {$symbol} @ {$current} (limit {$limit})\n";
                $exec = [
                    'action'   => 'request_trade_execution',
                    'session'  => $session,
                    'symbol'   => $symbol,
                    'quantity' => $qty,
                    'side'     => 'buy',
                    'price'    => $current
                ];
                $ch->basic_publish(
                    new AMQPMessage(json_encode($exec), ['content_type' => 'application/json']),
                    '',
                    'request_trade_execution'
                );
                unset($buyLimitOrders[$i]);
            }
        }
        $buyLimitOrders = array_values($buyLimitOrders);
        $lastCheck = time();
    }
}
?>

