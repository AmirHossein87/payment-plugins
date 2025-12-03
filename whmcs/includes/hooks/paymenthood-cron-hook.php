<?php
add_hook('AfterCronJob', 1, function ($vars) {
    try {
        // Include your handler
        require_once __DIR__ . '/../addons/paymenthood/paymenthoodHandler.php';

        PaymentHoodHandler::safeLogModuleCall('AfterCronJob', [], [], 'Cron executed');
        // Process unpaid invoices
        PaymenthoodHandler::processUnpaidInvoices();

        // Log execution
    } catch (\Throwable $ex) {
        PaymentHoodHandler::safeLogModuleCall('AfterCronJob Exception', [], [], $ex->getMessage());
    }
});
