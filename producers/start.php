<?php

// > /dev/null 2>&1 &
// ensure processes run in the background (Linux)
echo "Starting heartbeat.php...\n";
exec('php producers/heartbeat.php > /dev/null 2>&1 &');
echo "heartbeat.php has started.\n";

echo "Starting user_producer.php...\n";
exec('php producers/user_producer.php > 2>&1 &');
echo "user_producer.php has started.\n";


// run main service in the foreground
echo "Starting invoice_producer.php...\n";
passthru('php producers/invoice_producer.php');
echo "invoice_producer.php has started.\n";
