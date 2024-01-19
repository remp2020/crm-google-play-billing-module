<?php

namespace Crm\GooglePlayBillingModule\Models;

use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Google\Client;
use Google\Service\AndroidPublisher;
use ReceiptValidator\GooglePlay\Validator;

class GooglePlayValidatorFactory
{
    const SUBSCRIPTION_PAYMENT_STATE_PENDING = 0;
    const SUBSCRIPTION_PAYMENT_STATE_CONFIRMED = 1;
    const SUBSCRIPTION_PAYMENT_STATE_FREE_TRIAL = 2;

    private $applicationConfig;

    public function __construct(ApplicationConfig $applicationConfig)
    {
        $this->applicationConfig = $applicationConfig;
    }

    public function create(): Validator
    {
        $applicationName = $this->applicationConfig->get('site_title');

        $credentialsFile = $this->applicationConfig->get('google_play_billing_service_account_credentials_json');
        if (!$credentialsFile) {
            throw new \Exception('Missing application configuration [google_play_billing_service_account_credentials_json].');
        }

        $client = new Client();
        $client->setApplicationName($applicationName);
        $client->setAuthConfig($credentialsFile);
        $client->addScope(AndroidPublisher::ANDROIDPUBLISHER);

        return new Validator(new AndroidPublisher($client));
    }
}
