<?php

namespace Crm\GooglePlayBillingModule;

use Crm\ApiModule\Api\ApiRoutersContainerInterface;
use Crm\ApiModule\Router\ApiIdentifier;
use Crm\ApiModule\Router\ApiRoute;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\ApplicationModule\SeederManager;
use Crm\ApplicationModule\Widget\WidgetManagerInterface;
use Crm\ApplicationModule\User\UserDataRegistrator;
use Crm\GooglePlayBillingModule\Api\VerifyPurchaseApiHandler;
use Crm\UsersModule\Auth\UserTokenAuthorization;
use League\Event\Emitter;
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
        $seederManager->addSeeder($this->getInstance(\Crm\GooglePlayBillingModule\Seeders\SnippetsSeeder::class), 100);
    }

    public function registerEventHandlers(Emitter $emitter)
    {
        $emitter->addListener(
            \Crm\UsersModule\Events\RemovedAccessTokenEvent::class,
            $this->getInstance(\Crm\GooglePlayBillingModule\Events\RemovedAccessTokenEventHandler::class)
        );
        $emitter->addListener(
            \Crm\UsersModule\Events\PairDeviceAccessTokensEvent::class,
            $this->getInstance(\Crm\GooglePlayBillingModule\Events\PairDeviceAccessTokensEventHandler::class)
        );
        $emitter->addListener(
            \Crm\PaymentsModule\Events\PaymentChangeStatusEvent::class,
            $this->getInstance(\Crm\GooglePlayBillingModule\Events\PaymentStatusChangeEventHandler::class)
        );
    }

    public function registerDataProviders(DataProviderManager $dataProviderManager)
    {
        $dataProviderManager->registerDataProvider(
            'users.dataprovider.access_tokens',
            $this->getInstance(\Crm\GooglePlayBillingModule\DataProviders\AccessTokenDataProvider::class)
        );
    }

    public function registerWidgets(WidgetManagerInterface $widgetManager)
    {
        $widgetManager->registerWidget(
            'frontend.payments.listing.recurrent',
            $this->getInstance(\Crm\GooglePlayBillingModule\Components\StopRecurrentPaymentButtonWidget::class),
            100
        );
        $widgetManager->registerWidget(
            'payments.user_payments.listing.recurrent',
            $this->getInstance(\Crm\GooglePlayBillingModule\Components\StopRecurrentPaymentButtonWidget::class),
            100
        );
    }

    public function registerUserData(UserDataRegistrator $dataRegistrator)
    {
        $dataRegistrator->addUserDataProvider($this->getInstance(\Crm\GooglePlayBillingModule\User\GooglePlayUserDataProvider::class));
    }
}
