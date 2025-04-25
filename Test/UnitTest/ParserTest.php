<?php

// Update this path to point to the correct location of your parser.php file
require_once __DIR__ . '/../../parser.php';

use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    public function testXmlToJson()
    {
        // Fake XML to test with
        $fakeXml = '<?xml version="1.0"?>
                        <attendify>
                        <info>
                            <sender>billing</sender>
                            <operation>create</operation>
                        </info>
                        <user>
                            <first_name>Cedric</first_name>
                            <last_name>Pas</last_name>
                            <email>test@test.com</email>
                            <title>Mr.</title>
                            <password>test123</password>
                        </user>
                        </attendify>';

        // Expected JSON output (formatted for readability)
        $expectedJson = [
            "attendify" => [
                "info" => [
                    "sender" => "billing",
                    "operation" => "create"
                ],
                "user" => [
                    "first_name" => "Cedric",
                    "last_name" => "Pas",
                    "email" => "test@test.com",
                    "title" => "Mr.",
                    "password" => "test123"
                ]
            ]
        ];
        
        // Convert the expected output to a JSON string with pretty print
        $expectedJsonString = json_encode($expectedJson, JSON_PRETTY_PRINT);

        // Convert XML to JSON
        $actualJson = xmlToJson($fakeXml);
        
        // Print both expected and actual JSON for comparison
        echo "Expected JSON:\n" . $expectedJsonString . "\n\n";
        echo "Actual JSON:\n" . $actualJson . "\n\n";
        
        // Verify the output is valid JSON
        $this->assertJson($actualJson);
        
        // Compare the actual result with expected result (ignoring formatting differences)
        $this->assertEquals(
            json_decode($expectedJsonString, true),
            json_decode($actualJson, true),
            "The converted JSON doesn't match the expected output"
        );
    }
    public function testJsonToXml()
    {
        // Fake JSON to test with
        $fakeJson = '{
            "attendify": {
                "info": {
                    "sender": "billing",
                    "operation": "create"
                },
                "user": {
                    "first_name": "Cedric",
                    "last_name": "Pas",
                    "email": "test@test.com",
                    "title": "Mr.",
                    "password": "test123"
                }
            }
        }';
        $ExpectedXml = '<?xml version="1.0"?>
                            <attendify>
                            <info>
                                <sender>billing</sender>
                                <operation>create</operation>
                            </info>
                            <user>
                                <first_name>Cedric</first_name>
                                <last_name>Pas</last_name>
                                <email>test@test.com</email>
                                <title>Mr.</title>
                                <password>test123</password>
                            </user>
                            </attendify>';


        // Convert JSON to XML
        $ActualXml = JsonToxml($fakeJson);
        
        // Print both expected and actual XML for comparison
        echo "Expected XML:\n" . $ExpectedXml . "\n\n";
        echo "Actual XML:\n" . $ActualXml . "\n\n";
                
        
        // Compare the actual XML content with expected XML content
        $this->assertEquals(
            $ExpectedXml,
            $ActualXml,
            "The converted XML doesn't match the expected output"
        );
    }
}