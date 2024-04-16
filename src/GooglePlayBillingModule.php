<?php

namespace Crm\GooglePlayBillingModule;

use Crm\ApiModule\Models\Api\ApiRoutersContainerInterface;
use Crm\ApiModule\Models\Authorization\NoAuthorization;
use Crm\ApiModule\Models\Router\ApiIdentifier;
use Crm\ApiModule\Models\Router\ApiRoute;
use Crm\ApplicationModule\Application\CommandsContainerInterface;
use Crm\ApplicationModule\Application\Managers\SeederManager;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\Models\DataProvider\DataProviderManager;
use Crm\ApplicationModule\Models\Event\LazyEventEmitter;
use Crm\ApplicationModule\Models\User\UserDataRegistrator;
use Crm\ApplicationModule\Models\Widget\LazyWidgetManagerInterface;
use Crm\GooglePlayBillingModule\Api\DeveloperNotificationPushWebhookApiHandler;
use Crm\GooglePlayBillingModule\Api\VerifyPurchaseApiHandler;
use Crm\GooglePlayBillingModule\Commands\CreateMissingRecurrentPaymentsCommand;
use Crm\GooglePlayBillingModule\Commands\RevalidateDeveloperNotificationCommand;
use Crm\GooglePlayBillingModule\Components\StopRecurrentPaymentButtonWidget\StopRecurrentPaymentButtonWidget;
use Crm\GooglePlayBillingModule\DataProviders\AccessTokenDataProvider;
use Crm\GooglePlayBillingModule\DataProviders\ExternalIdAdminFilterFormDataProvider;
use Crm\GooglePlayBillingModule\DataProviders\ExternalIdUniversalSearchDataProvider;
use Crm\GooglePlayBillingModule\Events\PairDeviceAccessTokensEventHandler;
use Crm\GooglePlayBillingModule\Events\PaymentStatusChangeEventHandler;
use Crm\GooglePlayBillingModule\Events\RemovedAccessTokenEventHandler;
use Crm\GooglePlayBillingModule\Hermes\DeveloperNotificationReceivedHandler;
use Crm\GooglePlayBillingModule\Hermes\VoidedPurchaseNotificationReceivedHandler;
use Crm\GooglePlayBillingModule\Models\User\GooglePlayUserDataProvider;
use Crm\GooglePlayBillingModule\Seeders\ConfigsSeeder;
use Crm\GooglePlayBillingModule\Seeders\PaymentGatewaysSeeder;
use Crm\GooglePlayBillingModule\Seeders\SnippetsSeeder;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\UsersModule\Events\PairDeviceAccessTokensEvent;
use Crm\UsersModule\Events\RemovedAccessTokenEvent;
use Crm\UsersModule\Models\Auth\UserTokenAuthorization;
use Tomaj\Hermes\Dispatcher;

class GooglePlayBillingModule extends CrmModule
{
    const META_KEY_PURCHASE_TOKEN = 'google_play_billing_purchase_token';
    const META_KEY_ORDER_ID = 'google_play_billing_order_id';
    const META_KEY_DEVELOPER_NOTIFICATION_ID = 'google_play_billing_developer_notification_id';
    const META_KEY_GRACE_PERIOD_SUBSCRIPTION = 'google_play_billing_grace_period_subscription';

    public const USER_SOURCE_APP = 'android-app';

    public function registerApiCalls(ApiRoutersContainerInterface $apiRoutersContainer)
    {
        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'google-play-billing', 'webhook'),
                DeveloperNotificationPushWebhookApiHandler::class,
                NoAuthorization::class
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

    public function registerCommands(CommandsContainerInterface $commandsContainer)
    {
        $commandsContainer->registerCommand($this->getInstance(RevalidateDeveloperNotificationCommand::class));
        $commandsContainer->registerCommand($this->getInstance(CreateMissingRecurrentPaymentsCommand::class));
    }

    public function registerHermesHandlers(Dispatcher $dispatcher)
    {
        $dispatcher->registerHandler(
            'developer-notification-received',
            $this->getInstance(DeveloperNotificationReceivedHandler::class)
        );

        $dispatcher->registerHandler(
            'voided-purchase-notification-received',
            $this->getInstance(VoidedPurchaseNotificationReceivedHandler::class)
        );
    }

    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(ConfigsSeeder::class));
        $seederManager->addSeeder($this->getInstance(PaymentGatewaysSeeder::class));
        $seederManager->addSeeder($this->getInstance(SnippetsSeeder::class), 100);
    }

    public function registerLazyEventHandlers(LazyEventEmitter $emitter)
    {
        $emitter->addListener(
            RemovedAccessTokenEvent::class,
            RemovedAccessTokenEventHandler::class
        );
        $emitter->addListener(
            PairDeviceAccessTokensEvent::class,
            PairDeviceAccessTokensEventHandler::class
        );
        $emitter->addListener(
            PaymentChangeStatusEvent::class,
            PaymentStatusChangeEventHandler::class
        );
    }

    public function registerDataProviders(DataProviderManager $dataProviderManager)
    {
        $dataProviderManager->registerDataProvider(
            'users.dataprovider.access_tokens',
            $this->getInstance(AccessTokenDataProvider::class)
        );
        $dataProviderManager->registerDataProvider(
            'payments.dataprovider.payments_filter_form',
            $this->getInstance(ExternalIdAdminFilterFormDataProvider::class)
        );
        $dataProviderManager->registerDataProvider(
            'admin.dataprovider.universal_search',
            $this->getInstance(ExternalIdUniversalSearchDataProvider::class)
        );
    }

    public function registerLazyWidgets(LazyWidgetManagerInterface $widgetManager)
    {
        $widgetManager->registerWidget(
            'frontend.payments.listing.recurrent',
            StopRecurrentPaymentButtonWidget::class,
            100
        );
        $widgetManager->registerWidget(
            'payments.user_payments.listing.recurrent',
            StopRecurrentPaymentButtonWidget::class,
            100
        );
    }

    public function registerUserData(UserDataRegistrator $dataRegistrator)
    {
        $dataRegistrator->addUserDataProvider($this->getInstance(GooglePlayUserDataProvider::class));
    }
}
