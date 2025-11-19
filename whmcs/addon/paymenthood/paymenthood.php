<?php
use WHMCS\Database\Capsule;

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/paymenthoodhandler.php';

define('PAYMENTHOOD_GATEWAY', 'paymenthood');

function paymenthood_config()
{
    return [
        'name' => 'paymentHood addons',
        'description' => 'Activate your paymentHood account directly from WHMCS.',
        'version' => '1.0',
        'author' => 'PaymentHood.com',
    ];
}

function paymenthood_activate()
{
    PaymentHoodHandler::safeLogModuleCall('activate module', [], []);
    return ['status' => 'success', 'description' => 'Addon installed successfully and ready for activation.'];
}

function paymenthood_deactivate()
{
    PaymentHoodHandler::safeLogModuleCall('deactivate module', [], []);

    // Use saveGatewaySettingSafe to update the activated setting to '0' instead of deleting
    try {
        saveGatewaySettingSafe(PAYMENTHOOD_GATEWAY, 'activated', '0');
        PaymentHoodHandler::safeLogModuleCall('deactivate module - success', [], []);
    } catch (\Exception $e) {
        PaymentHoodHandler::safeLogModuleCall('deactivate module - error', [], ['error' => $e->getMessage()]);
    }

    return ['status' => 'success', 'description' => 'Addon deactivated successfully.'];
}

function paymenthood_output($vars)
{
    // Build return URL
    PaymentHoodHandler::safeLogModuleCall('output', [], []);

    $exists = Capsule::table('tblpaymentgateways')
        ->where('gateway', PAYMENTHOOD_GATEWAY)
        ->where('setting', 'name')
        ->where('value', 'PaymentHood')
        ->exists();

    if (!$exists) {
        echo '<div class="alert alert-danger">
            ⚠️ PaymentHood gateway not installed.<br>
            Please go to <b>Setup → Payment Gateways</b> and activate PaymentHood.
          </div>';
        echo '</div>';
        return;
    }

    $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}";
    $credentials = PaymentHoodHandler::getGatewayCredentials();
    $appId = $credentials['appId'];
    $paymenthoodUrl = PaymentHoodHandler::paymenthood_grantAuthorizationUrl()
        . '?returnUrl=' . urlencode($currentUrl)
        . '&appId=' . urlencode($appId)
        . '&grantAuthorization=' . urlencode('true');

    // Check if already activated
    $activated = Capsule::table('tblpaymentgateways')
        ->where('gateway', PAYMENTHOOD_GATEWAY)
        ->where('setting', 'activated')
        ->value('value');

    echo '<div class="container" style="margin-top:20px;">';
    echo '<h2>PaymentHood Account Activation</h2>';

    if ($activated == '1') {
        echo '<div class="alert alert-success">Account Activated</div>';
    } else {
        echo '<a class="btn btn-primary" href="' . $paymenthoodUrl . '">Activate</a>';
    }

    echo '</div>';
}

handlePaymentHoodActivationReturn();
function handlePaymentHoodActivationReturn()
{
    if (isset($_GET['appId']) && isset($_GET['authorizationCode'])) {
        handlePaymentHoodActivationReturnImpl($_GET['appId'], $_GET['authorizationCode']);
    }
}

function handlePaymentHoodActivationReturnImpl($appId, $authorizationCode)
{
    // Call paymentHood API
    PaymentHoodHandler::safeLogModuleCall('return activation', [], []);
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
    PaymentHoodHandler::safeLogModuleCall('return activation - result generate bot token', [], $httpCode);

    if ($httpCode == 200 && !empty($response)) {
        $accessToken = trim($response);

        // Save appId + accessToken in WHMCS gateway configuration
        paymenthood_save_credentials($appId, $accessToken);

        // sync webhook info with paymenthood provider
        SyncWebhookTokenByPaymentProvider($appId, $accessToken);

        // Optionally remove GET parameters to avoid repeated activation
        $addonUrl = "addonmodules.php?module=" . PAYMENTHOOD_GATEWAY;
        header("Location: $addonUrl");
        exit;
    }
}

function SyncWebhookTokenByPaymentProvider($appId, $token)
{
    // Fetch current values
    PaymentHoodHandler::safeLogModuleCall('register webhook', [], []);
    $credentials = PaymentHoodHandler::getGatewayCredentials();
    $webhookToken = $credentials['webhookToken'] ?? null;

    if (!$webhookToken) {
        // Create token if missing
        $webhookToken = bin2hex(random_bytes(32));
        saveGatewaySettingSafe(PAYMENTHOOD_GATEWAY, 'webhookToken', $webhookToken);
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
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Log for debugging
    PaymentHoodHandler::safeLogModuleCall('register webhook - finished', [], [
        'status' => $httpCode
    ]);

    return $httpCode >= 200 && $httpCode < 300;
}

function paymenthood_save_credentials($appId, $accessToken)
{
    // Save App ID
    saveGatewaySettingSafe(PAYMENTHOOD_GATEWAY, 'appId', $appId);

    // Save Token (long JWTs supported)
    saveGatewaySettingSafe(PAYMENTHOOD_GATEWAY, 'token', $accessToken);

    // update activated
    saveGatewaySettingSafe(PAYMENTHOOD_GATEWAY, 'activated', '1');

}

function saveGatewaySettingSafe($gateway, $setting, $value)
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
        PaymentHoodHandler::safeLogModuleCall('saveGatewaySettingSafe', compact('gateway', 'setting', 'value'), ['error' => $e->getMessage()]);
    }
}

