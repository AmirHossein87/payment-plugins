<?php
use WHMCS\Database\Capsule;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined("WHMCS"))
    die("This file cannot be accessed directly");

require_once __DIR__ . '/../addons/paymenthood/paymenthoodhandler.php';

define('paymenthood_GATEWAY', 'paymenthood');

// Handle activation return before any output
paymenthood_handleActivationReturn();

function paymenthood_config()
{
    // Get current activation status
    $activated = Capsule::table('tblpaymentgateways')
        ->where('gateway', 'paymenthood')
        ->where('setting', 'activated')
        ->value('value');

    // Build activation link
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'PaymentHood',
        ],
        'Description' => [
            'Type' => 'System',
            'Value' => 'Pay invoices securely using PaymentHood.'
        ],
        'activation' => [
            'FriendlyName' => 'Activation',
            'Type' => 'system',
            'Description' => paymenthood_getActivationLink($activated),
        ],
    ];
}

function paymenthood_getActivationLink($activated)
{
    // Build return URL
    $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
        . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";

    $credentials = PaymentHoodHandler::getGatewayCredentials();
    $appId = $credentials['appId'];

    $paymenthoodUrl = PaymentHoodHandler::paymenthood_grantAuthorizationUrl()
        . '?returnUrl=' . urlencode($currentUrl)
        . '&appId=' . urlencode($appId)
        . '&grantAuthorization=' . urlencode('true');

    PaymentHoodHandler::safeLogModuleCall('getActivationLink', ['appId' => $appId, 'activated' => $activated], ['url' => $paymenthoodUrl]);

    if ($activated == '1') {
        return '<span style="color:#28a745;font-weight:bold;">✓ Account is activated</span>';
    }

    return '<a href="' . htmlspecialchars($paymenthoodUrl) . '" 
                style="padding:8px 16px;background:#007bff;color:white;border-radius:4px;text-decoration:none;display:inline-block;">
                Activate PaymentHood
            </a>';
}

function paymenthood_handleActivationReturn()
{
    if (isset($_GET['appId']) && isset($_GET['authorizationCode'])) {
        $appId = $_GET['appId'];
        $authorizationCode = $_GET['authorizationCode'];

        PaymentHoodHandler::safeLogModuleCall('gateway activation return', ['appId' => $appId], []);

        // Call paymentHood API to generate bot token
        $baseUrl = PaymentHoodHandler::paymenthood_getPaymentAppBaseUrl();
        $url = $baseUrl . "/apps/" . urlencode($appId) . "/generate-bot-token";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $authorizationCode,
            "Content-Type: application/json"
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        PaymentHoodHandler::safeLogModuleCall('gateway activation - generate bot token', ['httpCode' => $httpCode], []);

        if ($httpCode == 200 && !empty($response)) {
            $accessToken = trim($response);

            // Save appId + accessToken in WHMCS gateway configuration
            paymenthood_saveCredentials($appId, $accessToken);

            // Sync webhook info with paymenthood provider
            paymenthood_syncWebhookToken($appId, $accessToken);

            // Redirect to clean URL (remove query parameters)
            $cleanUrl = strtok($_SERVER['REQUEST_URI'], '?');
            header("Location: " . $cleanUrl);
            exit;
        } else {
            PaymentHoodHandler::safeLogModuleCall('gateway activation - failed', ['httpCode' => $httpCode, 'response' => $response], []);
        }
    }
}

function paymenthood_saveCredentials($appId, $accessToken)
{
    try {
        // Save App ID
        paymenthood_saveGatewaySetting('paymenthood', 'appId', $appId);

        // Save Token
        paymenthood_saveGatewaySetting('paymenthood', 'token', $accessToken);

        // Mark as activated
        paymenthood_saveGatewaySetting('paymenthood', 'activated', '1');

        PaymentHoodHandler::safeLogModuleCall('gateway save credentials - success', ['appId' => $appId], []);
    } catch (\Exception $e) {
        PaymentHoodHandler::safeLogModuleCall('gateway save credentials - error', ['appId' => $appId], ['error' => $e->getMessage()]);
    }
}

function paymenthood_syncWebhookToken($appId, $token)
{
    PaymentHoodHandler::safeLogModuleCall('gateway register webhook', ['appId' => $appId], []);

    // Get or create webhook token
    $credentials = PaymentHoodHandler::getGatewayCredentials();
    $webhookToken = $credentials['webhookToken'] ?? null;

    if (!$webhookToken) {
        // Create token if missing
        $webhookToken = bin2hex(random_bytes(32));
        paymenthood_saveGatewaySetting('paymenthood', 'webhookToken', $webhookToken);
    }

    $payload = [
        "webhookAuthorizationHeaderScheme" => ["value" => "Bearer"],
        "webhookAuthorizationHeaderParameter" => ["value" => $webhookToken],
    ];

    $headers = [
        "Authorization: Bearer {$token}",
        "Content-Type: application/json",
        "Accept: text/plain"
    ];

    $baseUrl = PaymentHoodHandler::paymenthood_getPaymentAppBaseUrl();
    $url = $baseUrl . "/apps/{$appId}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    PaymentHoodHandler::safeLogModuleCall('gateway register webhook - finished', ['appId' => $appId], ['status' => $httpCode]);

    return $httpCode >= 200 && $httpCode < 300;
}

function paymenthood_saveGatewaySetting($gateway, $setting, $value)
{
    try {
        Capsule::connection()->transaction(function () use ($gateway, $setting, $value) {
            $rows = Capsule::table('tblpaymentgateways')
                ->where('gateway', $gateway)
                ->whereRaw("TRIM(LOWER(setting)) = ?", [strtolower($setting)])
                ->get();

            $keepId = null;
            foreach ($rows as $row) {
                if ($keepId === null) {
                    $keepId = $row->id;
                } else {
                    Capsule::table('tblpaymentgateways')->where('id', $row->id)->delete();
                }
            }

            if ($keepId !== null) {
                Capsule::table('tblpaymentgateways')->where('id', $keepId)->update(['value' => $value]);
            } else {
                Capsule::table('tblpaymentgateways')->insert([
                    'gateway' => $gateway,
                    'setting' => $setting,
                    'value' => $value,
                ]);
            }
        });
    } catch (\Throwable $e) {
        PaymentHoodHandler::safeLogModuleCall('gateway saveGatewaySetting', compact('gateway', 'setting', 'value'), ['error' => $e->getMessage()]);
    }
}

function paymenthood_link($params)
{
    try {
        PaymentHoodHandler::safeLogModuleCall('link called', []);
        return paymenthoodHandler::handleInvoice($params);
    } catch (\Throwable $ex) {
        PaymentHoodHandler::safeLogModuleCall('link called - error', ['error' => $ex->getMessage()]);
        // Stay on the same invoice page and show message
        return '<div class="alert alert-danger">Error: ' . htmlspecialchars($ex->getMessage()) . '</div>';
    }
}