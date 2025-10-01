#!/usr/bin/php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;


$connection = new AMQPStreamConnection('10.147.17.197', 5672, 'rey', 'rey', 'projectVhost');
$channel = $connection->channel();

$channel->queue_declare('auth_request', false, true, false, false);

$data = [
   "action"   => "register",
   "username" => "testuser",
   "password" => "mypassword",
   "email"    => "test@example.com"
];


// send message
$msg = new AMQPMessage(json_encode($data));
$channel->basic_publish($msg, '', 'auth_request');


echo " [x] Sent reg request!'\n";


$channel->close();
$connection->close();
?>