function paymenthood_clientarea($vars)
{
    PaymentHoodHandler::safeLogModuleCall('paymenthood_clientarea', [], []);

    $action = isset($_GET['action']) ? $_GET['action'] : '';
    PaymentHoodHandler::safeLogModuleCall('paymenthood_clientarea', $action, []);

    if ($action === 'manage-subscription') {
        // Get client id
        $clientId = $_SESSION['uid'];

        // Fetch verified methods from your helper function
        $methods = paymenthood_getVerifiedMethods($clientId);
        PaymentHoodHandler::safeLogModuleCall('paymenthood_clientarea-methods', $methods, []);

        return [
            'pagetitle' => 'Manage Subscription',
            'breadcrumb' => ['index.php?m=paymenthood&action=manage-subscription' => 'Manage Subscription'],
            'templatefile' => 'manage-subscription',
            'requirelogin' => true,
            'vars' => [
                'paymentMethods' => $methods,
            ],
        ];
    }

    if ($action === 'redirect-customer-panel') {
        $clientId = $_SESSION['uid'];
        PaymentHoodHandler::safeLogModuleCall('redirect-customer-panel', [], []);

        // Call API to get paymentHood Customer Panel URL
        $url = paymenthood_getCustomerPanelUrl($clientId);
        PaymentHoodHandler::safeLogModuleCall('redirect-customer-panel', ['url' => $url], []);
        $url = trim($url, "\"'");

        if ($url) {
            header("Location: {$url}");
            exit;
        }

        return [
            'pagetitle' => 'Error',
            'templatefile' => 'error',
            'requirelogin' => true,
            'vars' => [
                'message' => 'Could not fetch paymentHood Customer Panel URL.',
            ],
        ];
    }


    // fallback
    return [];
}

function paymenthood_getVerifiedMethods($clientId)
{
    $methods = [];
    PaymentHoodHandler::safeLogModuleCall('getVerifiedMethods', [], []);

    $credentials = PaymentHoodHandler::getGatewayCredentials();
    $appId = $credentials['appId'];
    $token = $credentials['token'];

    try {
        $url = PaymentHoodHandler::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/customers/$clientId/payment-methods?onlyVerifiedPaymentMethods=true";
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: text/plain',
                "Authorization: Bearer $token"
            ]
        ]);
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $data = json_decode($response, true);
        foreach ($data as $item) {
            if (!empty($item['providerProfiles'])) {
                // Use each provider profile
                foreach ($item['providerProfiles'] as $profile) {
                    $methods[] = [
                        'provider' => $profile['provider'],
                        'paymentMethodNumber' => $item['paymentMethodNumber']
                    ];
                }
            } else {
                // Fallback: show the top-level payment method itself
                $methods[] = [
                    'provider' => $item['paymentMethodType'], // e.g., "CreditCard"
                    'paymentMethodNumber' => $item['paymentMethodNumber']
                ];
            }
        }

    } catch (\Exception $e) {
        PaymentHoodHandler::safeLogModuleCall('getVerifiedMethods - error', ['error' => $e->getMessage()], []);
        throw $e;
    }

    return $methods;
}

function paymenthood_getCustomerPanelUrl($clientId)
{
    PaymentHoodHandler::safeLogModuleCall('getCustomerPanelUrl', ['clientId' => $clientId], []);

    try {
        $credentials = PaymentHoodHandler::getGatewayCredentials();
        $appId = $credentials['appId'];
        $token = $credentials['token'];

        // API URL
        $url = PaymentHoodHandler::paymenthood_getPaymentBaseUrl() .
            "/apps/{$appId}/customers/{$clientId}/panel-link";

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: text/plain',
                "Authorization: Bearer {$token}"
            ],
        ]);

        $response = curl_exec($curl);
        if (curl_errno($curl)) {
            throw new Exception("cURL Error: " . curl_error($curl));
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode !== 200) {
            throw new Exception("paymentHood API returned HTTP $httpCode: " . $response);
        }

        // API should return plain URL string
        return trim($response);

    } catch (\Exception $e) {
        PaymentHoodHandler::safeLogModuleCall('getCustomerPanelUrl - error', ['clientId' => $clientId], ['error' => $e->getMessage()]);
        throw $e; // rethrow so caller knows there was an error
    }
}

add_hook('AfterCronJob', 1, function ($vars) {
    PaymentHoodHandler::safeLogModuleCall('DailyCronJob', [], []);
    //PaymenthoodHandler::processUnpaidInvoices();
});

function addManageSubscriptionMenuItem()
{
    // Only for logged-in users
    if (!isset($_SESSION['uid'])) {
        return;
    }
    PaymentHoodHandler::safeLogModuleCall('addManageSubscriptionMenuItem', [], []);
    // Retrieve the primary navbar object
    $primaryNavbar = Menu::primaryNavbar();

    // Find the Billing menu
    $billingMenu = $primaryNavbar->getChild('Billing');
    if (!$billingMenu) {
        return;
    }

    // Add "Manage Subscription" if it doesn't exist already
    if (!$billingMenu->getChild('Manage Subscription')) {
        $billingMenu->addChild('Manage Subscription', [
            'label' => 'Manage Subscription',
            'uri' => 'index.php?m=paymenthood&action=manage-subscription',
            'order' => 100,
        ]);
    }
}
