<?php

namespace Crm\GooglePlayBillingModule\Gateways;

use Crm\PaymentsModule\Gateways\GatewayAbstract;

class GooglePlayBilling extends GatewayAbstract
{
    const GATEWAY_CODE = 'google_play_billing';
    const GATEWAY_NAME = 'Google Play Billing';

    public function isSuccessful(): bool
    {
        return true;
    }

    public function process($allowRedirect = true)
    {
    }

    protected function initialize()
    {
    }

    public function begin($payment)
    {
        exit();
    }

    public function complete($payment): ?bool
    {
        return true;
    }
}
