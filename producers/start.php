<?php
echo "Current working directory: " . getcwd() . "\n";
echo "Files in directory:\n";
print_r(scandir(getcwd()));

// > /dev/null 2>&1 & 
// ensure processes run in the background (Linux)
echo "Starting heartbeat.php...\n";
exec('php producers/heartbeat.php > /dev/null 2>&1 &');
echo "heartbeat.php has started.\n";

// run main service in the foreground
echo "Starting user_producer.php...\n";
passthru('php producers/user_producer.php');
echo "user_producer.php has started.\n";
