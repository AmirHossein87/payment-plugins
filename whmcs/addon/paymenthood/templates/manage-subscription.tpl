<h2 class="mb-4">Manage Subscription</h2>

{if $paymentMethods|@count > 0}
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <span>Your Verified Payment Methods</span>
            <a href="index.php?m=paymenthood&action=redirect-customer-panel" 
               class="btn btn-light btn-sm">
                <i class="fas fa-cog"></i> Manage Subscription
            </a>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover table-striped mb-0">
                <thead class="thead-light">
                    <tr>
                        <th>Provider</th>
                        <th>Card Number</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $paymentMethods as $method}
                        <tr>
                            <td><i class="fas fa-credit-card text-muted"></i> {$method.provider}</td>
                            <td>{$method.paymentMethodNumber}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    </div>
{else}
    <div class="alert alert-info d-flex justify-content-between align-items-center">
        <span>You do not have any payment methods yet.</span>
        <a href="index.php?m=paymenthood&action=redirect-customer-panel" class="btn btn-primary btn-sm">
            <i class="fas fa-plus-circle"></i> Add Payment Method
        </a>
    </div>
{/if}
