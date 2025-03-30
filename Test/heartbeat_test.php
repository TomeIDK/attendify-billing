<?php
// Super simple heartbeat test script

function checkServiceStatus($service) {
    $status = "down";
    $error = "";
    
    try {
        if ($service['type'] === 'http') {
            // Check HTTP service
            $fp = @fsockopen($service['host'], $service['port'], $errno, $errstr, 3);
            if ($fp) {
                $status = "up";
                fclose($fp);
            } else {
                $error = "HTTP connection failed: $errstr ($errno)";
            }
        } else if ($service['type'] === 'tcp') {
            // Check TCP service (like MySQL)
            $fp = @fsockopen($service['host'], $service['port'], $errno, $errstr, 3);
            if ($fp) {
                $status = "up";
                fclose($fp);
            } else {
                $error = "TCP connection failed: $errstr ($errno)";
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
    
    return [
        'status' => $status,
        'error' => $error
    ];
}

// Test with known working services
$services = [
    [
        'name' => 'Google',
        'host' => 'google.com',
        'port' => 80,
        'type' => 'http'
    ],
    [
        'name' => 'Bing',
        'host' => 'bing.com',
        'port' => 80,
        'type' => 'http'
    ],
    [
        'name' => 'Local test',
        'host' => 'localhost',
        'port' => 80,
        'type' => 'http'
    ]
];

echo "Testing service status...\n";
foreach ($services as $service) {
    $result = checkServiceStatus($service);
    echo "Service: {$service['name']}\n";
    echo "Status: {$result['status']}\n";
    if ($result['error']) {
        echo "Error: {$result['error']}\n";
    }
    echo "-------------------\n";
}

echo "\nHeartbeat test completed successfully!\n"; 