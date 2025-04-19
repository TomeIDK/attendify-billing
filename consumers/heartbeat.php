<?php

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
    exit;
}

header('Content-Type: application/json');

echo json_encode(["status" => "success", "message" => "Attendify billing consumer is up"]);