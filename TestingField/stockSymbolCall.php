<?php

require_once(__DIR__ . '../../vendor/autoload.php');

$config = Finnhub\Configuration::getDefaultConfiguration()->setApiKey('token', 'd3mio7pr01qmso340tq0d3mio7pr01qmso340tqg');
$client = new Finnhub\Api\DefaultApi(
    new GuzzleHttp\Client(),
    $config
);

print_r($client->stockSymbols("US"));

?>
