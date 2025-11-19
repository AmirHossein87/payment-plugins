<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../../addons/paymenthood/paymenthoodhandler.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

use WHMCS\Database\Capsule;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('PAYMENTHOOD_GATEWAY', 'paymenthood');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Log raw input first
    $rawInput = file_get_contents('php://input');
    PaymentHoodHandler::safeLogModuleCall('catch webhook - POST - raw', ['raw_input' => $rawInput]);

    try {
        $json = json_decode($rawInput, true);

        // Log JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            PaymentHoodHandler::safeLogModuleCall('catch webhook - POST - JSON error', [
                'error' => json_last_error_msg(),
                'raw_input' => $rawInput
            ]);
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
            exit;
        }

        PaymentHoodHandler::safeLogModuleCall('catch webhook - POST', [
            'full_payload' => $json,
            'referenceId' => $json['payment']['referenceId'] ?? 'not found'
        ]);

        $referenceId = $json['payment']['referenceId'] ?? null;
        if (!$referenceId) {
            PaymentHoodHandler::safeLogModuleCall('catch webhook - POST - missing referenceId', ['payload' => $json]);
            http_response_code(400);
            echo json_encode(['error' => 'Missing referenceId', 'received' => $json]);
            exit;
        }

        processPaymenthoodCallback($referenceId, true);

        PaymentHoodHandler::safeLogModuleCall('catch webhook - POST - success', ['referenceId' => $referenceId]);
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Webhook processed']);
        exit;

    } catch (Exception $e) {
        PaymentHoodHandler::safeLogModuleCall('catch webhook - POST - exception', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        http_response_code(500);
        echo json_encode(['error' => 'Internal error: ' . $e->getMessage()]);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $referenceId = $_GET['invoiceid'] ?? null;
    PaymentHoodHandler::safeLogModuleCall('catch webhook - GET', ['referenceId' => $referenceId ?? 'unknown']);
    if (!$referenceId) {
        PaymentHoodHandler::safeLogModuleCall('callback-missing-reference', ['get_params' => $_GET], [], 'Missing invoiceId');
        die('Missing invoiceId');
    }

    processPaymenthoodCallback($referenceId, false);
    PaymentHoodHandler::safeLogModuleCall('catch webhook-finish process', ['status' => $status ?? 'unknown']);

    $redirectBase = PaymentHoodHandler::getSystemUrl();
    PaymentHoodHandler::safeLogModuleCall('catch webhook-redircetUrl', ['redirectBase' => $redirectBase]);

    if ($status === 'success') {
        header("Location: $redirectBase&paymentsuccess=true");
    } elseif ($status === 'failed') {
        header("Location: $redirectBase&paymentfailed=true");
    } else {
        header("Location: $redirectBase&paymentpending=true");
    }
    exit;
}

function processPaymenthoodCallback(string $referenceId, bool $validateAuthorization)
{
    $credentials = PaymentHoodHandler::getGatewayCredentials();
    $appId = $credentials['appId'];
    $token = $credentials['token'];
    $webhookToken = $credentials['webhookToken'];

    if (!$appId || !$token || !$webhookToken) {
        PaymentHoodHandler::safeLogModuleCall('callback-missing-configuration', ['appId' => $appId ?: 'empty', 'tokenConfigured' => !empty($token), 'webhookTokenConfigured' => !empty($webhookToken)], [], 'App ID or Token not configured');
        die('Payment gateway not configured');
    }

    if ($validateAuthorization && !validatePaymenthoodWebhookToken($webhookToken)) {
        http_response_code(401);
        die('Unauthorized');
    }

    $invoiceId = (int) $referenceId;

    // Prepare API call
    $url = PaymentHoodHandler::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/referenceId:$referenceId";

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: text/plain',
            "Authorization: Bearer $token"
        ]
    ]);
    PaymentHoodHandler::safeLogModuleCall('callback-curl', ['url' => $url]);

    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    PaymentHoodHandler::safeLogModuleCall('callback-status-check', [], ['httpCode' => $httpCode, 'error' => $error ?: 'none']);

    if (!$response || $httpCode >= 400) {
        die('Error communicating with payment gateway');
    }

    $data = json_decode($response, true);
    $paymentState = $data['paymentState'] ?? 'unknown';
    $transactionId = $data['paymentId'] ?? 'N/A'; // fallback

    // Save note in invoice using WHMCS API
    PaymentHoodHandler::safeLogModuleCall('callback-update invoice', ['httpCode' => $httpCode]);
    
    // Get existing invoice data using WHMCS API
    $invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    $existing = $invoiceData['notes'] ?? '';

    PaymentHoodHandler::safeLogModuleCall('callback-payment-state', ['paymentState' => $paymentState]);
    if (strpos($existing, 'Payment provider state') === false) {
        try {
            $newNotes = $existing . "\nPayment provider state: $paymentState";
            $command = 'UpdateInvoice';
            $postData = [
                'invoiceid' => $invoiceId,
                'notes' => $newNotes
            ];
            $results = localAPI($command, $postData);
            
            PaymentHoodHandler::safeLogModuleCall('callback-update-invoice-notes', [
                'invoiceId' => $invoiceId,
                'paymentState' => $paymentState,
                'apiResult' => $results
            ]);
        } catch (Exception $e) {
            PaymentHoodHandler::safeLogModuleCall('callback-update-invoice-notes-error', [
                'invoiceId' => $invoiceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    // decide for invoice based on payment provider state
    if ($paymentState === 'Captured') {
        PaymentHoodHandler::safeLogModuleCall('processPaymenthoodCallback - start capture');
        // Payment Success
        try {

            addInvoicePayment($invoiceId, $transactionId, $data['amount'], 0, PAYMENTHOOD_GATEWAY);
        } catch (Exception $e) {
            PaymentHoodHandler::safeLogModuleCall('processPaymenthoodCallback - error', [
                'invoiceId' => $invoiceId,
                'transactionId' => $transactionId,
                'amount' => $data['amount'],
                'error' => $e->getMessage()
            ]);
        }
        PaymentHoodHandler::safeLogModuleCall('processPaymenthoodCallback - callback - captured');

        // it is for browser iteraction
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header("Location: " . PaymentHoodHandler::getSystemUrl() . "viewinvoice.php?id=$invoiceId&paymentsuccess=true");
            exit;
        }

        http_response_code(200);
        echo "OK";

        exit;
    } elseif ($paymentState === 'Failed') {
        PaymentHoodHandler::safeLogModuleCall('callback-cancel-invoice', ['invoiceId' => $invoiceId]);

        // Payment Failed - Use WHMCS API instead of direct DB update
        try {
            $command = 'UpdateInvoice';
            $postData = [
                'invoiceid' => $invoiceId,
                'status' => 'Cancelled',
                'notes' => 'Payment failed via PaymentHood'
            ];
            $results = localAPI($command, $postData);

            PaymentHoodHandler::safeLogModuleCall('callback-cancel-invoice - finished', [
                'invoiceId' => $invoiceId,
                'apiResult' => $results
            ]);
        } catch (Exception $e) {
            PaymentHoodHandler::safeLogModuleCall('callback-cancel-invoice-error', [
                'invoiceId' => $invoiceId,
                'error' => $e->getMessage()
            ]);
        }

        PaymentHoodHandler::safeLogModuleCall('callback', ['data' => $data, 'status' => 'Failed']);

        // it is for browser iteraction
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header("Location: " . PaymentHoodHandler::getSystemUrl() . "viewinvoice.php?id=$invoiceId&paymentfailed=true");
            exit;
        }

        http_response_code(200);
        echo "OK";
        exit;
    } else {
        // Still processing
        PaymentHoodHandler::safeLogModuleCall('callback', ['status' => 'processing', 'paymentState' => $paymentState]);
        // it is for browser iteraction
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header("Location: " . PaymentHoodHandler::getSystemUrl() . "viewinvoice.php?id=$invoiceId&paymentpending=true");
            exit;
        }

        http_response_code(200);
        echo "OK";
        exit;
    }
}

function validatePaymenthoodWebhookToken(string $webhookToken): bool
{
    if (!is_string($webhookToken) || $webhookToken === '') {
        return false; // Token not configured
    }

    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (strpos($authHeader, 'Bearer ') !== 0) {
        return false; // Missing Bearer token
    }

    $incomingToken = substr($authHeader, 7); // Remove "Bearer " prefix

    // Compare securely
    return hash_equals($webhookToken, $incomingToken);
}