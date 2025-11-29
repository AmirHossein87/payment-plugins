<?php
use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

if (defined('WHMCS_MAIL') && WHMCS_MAIL) {
    // Skip API call — email generation context
    return '<a href="#">Pay with paymentHood</a>';
}

class PaymentHoodHandler
{
    const PAYMENTHOOD_GATEWAY = 'paymenthood';

    /**
     * Safe wrapper for logging that works across WHMCS contexts
     * @param string $action Action being performed
     * @param array|string $request Request data or simple string data
     * @param array $response Response data
     * @param string $trace Optional trace information
     */
    public static function safeLogModuleCall($action, $request = [], $response = [], $trace = null)
    {
        // Convert string request to array for consistency
        if (is_string($request)) {
            $request = ['data' => $request];
        }

        // In some WHMCS contexts (like addons), logModuleCall might not be available
        if (function_exists('logModuleCall')) {
            if ($trace !== null) {
                return logModuleCall(self::PAYMENTHOOD_GATEWAY, $action, $request, $response, $trace);
            }
            return logModuleCall(self::PAYMENTHOOD_GATEWAY, $action, $request, $response);
        }

        // Fallback logging for contexts where logModuleCall isn't available
        $logData = [
            'module' => self::PAYMENTHOOD_GATEWAY,
            'action' => $action,
            'request' => $request,
            'response' => $response,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        if ($trace !== null) {
            $logData['trace'] = $trace;
        }
        error_log('PaymentHood Module: ' . json_encode($logData));
    }

    public static function handleInvoice(array $params)
    {
        try {
            $invoiceId = (string) $params['invoiceid'];
            $credentials = self::getGatewayCredentials();
            $appId = $credentials['appId'];
            $token = $credentials['token'];

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['paymentmethod'] ?? '') === self::PAYMENTHOOD_GATEWAY) {
                self::safeLogModuleCall('call payment api - start', ['appId' => $appId]);

                $amount = $params['amount'];
                $currency = $params['currency'];
                $clientEmail = $params['clientdetails']['email'] ?? '';

                $callbackUrl = self::getSystemUrl() . 'modules/gateways/callback/paymenthood.php?invoiceid=' . $invoiceId;

                $hasSubscription = Capsule::table('tblinvoiceitems as ii')
                    ->join('tblhosting as h', 'h.id', '=', 'ii.relid')
                    ->where('ii.invoiceid', $invoiceId)
                    ->whereIn('ii.type', ['Hosting', 'Product', 'Product/Service'])
                    ->whereNotIn('h.billingcycle', ['Free', 'Free Account', 'One Time'])
                    ->exists();
                //$checkoutMethods = $hasSubscription ? ["VerifiedPaymentMethod"] : null;

                $postData = [
                    "referenceId" => $invoiceId,
                    "amount" => $amount,
                    "currency" => $currency,
                    "autoCapture" => true,
                    "webhookUrl" => $callbackUrl,
                    "showAvailablePaymentMethodsInCheckout" => $hasSubscription,
                    "customerOrder" => [
                        "customer" => [
                            "customerId" => (string) ($params['clientdetails']['userid'] ?? ''),
                            "email" => $clientEmail,
                        ]
                    ],
                    "returnUrl" => $callbackUrl
                ];
                // if (!is_null($checkoutMethods)) {
                //     $postData['checkoutMethods'] = $checkoutMethods;
                // }

                $response = self::callApi(self::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/hosted-page", $postData, $token);
                self::safeLogModuleCall('result of create payment', $response);

                // handle duplicate reference
                if (isset($response['Message']) && strpos($response['Message'], 'ProviderReferenceId already used') !== false) {
                    self::safeLogModuleCall('Duplicate Payment', $response);
                    self::cancelInvoice($invoiceId);
                    return '<p>An error occurred: duplicate payment reference. Please contact support or try again.</p>';
                }

                if (empty($response['redirectUrl'])) {
                    return "<p>Payment gateway returned invalid response.</p>";
                }

                self::safeLogModuleCall('redirect to hosted payment', ['url' => $response['redirectUrl']]);
                header("Location: " . $response['redirectUrl']);
                exit;
            } else {
                // GET request or render page logic
                self::safeLogModuleCall('render page', []);
                self::checkInvoiceStatus($invoiceId, $appId, $token);
                return ''; // hide complete order button
            }
        } catch (\Throwable $ex) {
            self::safeLogModuleCall('handler_exception', $params, $ex->getMessage(), $ex->getTraceAsString());
            return '<div class="alert alert-danger">paymentHood Error: '
                . htmlspecialchars($ex->getMessage())
                . '</div>';
        }
    }

    public static function processUnpaidInvoices()
    {
        self::safeLogModuleCall('start processUnpaidInvoices', [], []);
        try {
            // Get  invoices due today that use paymentHood as gateway
            $today = date('Y-m-d');

            $invoices = Capsule::table('tblinvoices as i')
                ->join('tblinvoiceitems as ii', 'ii.invoiceid', '=', 'i.id')
                ->join('tblhosting as h', 'ii.relid', '=', 'h.id')
                ->where('i.status', 'Unpaid')
                ->where('ii.type', 'Hosting')
                ->whereNotIn('h.billingcycle', ['One Time', 'Free'])
                ->whereDate('i.duedate', '<=', $today)
                ->select('i.id as invoiceId', 'i.userid', 'i.total')
                ->distinct()
                ->get();

            foreach ($invoices as $invoice) {
                $invoiceId = $invoice->invoiceId;
                $clientId = $invoice->userid;

                self::safeLogModuleCall(
                    'processUnpaidInvoices',
                    ['invoiceId' => $invoiceId, 'clientId' => $clientId]
                );

                try {
                    // Call paymentHood Auto-Payment API
                    $result = self::createAutoPayment($clientId, $invoiceId, $invoice->total);

                    // apply payment in WHMCS
                    if ($result['status'] === 'success') {
                        addInvoicePayment(
                            $invoiceId,
                            $result['transactionId'] ?? uniqid('paymenthood_'),
                            $invoice->total,
                            0,
                            'paymenthood'
                        );
                    }

                } catch (\Exception $ex) {
                    throw new \Exception(
                        "Error processing auto-payment for Invoice #{$invoiceId}: " . $ex->getMessage(),
                        $ex->getCode(),
                        previous: $ex
                    );
                }
            }
        } catch (\Exception $ex) {
            self::safeLogModuleCall(
                'processUnpaidInvoices - Error',
                [],
                ['error' => $ex->getMessage()]
            );
        }
    }

