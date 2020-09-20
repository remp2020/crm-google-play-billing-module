<?php

namespace Crm\GooglePlayBillingModule;

use Crm\ApiModule\Api\ApiRoutersContainerInterface;
use Crm\ApiModule\Router\ApiIdentifier;
use Crm\ApiModule\Router\ApiRoute;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\SeederManager;
use Crm\GooglePlayBillingModule\Api\VerifyPurchaseApiHandler;
use Crm\UsersModule\Auth\UserTokenAuthorization;
use Tomaj\Hermes\Dispatcher;

class GooglePlayBillingModule extends CrmModule
{
    const META_KEY_PURCHASE_TOKEN = 'google_play_billing_purchase_token';
    const META_KEY_ORDER_ID = 'google_play_billing_order_id';
    const META_KEY_DEVELOPER_NOTIFICATION_ID = 'google_play_billing_developer_notification_id';

    public const USER_SOURCE_APP = 'android-app';

    public function registerApiCalls(ApiRoutersContainerInterface $apiRoutersContainer)
    {
        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'google-play-billing', 'webhook'),
                \Crm\GooglePlayBillingModule\Api\DeveloperNotificationPushWebhookApiHandler::class,
                \Crm\ApiModule\Authorization\NoAuthorization::class
            )
        );

        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'google-play-billing', 'verify-purchase'),
                VerifyPurchaseApiHandler::class,
                UserTokenAuthorization::class
            )
        );
    }

    public function registerHermesHandlers(Dispatcher $dispatcher)
    {
        $dispatcher->registerHandler(
            'developer-notification-received',
            $this->getInstance(\Crm\GooglePlayBillingModule\Hermes\DeveloperNotificationReceivedHandler::class)
        );
    }

    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(\Crm\GooglePlayBillingModule\Seeders\ConfigsSeeder::class));
        $seederManager->addSeeder($this->getInstance(\Crm\GooglePlayBillingModule\Seeders\PaymentGatewaysSeeder::class));
    }
}
