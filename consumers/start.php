<?php
echo "Current working directory: " . getcwd() . "\n";
echo "Files in directory:\n";
print_r(scandir(getcwd()));

// > /dev/null 2>&1 & 
// ensure processes run in the background (Linux)
echo "Starting heartbeat.php...\n";
exec('php heartbeat.php > /dev/null 2>&1 &');
echo "heartbeat.php has started.\n";

// run main service in the foreground
echo "Starting user_consumer.php...\n";
passthru('php user_consumer.php');
echo "user_consumer.php has started.\n";
