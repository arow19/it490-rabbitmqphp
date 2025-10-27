<?php
require_once __DIR__ . '/../StockInfo/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$GMAIL_USER = 'pivotpoint490@gmail.com';
$passFile = fopen("../doNotPushToGit/gmailPassword", "r") or die("Unable to open file!");
$GMAIL_PASS = fread($passFile, filesize("../doNotPushToGit/gmailPassword"));
fclose($passFile);
$GMAIL_NAME = 'PivotPoint';

$conn = new AMQPStreamConnection('10.147.17.197', 5672, 'benji', 'benji', 'projectVhost',false,'AMQPLAIN',null,'en_US',10.0,10.0,null,false,60);
$channel = $conn->channel();
$channel->queue_declare('request_email_notification', false, true, false, false);
$channel->queue_declare('response_email_notification', false, true, false, false);

echo "Waiting for email request...\n";
$callback = function ($msg) use ($GMAIL_USER, $GMAIL_PASS, $GMAIL_NAME, $channel) {
    $data = json_decode($msg->body, true);
    $email    = $data['email'] ?? null;
    $type     = strtoupper($data['type'] ?? 'TRADE');
    $symbol   = $data['symbol'] ?? 'UNKNOWN';
    $quantity = $data['quantity'] ?? 0;
    $price    = $data['price'] ?? 0;
    echo "Received request for {$email} ({$symbol} {$type})\n";
    $subject = "PivotPoint: $type Confirmation - $symbol";
    $body = "
        <h2>Trade Confirmation</h2>
        <p>Your recent <strong>$type</strong> order has been executed.</p>
        <ul>
            <li><strong>Stock:</strong> $symbol</li>
            <li><strong>Quantity:</strong> $quantity</li>
            <li><strong>Price per share:</strong> $$price</li>
        </ul>
        <p>Thank you for trading with PivotPoint.</p>
    ";
    $result = ['status' => 'success', 'email' => $email, 'symbol' => $symbol, 'type' => $type];
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $GMAIL_USER;
        $mail->Password   = $GMAIL_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom($GMAIL_USER, $GMAIL_NAME);
        $mail->addAddress($email);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        echo "Email sent to $email for $symbol ($type)\n";
    } catch (Exception $e) {
        $result = ['status' => 'error', 'error' => $e->getMessage()];
        echo "Failed to send email: {$e->getMessage()}\n";
    }
    $response = new AMQPMessage(json_encode($result));
    $channel->basic_publish($response, '', 'response_email_notification');
    echo "Published response...\n";
};
$channel->basic_consume('request_email_notification', '', false, true, false, false, $callback);
while ($channel->is_consuming()) {
    $channel->wait(null, false, 30);
}
?>