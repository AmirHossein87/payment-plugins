<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!defined("WHMCS"))
    die("This file cannot be accessed directly");

require_once __DIR__ . '/../addons/paymenthood/paymenthoodhandler.php';

define('paymenthood_GATEWAY', 'paymenthood');

function paymenthood_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'PaymentHood',
        ],
        'Description' => [
            'Type' => 'System',
            'Value' => 'Pay invoices securely using PaymentHood.'
        ]
    ];
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