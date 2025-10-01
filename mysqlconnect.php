#!/usr/bin/php
<?php

$mydb = new mysqli('127.0.0.1','testUser','12345','testdb');
  131  sudo apt istall ssh-s
  132  sudo apt istall openssh-server
  133  sudo apt install openssh-server
  134  sudo apt install openssh-client

if ($mydb->errno != 0)
{
	echo "failed to connect to database: ". $mydb->error . PHP_EOL;
	exit(0);
}

echo "successfully connected to database".PHP_EOL;

$query = "select * from students;";

$response = $mydb->query($query);
if ($mydb->errno != 0)
{
	echo "failed to execute query:".PHP_EOL;
	echo __FILE__.':'.__LINE__.":error: ".$mydb->error.PHP_EOL;
	exit(0);
}


?>
