<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// --- RabbitMQ Info ---
$rabbitHost  = '10.147.17.197';
$rabbitUser  = 'benji';
$rabbitPass  = 'benji';
$rabbitVhost = 'projectVhost';

// --- API KEY (read from file) ---
$APIKeyFile = fopen("../doNotPushToGit/polygonAPIKey", "r") or die("Unable to open file!");
$apiKey = fread($APIKeyFile, filesize("../doNotPushToGit/polygonAPIKey"));
fclose($APIKeyFile);
$apiKey = trim($apiKey);

$conn = new AMQPStreamConnection($rabbitHost, 5672, $rabbitUser, $rabbitPass, $rabbitVhost);
$ch   = $conn->channel();

$ch->queue_declare('volatility_request',  false, true, false, false);
$ch->queue_declare('volatility_response', false, true, false, false);

echo "[DMZ] Waiting for volatility requests...\n";

// --- Callback for processing incoming messages ---
$callback = function ($msg) use ($ch, $apiKey) {
    echo "[DMZ] Received request: {$msg->body}\n";

    $request = json_decode($msg->body, true);
    if (!$request || !isset($request['symbol'])) {
        echo "[DMZ] Invalid request payload (expected 'symbol').\n";
        return;
    }

    $symbol = strtoupper($request['symbol']);
    $days   = isset($request['days']) ? (int)$request['days'] : 30;

    $to   = time() - 86400;
    $from = $to - ($days * 86400);

    $toDate   = date("Y-m-d", $to);
    $fromDate = date("Y-m-d", $from);

    // --- Fetch market data from Polygon.io ---
    $url = "https://api.polygon.io/v2/aggs/ticker/$symbol/range/1/day/$fromDate/$toDate"
         . "?adjusted=true&sort=asc&limit=5000&apiKey=$apiKey";

    $chCurl = curl_init();
    curl_setopt($chCurl, CURLOPT_URL, $url);
    curl_setopt($chCurl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($chCurl);
    curl_close($chCurl);

    if (!$response) {
        echo "[DMZ] Error fetching data for $symbol\n";
        return;
    }

    
    $data = json_decode($response, true);


    // echo "TESTING -------- \n";
    // foreach ($data as $key => $value) {
    //     echo "$key : ";
    //     if (is_array($value)) {
    //         echo json_encode($value) . "\n";
    //     } else {
    //         echo "$value\n";
    //     }
    // }


    if (!isset($data['results'])) {
        echo "[DMZ] No sufficient data for $symbol\n";
        return;
    }

    // --- Calculate volatility ---
    $closes = array_column($data['results'], 'c');
    $returns = [];
    for ($i = 1; $i < count($closes); $i++) {
        $returns[] = log($closes[$i] / $closes[$i - 1]);
    }

    $mean = array_sum($returns) / count($returns);
    $sumSqDiff = 0;
    foreach ($returns as $r) {
        $sumSqDiff += pow($r - $mean, 2);
    }
    $variance = $sumSqDiff / (count($returns) - 1);
    $dailyVolatility = sqrt($variance);
    $annualizedVolatility = $dailyVolatility * sqrt(252);

    // --- Prepare response payload ---
    $responsePayload = [
        'status' => 'success',
        'symbol' => $symbol,
        'from'   => $fromDate,
        'to'     => $toDate,
        'daily_vol' => round($dailyVolatility, 6),
        'annual_vol' => round($annualizedVolatility, 6),
        'timestamp' => date("Y-m-d H:i:s")
    ];

    $msgOut = new AMQPMessage(json_encode($responsePayload), [
        'content_type' => 'application/json',
        'delivery_mode' => 2
    ]);

    // Send back to response queue
    $ch->basic_publish($msgOut, '', 'volatility_response');
    echo "[DMZ] Responded with volatility for {$symbol}\n";
};

$ch->basic_consume('volatility_request', '', false, true, false, false, $callback);

// --- Keep running ---
while ($ch->is_consuming()) {
    $ch->wait();
}
?>
