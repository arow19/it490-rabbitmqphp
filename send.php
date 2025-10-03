<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// connect to RabbitMQ
$connection = new AMQPStreamConnection('localhost', 5672, 'andrew', 'andrew', 'projectVhost');
$channel = $connection->channel();

$channel->queue_declare('auth_request', false, true, false, false);

$data = [
    "action"   => "register",
    "username" => "testing",
    "password" => "mypassword",
    "email"    => "testing@example.com"
];

// send message
$msg = new AMQPMessage(json_encode($data));
$channel->basic_publish($msg, '', 'auth_request');

echo " [x] Sent reg request!'\n";

$channel->close();
$connection->close();
