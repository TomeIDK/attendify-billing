<?php

// run main service in the foreground
echo "Starting user_producer.php...\n";
passthru('php user_producer.php');
echo "user_producer.php has started.\n";

// > /dev/null 2>&1 & 
// ensure processes run in the background (Linux)
echo "Starting heartbeat.php...\n";
exec('php heartbeat.php > /dev/null 2>&1 &');
echo "heartbeat.php has started.\n";
