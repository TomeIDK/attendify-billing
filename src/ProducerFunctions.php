<?php

use PhpAmqpLib\Message\AMQPMessage;

function processRow($userData, $operation, $channel) {
    switch ($operation) {
        case 'CREATE':
        case 'UPDATE':
        case 'DELETE':
            $xmlString = formatUser($userData);
            publishMessage($xmlString, $channel, $operation);
            break;
        default:
            return;
    }
}

function formatUser($userData) {
    $formattedUser = [
        "info" => [
            "sender" => 'billing',
            "operation" => strtolower($userData['operation']),
        ],
        "user" => [
            "first_name" => $userData['first_name'],
            "last_name" => $userData['last_name'],
            "email" => $userData['email'],
            "title" => $userData['title'],
            "password" => $userData['password']
        ]
    ];

    $xml = new SimpleXMLElement("<attendify/>");
    arrayToXml($formattedUser, $xml);

    $dom = new DOMDocument("1.0");
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());

    return $dom->saveXML();
}

function publishMessage($xmlString, $channel, $operation) {
    $msg = new AMQPMessage($xmlString, ['content-type' => 'application/xml']);
    switch ($operation) {
        case 'CREATE':
            $channel->basic_publish($msg, 'user-management', 'user.register');
            break;
        case 'UPDATE':
            $channel->basic_publish($msg, 'user-management', 'user.update');
            break;
        case 'DELETE':
            $channel->basic_publish($msg, 'user-management', 'user.delete');
            break;
    }
}

function arrayToXml(array $data, SimpleXMLElement &$xml) {
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $subnode = $xml->addChild(is_numeric($key) ? "item$key" : $key);
            arrayToXml($value, $subnode);
        } else {
            $xml->addChild(is_numeric($key) ? "item$key" : $key, htmlspecialchars($value));
        }
    }
}
