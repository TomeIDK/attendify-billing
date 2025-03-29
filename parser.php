<?php

/**
 * Convert XML string to pretty-printed JSON.
 *
 * @param string $xmlString The XML data as a string.
 * @return string The JSON encoded data.
 */
function xmlToJson($xmlString) {
    $xmlObject = simplexml_load_string($xmlString);
    // Wrap the XML object in an array with the root element name as key
    $wrapped = [$xmlObject->getName() => $xmlObject];

    return json_encode($wrapped, JSON_PRETTY_PRINT);
}

/**
 * Recursively converts an array to XML nodes.
 *
 * @param array  $data The data to convert.
 * @param SimpleXMLElement $xml The current XML node.
 */
function arrayToXml(array $data, SimpleXMLElement &$xml) {
    foreach ($data as $key => $value) {
        // if key is numeric, use a generic element name
        if (is_numeric($key)) {
            $key = 'item';
        }
        if (is_array($value)) {
            $subnode = $xml->addChild($key);
            arrayToXml($value, $subnode);
        } else {
            // Convert value to string and add as child
            $xml->addChild($key, htmlspecialchars($value));
        }
    }
}

/**
 * Convert JSON string to formatted XML using the root from JSON.
 *
 * @param string $jsonString The JSON data as a string.
 * @return string The formatted XML string.
 */
function JsonToxml($jsonString) {
    // Decode the JSON string into an associative array
    $data = json_decode($jsonString, true);

    // Extract the root element dynamically
    $rootElement = key($data); // Get the first key of the array (root element)
    $rootData = $data[$rootElement]; // Get the inner data

    // Create the XML structure using the detected root element
    $xml = new SimpleXMLElement("<$rootElement/>");

    // Recursively convert the array to XML nodes
    arrayToXml($rootData, $xml);

    // Convert to a DOMDocument for formatting
    $dom = new DOMDocument("1.0", "UTF-8");
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());

    // Return the formatted XML as a string
    return $dom->saveXML();
}





// Example usage
$xml = '
<attendify>
    <info>
        <sender>Name of service</sender> Example: crm
        <operation>name of operation</operation> Example create,update or delete
    </info>
    <user>
        <first_name>Pieter</first_name>
        <last_name>Doe</last_name>
        <email>test@test.com</email>
        <title>mr</title>
        <password>Hashed password</password>
    </user>
</attendify>
';


$userData = [
    'status' => 'active',
    'group_id' => 1,  // You might need to adjust this based on your FOSSBilling groups
    'email' => 'Bilal.belkasem@gmail.com',  // Updated email format
    'first_name' => 'Bilal',
    'last_name' => 'Belkasem',
    'company' => 'souls inc.',
    'address_1' => 'la nigrillo anarctito',
    'address_2' => 'Bombardino crocodilo',
    'city' => 'Columbus',
    'state' => 'Ohio',
    'country' => 'US',  // Country code
    'postcode' => '170025',
    'phone_cc' => '56',  // Country calling code for Netherlands
    'phone' => '254876156',
    'currency' => 'USD',
    'password' => 'SecurePassword123!',
    'password_confirm' => 'SecurePassword123!'  // Added password_confirm
];


$jsonData = xmlToJson($xml);
echo "JSON output:\n" . $jsonData . "\n\n";

$xmlOutput = JsonToxml($jsonData);
echo "XML output:\n" . $xmlOutput . "\n";

?>
 