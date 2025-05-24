<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../parser.php';
require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/helper.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeload();

function createInvoice($client_id, $companyDetails, $pdo, $channel) {
    $internalId = getClientInternalId($client_id, $pdo, $channel);
    $serie = 'INV' . date("Y");
    $nr = getNextInvoiceNumber($serie, $pdo);
    $currentTime = date('Y-m-d H:i:s');
    $insertInvoiceSql = "INSERT INTO invoice (
                            client_id, hash, serie, nr, seller_company, seller_company_vat, seller_company_number, seller_address, seller_phone, seller_email,
                            buyer_company, buyer_company_vat, buyer_company_number, buyer_address, buyer_city, buyer_country, buyer_zip, buyer_phone, buyer_email,
                            approved, due_at, created_at, updated_at
                        ) VALUES (
                            :client_id, :hash, :serie, :nr, :seller_company, :seller_company_vat, :seller_company_number, :seller_address, :seller_phone, :seller_email, 
                            :buyer_company, :buyer_company_vat, :buyer_company_number, :buyer_address, :buyer_city, :buyer_country, :buyer_zip, :buyer_phone, :buyer_email,
                            :approved, :due_at, :created_at, :updated_at)";
    $stmtInvoice = $pdo->prepare($insertInvoiceSql);
    try {
        $stmtInvoice->execute([
            ':client_id' => $internalId,
            ':hash' => bin2hex(random_bytes(16)),
            ':serie' => $serie,
            ':nr' => $nr,
            ':seller_company' => "Attendify",
            ':seller_company_vat' => "BE 0897.456.321",
            ':seller_company_number' => "0897.456.321",
            ':seller_address' => "Nijverheidskaai 170, 1070 Anderlecht, Belgium",
            ':seller_phone' => "04 41 34 27 78",
            ':seller_email' => "contact@attendify.com",
        
            ':buyer_company' => $companyDetails['name'],
            ':buyer_company_vat' => $companyDetails['VATNumber'],
            ':buyer_company_number' => $companyDetails['companyNumber'],
            ':buyer_address' => $companyDetails['billing_address_street'] . " " . $companyDetails['billing_address_number'],
            ':buyer_city' => $companyDetails['billing_address_city'],
            ':buyer_country' => "Belgium",
            ':buyer_zip' => $companyDetails['billing_address_postcode'],
            ':buyer_phone' => $companyDetails['phone'],
            ':buyer_email' => $companyDetails['email'],
        
            ':approved' => 1,
            ':due_at' => date('Y-m-d H:i:s', strtotime($currentTime . ' +14 days')),
            ':created_at' => $currentTime,
            ':updated_at' => $currentTime
        ]);
        $invoiceId = $pdo->lastInsertId(); 
        echo " [✔] Created new invoice #{$invoiceId}\n";
        $stmtInvoice->closeCursor();
        return $invoiceId;
    } catch (PDOException $e) {
        echo " [!] Database failed to create invoice: " . $e->getMessage() . "\n";
        return null;
    }
}

function getNextInvoiceNumber($serie, $pdo) {
    $sql = "SELECT MAX(CAST(nr AS UNSIGNED)) AS max_nr FROM invoice WHERE serie = :serie";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':serie' => $serie]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    
    $maxNr = $row['max_nr'] ?? 0;
    
    return (int)$maxNr + 1;    
}

function generateInvoiceId($company_id, $event_id, $pdo, $channel) {
    $owner_id = getCompanyOwnerId($company_id, $pdo, $channel); 
    if ($owner_id === null) { 
        echo " [!] Could not generate invoice ID: Company owner not found for company_id {$company_id}\n";
        return null; 
    }

    $companyDetails = getCompany(['company_id' => $company_id], $pdo, $channel);
    if ($companyDetails === null) {
        echo " [!] Could not generate invoice ID: Company details not found for company_id {$company_id}\n";
        return null;
    }

    // Create new invoice
    $invoiceId = createInvoice($owner_id, $companyDetails, $pdo, $channel);
    if ($invoiceId === null) {
        return null;
    }

    // Link invoice to company and event
    $insertCompanyInvoiceSql = "INSERT INTO company_invoice (company_id, event_id, invoice_id) VALUES (:company_id, :event_id, :invoice_id)";
    $stmtCompanyInvoice = $pdo->prepare($insertCompanyInvoiceSql);
    try {
        $stmtCompanyInvoice->execute([
            ':company_id' => $company_id,
            ':event_id' => $event_id,
            ':invoice_id' => $invoiceId 
        ]);
        $stmtCompanyInvoice->closeCursor();
        echo " [✔] Linked invoice #{$invoiceId} to company {$company_id} and event {$event_id}\n";
        return $invoiceId; 
    } catch (PDOException $e) {
        echo " [!] Database failed to link invoice to company/event: " . $e->getMessage() . "\n";
        $stmtCompanyInvoice->closeCursor();
        return null;
    }
}