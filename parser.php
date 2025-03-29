<?php

class AttendifyXMLParser
{
    public function parseMessage($xmlString)
    {
        try {
            // Remove any whitespace between tags
            $xmlString = preg_replace('/>\s+</', '><', trim($xmlString));
            $xml = new SimpleXMLElement($xmlString);
            
            // Create the structured JSON format
            $result = [
                'info' => [
                    'sender' => (string)$xml->info->sender,
                    'operation' => (string)$xml->info->operation
                ],
                'user' => [
                    'id' => (string)$xml->user->id,
                    'first_name' => (string)$xml->user->first_name,
                    'last_name' => (string)$xml->user->last_name,
                    'date_of_birth' => (string)$xml->user->date_of_birth,
                    'phone_number' => trim((string)$xml->user->phone_number),
                    'title' => trim((string)$xml->user->title),
                    'email' => (string)$xml->user->email,
                    'password' => (string)$xml->user->password,
                    'address' => [
                        'street' => (string)$xml->user->address->street,
                        'number' => (string)$xml->user->address->number,
                        'bus_number' => (string)$xml->user->address->bus_number,
                        'city' => (string)$xml->user->address->city,
                        'province' => (string)$xml->user->address->province,
                        'country' => (string)$xml->user->address->country,
                        'postal_code' => (string)$xml->user->address->postal_code
                    ],
                    'payment_details' => [
                        'facturation_address' => [
                            'street' => (string)$xml->user->payment_details->facturation_address->street,
                            'number' => (string)$xml->user->payment_details->facturation_address->number,
                            'company_bus_number' => (string)$xml->user->payment_details->facturation_address->company_bus_number,
                            'city' => (string)$xml->user->payment_details->facturation_address->city,
                            'province' => (string)$xml->user->payment_details->facturation_address->province,
                            'country' => (string)$xml->user->payment_details->facturation_address->country,
                            'postal_code' => (string)$xml->user->payment_details->facturation_address->postal_code
                        ],
                        'payment_method' => (string)$xml->user->payment_details->payment_method,
                        'card_number' => (string)$xml->user->payment_details->card_number
                    ],
                    'email_registered' => $this->parseBoolean((string)$xml->user->email_registered),
                    'company' => [
                        'id' => trim((string)$xml->user->company->id),
                        'name' => (string)$xml->user->company->name,
                        'VAT_number' => (string)$xml->user->company->VAT_number,
                        'address' => [
                            'street' => (string)$xml->user->company->address->street,
                            'number' => (string)$xml->user->company->number,
                            'city' => (string)$xml->user->company->address->city,
                            'province' => (string)$xml->user->company->address->province,
                            'country' => (string)$xml->user->company->address->country,
                            'postal_code' => (string)$xml->user->company->address->postal_code
                        ]
                    ],
                    'from_company' => $this->parseBoolean((string)$xml->user->from_company)
                ]
            ];
            
            return json_encode($result, JSON_PRETTY_PRINT);
            
        } catch (Exception $e) {
            throw new Exception("Error parsing Attendify XML: " . $e->getMessage());
        }
    }
    
    private function parseBoolean($value)
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

// Example usage with RabbitMQ
$parser = new AttendifyXMLParser();

// When receiving message from RabbitMQ
$callback = function ($msg) use ($parser) {
    try {
        $jsonData = $parser->parseMessage($msg->body);
        // Now you can use $jsonData with FOSSBilling
        // Store in database or process further
        
    } catch (Exception $e) {
        // Handle error
        echo "Error: " . $e->getMessage();
    }
};
