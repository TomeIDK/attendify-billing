<?php

echo "Starting services...\n";

$services = [
    'user_consumer' => __DIR__ . '/user_consumer.php',
    'heartbeat' => __DIR__ . '/heartbeat.php'
    // You can add more consumers here if needed:
    // 'event_consumer' => __DIR__ . '/event_consumer.php',
];

$children = [];

foreach ($services as $name => $script) {
    $pid = pcntl_fork();

    if ($pid === -1) {
        echo "Failed to fork for $name\n";
        exit(1);
    }

    if ($pid === 0) {
        // Child process
        echo "Starting $name...\n";
        passthru("php {$script}");
        exit(0); // Important: end child process
    } else {
        // Parent process
        $children[$pid] = $name;
    }
}

// Parent waits for child processes
foreach ($children as $pid => $name) {
    pcntl_waitpid($pid, $status);
    echo "Process $name exited with status $status\n";
}