    private static function createAutoPayment(string $clientId, string $invoiceId, string $amount)
    {
        try {
            $credentials = self::getGatewayCredentials();
            $appId = $credentials['appId'];
            $token = $credentials['token'];

            self::safeLogModuleCall('createAutoPayment', ['appId' => $appId]);

            $postData = [
                "referenceId" => $invoiceId,
                "amount" => $amount,
                "autoCapture" => true,
                "customerOrder" => [
                    "customer" => [
                        "customerId" => $clientId,
                    ]
                ]
            ];

            $response = self::callApi(self::paymenthood_getPaymentBaseUrl() . "/apps/{$appId}/payments/auto-payment", $postData, $token);
            self::safeLogModuleCall('createAutoPayment - result', $response);

            // handle duplicate reference
            if (isset($response['Message']) && strpos($response['Message'], 'ProviderReferenceId already used') !== false) {
                self::safeLogModuleCall('Duplicate Payment', $response);
                self::cancelInvoice($invoiceId);
                return '<p>An error occurred: duplicate payment reference. Please contact support or try again.</p>';
            }

            return [
                'status' => 'success',
                'paymentId' => $response['paymentId']
            ];
        } catch (\Throwable $ex) {
            self::safeLogModuleCall('handler_exception', ['invoiceId' => $invoiceId], ['error' => $ex->getMessage()], $ex->getTraceAsString());
            return [
                'status' => 'error',
                'rawdata' => $ex->getMessage(),
            ];
        }
    }

    private static function getWebhookUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host . '/modules/gateways/callback/paymenthood.php';
    }

    private static function callApi(string $url, array $data, string $token, string $method = 'POST'): array
    {
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer $token",
                "Content-Type: application/json"
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$response || $httpCode >= 400) {
                self::safeLogModuleCall('API error', ['response' => $response], ['httpCode' => $httpCode]);
                throw new \Exception($response);
            }

            return json_decode($response, true) ?? [];
        } catch (\Exception $ex) {
            self::safeLogModuleCall('app call error', $httpCode, [], $ex->getMessage());
            throw $ex;
        }
    }

    private static function cancelInvoice(string $invoiceId)
    {
        try {
            $command = 'UpdateInvoice';
            $postData = [
                'invoiceid' => $invoiceId,
                'status' => 'Cancelled',
            ];
            $results = localAPI($command, $postData);

            if ($results['result'] !== 'success') {
                throw new \Exception('Failed to cancel invoice: ' . ($results['message'] ?? 'Unknown error'));
            }

            self::safeLogModuleCall('invoice cancelled', ['invoiceId' => $invoiceId]);
        } catch (\Exception $ex) {
            self::safeLogModuleCall('invoice cancel error', ['invoiceId' => $invoiceId], ['error' => $ex->getMessage()]);
            throw $ex;
        }
    }

    private static function checkInvoiceStatus(string $invoiceId, string $appId, string $token)
    {
        $status = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        self::safeLogModuleCall('checkInvoiceStatus', ['invoiceId' => $invoiceId], ['status' => $status]);
        if ($status !== 'Unpaid') {
            return;
        }

        $url = self::getPaymentBaseUrl() . "/v1/apps/{$appId}/payments/referenceId:$invoiceId";
        $response = self::callApi($url, [], $token, 'GET');

        if (!$response) {
            self::cancelInvoice($invoiceId);
        }
    }

    public static function getGatewayCredentials()
    {
        $rows = Capsule::table('tblpaymentgateways')
            ->where('gateway', 'paymenthood')
            ->whereIn('setting', ['appId', 'token', 'webhookToken'])
            ->get()
            ->keyBy('setting');

        $appId = isset($rows['appId']) ? $rows['appId']->value : null;
        $token = isset($rows['token']) ? $rows['token']->value : null;
        $webhookToken = isset($rows['webhookToken']) ? $rows['webhookToken']->value : null;

        return ['appId' => $appId, 'token' => $token, 'webhookToken' => $webhookToken];
    }

    public static function getSystemUrl()
    {
        $systemUrl = Capsule::table('tblconfiguration')
            ->where('setting', 'SystemURL')
            ->value('value');

        if ($systemUrl) {
            // Ensure it ends with a slash
            return rtrim($systemUrl, '/') . '/';
        }

        return null;
    }

    public static function paymenthood_getPaymentAppBaseUrl(): string
    {
        return rtrim('https://appapi.paymenthood.com/api/', '/');
    }

    public static function paymenthood_grantAuthorizationUrl(): string
    {
        return rtrim('https://console.paymenthood.com/auth/signin', '/');
    }

    public static function paymenthood_getPaymentBaseUrl(): string
    {
        return rtrim('https://api.paymenthood.com/api/v1', '/');
    }
}
