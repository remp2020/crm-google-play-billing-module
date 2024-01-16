<?php

namespace Crm\GooglePlayBillingModule\Tests\Hermes;

use Crm\ApplicationModule\Config\Repository\ConfigCategoriesRepository;
use Crm\ApplicationModule\Config\Repository\ConfigsRepository;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\GooglePlayBillingModule\Hermes\DeveloperNotificationReceivedHandler;
use Crm\GooglePlayBillingModule\Repository\DeveloperNotificationsRepository;
use Crm\GooglePlayBillingModule\Repository\GooglePlaySubscriptionTypesRepository;
use Crm\GooglePlayBillingModule\Repository\PurchaseDeviceTokensRepository;
use Crm\GooglePlayBillingModule\Repository\PurchaseTokensRepository;
use Crm\GooglePlayBillingModule\Seeders\PaymentGatewaysSeeder;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\PaymentStatusChangeHandler;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentItemMetaRepository;
use Crm\PaymentsModule\Repository\PaymentItemsRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Seeders\ConfigsSeeder as PaymentsConfigsSeeder;
use Crm\SubscriptionsModule\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Repository\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypeNamesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Seeders\ConfigSeeder as SubscriptionsConfigSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Google\Service\AndroidPublisher\SubscriptionPurchase;
use League\Event\Emitter;
use Mockery;
use Mockery\MockInterface;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Nette\Utils\Random;
use ReceiptValidator\GooglePlay\SubscriptionResponse;
use ReceiptValidator\GooglePlay\Validator;
use Tracy\Debugger;
use Tracy\ILogger;

class DeveloperNotificationReceivedHandlerGracePeriodTest extends DatabaseTestCase
{
    private string $googlePlayPackage = 'test.package';
    private ?ActiveRow $googlePlaySubscriptionTypeWeb = null;
    private ?ActiveRow $user = null;

    private DeveloperNotificationReceivedHandler $developerNotificationReceivedHandler;

    private DeveloperNotificationsRepository $developerNotificationsRepository;

    private GooglePlaySubscriptionTypesRepository $googlePlaySubscriptionTypesRepository;

    private PurchaseTokensRepository $purchaseTokensRepository;

    private PaymentsRepository $paymentsRepository;

    private PaymentMetaRepository $paymentMetaRepository;

    private SubscriptionTypesRepository $subscriptionTypesRepository;

    private SubscriptionTypeBuilder $subscriptionTypeBuilder;

    private SubscriptionsRepository $subscriptionsRepository;

    private SubscriptionMetaRepository $subscriptionMetaRepository;

    private UsersRepository $usersRepository;

    protected function requiredRepositories(): array
    {
        return [
            GooglePlaySubscriptionTypesRepository::class,
            DeveloperNotificationsRepository::class,
            PurchaseDeviceTokensRepository::class,
            PurchaseTokensRepository::class,
            DeviceTokensRepository::class,
            SubscriptionTypesRepository::class,
            SubscriptionTypeItemsRepository::class,
            SubscriptionTypeNamesRepository::class,
            SubscriptionsRepository::class,
            SubscriptionMetaRepository::class,
            PaymentGatewaysRepository::class,
            PaymentsRepository::class,
            PaymentMetaRepository::class,
            PaymentItemsRepository::class,
            PaymentItemMetaRepository::class,
            UsersRepository::class,
            ConfigCategoriesRepository::class,
            ConfigsRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
            PaymentGatewaysSeeder::class,
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionLengthMethodSeeder::class,
            SubscriptionTypeNamesSeeder::class,
            PaymentsConfigsSeeder::class,
            SubscriptionsConfigSeeder::class,
        ];
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->developerNotificationReceivedHandler = $this->inject(DeveloperNotificationReceivedHandler::class);

        $this->developerNotificationsRepository = $this->getRepository(DeveloperNotificationsRepository::class);
        $this->googlePlaySubscriptionTypesRepository = $this->getRepository(GooglePlaySubscriptionTypesRepository::class);
        $this->purchaseTokensRepository = $this->getRepository(PurchaseTokensRepository::class);

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->paymentMetaRepository = $this->getRepository(PaymentMetaRepository::class);
        $this->subscriptionTypesRepository = $this->getRepository(SubscriptionTypesRepository::class);
        $this->subscriptionTypeBuilder = $this->inject(SubscriptionTypeBuilder::class);
        $this->subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);
        $this->subscriptionMetaRepository = $this->getRepository(SubscriptionMetaRepository::class);
        $this->usersRepository = $this->getRepository(UsersRepository::class);


        // add event handler to create subscription

        /** @var Emitter $emitter */
        $emitter = $this->inject(Emitter::class);
        // clear initialized handlers (we do not want duplicated listeners)
        $emitter->removeAllListeners(PaymentChangeStatusEvent::class);
        $emitter->addListener(
            PaymentChangeStatusEvent::class,
            $this->inject(PaymentStatusChangeHandler::class)
        );
    }

    public function tearDown(): void
    {
        /** @var Emitter $emitter */
        $emitter = $this->inject(Emitter::class);
        $emitter->removeAllListeners(PaymentChangeStatusEvent::class);

        parent::tearDown();
    }

    public function testSuccessFutureDates()
    {
        /* ***************************************************************** *
         * FIRST PURCHASE ************************************************** *
         * ***************************************************************** */
        $purchaseTokenFirstPurchase = $this->purchaseTokensRepository->add(
            'purchase_token_' . Random::generate(),
            $this->googlePlayPackage,
            $this->getGooglePlaySubscriptionTypeWeb()->subscription_id
        );
        $developerNotificationFirstPurchase = $this->developerNotificationsRepository->add(
            $purchaseTokenFirstPurchase,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED
        );
        $hermesMessageFirstPurchase = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationFirstPurchase->getPrimary(),
            ],
        );

        $orderIdFirstPurchase = 'GPA.1111-1111-1111-11111';
        $startTimeMillisFirstPurchase = new DateTime('2030-04-27 19:20:57');
        $expiryTimeMillisFirstPurchase = new DateTime('2030-05-27 19:20:57');
        $priceAmountMicrosFirstPurchase = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // acknowledgementState: 1 -> set to acknowledged, so we don't need to mock acknowledgement service
        // autoRenewing: true -> first purchase set to create recurrent payment
        $googleResponseFirstPurchase = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": true,
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisFirstPurchase->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "linkedPurchaseToken": null,
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdFirstPurchase}",
                "paymentState": 1,
                "priceAmountMicros": "{$priceAmountMicrosFirstPurchase}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisFirstPurchase->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseFirstPurchase);

        // nothing exists
        $this->assertEquals(0, $this->paymentsRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(0, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount());

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageFirstPurchase);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationFirstPurchaseUpdated = $this->developerNotificationsRepository->find($developerNotificationFirstPurchase->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationFirstPurchaseUpdated->status
        );

        // payment, payment_meta and subscription created
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount()); // no subscription meta

        // payment status & subscription_type checks
        $paymentFirstPurchase = $this->paymentsRepository->getTable()->fetch();
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $paymentFirstPurchase->status);
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $paymentFirstPurchase->subscription_type_id);

        // check payment meta against order id and purchase token
        $this->assertEquals(
            $purchaseTokenFirstPurchase->purchase_token,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchase, 'google_play_billing_purchase_token')->value
        );
        $this->assertEquals(
            $developerNotificationFirstPurchase->id,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchase, 'google_play_billing_developer_notification_id')->value
        );
        $this->assertEquals(
            $orderIdFirstPurchase,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchase, 'google_play_billing_order_id')->value
        );

        // check payment, subscription type & start/end times against Google play validation response
        $subscriptionFirstPurchase = $this->subscriptionsRepository->getTable()->fetch();
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $paymentFirstPurchase->status);
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $paymentFirstPurchase->subscription_type_id);
        $this->assertEquals($startTimeMillisFirstPurchase, $paymentFirstPurchase->subscription_start_at);
        $this->assertEquals($expiryTimeMillisFirstPurchase, $paymentFirstPurchase->subscription_end_at);
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscriptionFirstPurchase->subscription_type_id);
        $this->assertEquals($startTimeMillisFirstPurchase, $subscriptionFirstPurchase->start_time);
        $this->assertEquals($expiryTimeMillisFirstPurchase, $subscriptionFirstPurchase->end_time);
        $this->assertEquals($subscriptionFirstPurchase->id, $paymentFirstPurchase->subscription_id);

        /* ***************************************************************** *
         * GRACE PERIOD NOTIFICATION *************************************** *
         * ***************************************************************** */

        $purchaseTokenGracePeriod = $purchaseTokenFirstPurchase;
        $developerNotificationGracePeriod = $this->developerNotificationsRepository->add(
            $purchaseTokenGracePeriod,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_IN_GRACE_PERIOD,
        );
        $hermesMessageGracePeriod = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationGracePeriod->getPrimary(),
            ],
        );

        // orderID is same, only suffix is different
        $orderIdGracePeriod = $orderIdFirstPurchase . '..0';
        // start of subscription within grace period notification is same as start of previous subscription
        $startTimeMillisGracePeriod = $expiryTimeMillisFirstPurchase;
        // expiry of grace period subscription will be cca 2 days after expiration of previous purchase
        $expiryTimeMillisGracePeriod = $expiryTimeMillisFirstPurchase->modifyClone('+2 days');
        // price doesn't matter now
        $priceAmountMicrosGracePeriod = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // other settings:
        // - acknowledgementState: set to 1 (acknowledged) so we don't need to mock acknowledgement service
        // - paymentState: set to 0 (payment pending); payment did not go through (that's why grace period was initiated)
        $paymentState = 0;
        // - autoRenewing: still true; grace period was initiated because there is issue with charging,
        //                 but user has still change to switch card / payment option
        $autoRenewing = true;
        $googleResponseGracePeriod = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": {$autoRenewing},
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisGracePeriod->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdGracePeriod}",
                "paymentState": {$paymentState},
                "priceAmountMicros": "{$priceAmountMicrosGracePeriod}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisGracePeriod->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseGracePeriod);

        // state from original notification - first purchase
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount()); // no subscription meta

        // handler returns only bool value; check expected log for result (nothing logged)
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->never())
            ->method('log');
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageGracePeriod);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationGracePeriodUpdated = $this->developerNotificationsRepository->find($developerNotificationGracePeriod->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationGracePeriodUpdated->status
        );

        // NO new payment and NO payment_meta
        // NEW subscription & NEW subscription_meta
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(2, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(4, $this->subscriptionMetaRepository->totalCount());

        $payments = $this->paymentsRepository->getTable()->order('created_at')->fetchAll();
        $paymentFirstPurchaseReload = reset($payments);
        $subscriptions = $this->subscriptionsRepository->getTable()->order('created_at')->fetchAll();
        $subscriptionFirstPurchaseReload = reset($subscriptions);
        $subscriptionGracePeriod = next($subscriptions);

        // check first payment against original purchase (check if something was overridden)
        // $paymentFirstPurchase and $subscriptionFirstPurchase are previously loaded rows of first purchase
        $this->assertEquals($paymentFirstPurchase->toArray(), $paymentFirstPurchaseReload->toArray());
        // first subscription freshly loaded from database will be now linked to next subscription
        $subscriptionFirstPurchaseData = $subscriptionFirstPurchase->toArray();
        $subscriptionFirstPurchaseReloadData = $subscriptionFirstPurchaseReload->toArray();
        $this->assertNull($subscriptionFirstPurchaseData['next_subscription_id']);
        $this->assertEquals($subscriptionGracePeriod->id, $subscriptionFirstPurchaseReloadData['next_subscription_id']);
        unset($subscriptionFirstPurchaseData['next_subscription_id']);
        unset($subscriptionFirstPurchaseReloadData['next_subscription_id']);
        // rest of subscription should be same
        $this->assertEquals($subscriptionFirstPurchaseData, $subscriptionFirstPurchaseReloadData);

        // check meta
        $this->assertEquals(
            $purchaseTokenFirstPurchase->purchase_token,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchaseReload, 'google_play_billing_purchase_token')->value
        );
        $this->assertEquals(
            $developerNotificationFirstPurchase->id,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchaseReload, 'google_play_billing_developer_notification_id')->value
        );
        $this->assertEquals(
            $orderIdFirstPurchase,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchaseReload, 'google_play_billing_order_id')->value
        );

        // check new subscription (grace period) & meta
        $this->assertEquals($subscriptionFirstPurchaseReload->subscription_type_id, $subscriptionGracePeriod->subscription_type_id);
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscriptionGracePeriod->subscription_type_id);
        $this->assertEquals($startTimeMillisGracePeriod, $subscriptionGracePeriod->start_time); // grace period should start after last valid Google subscription
        $this->assertEquals($expiryTimeMillisGracePeriod, $subscriptionGracePeriod->end_time);
        $this->assertEquals(
            $purchaseTokenGracePeriod->purchase_token,
            $this->subscriptionMetaRepository->findBySubscriptionAndKey($subscriptionGracePeriod, 'google_play_billing_purchase_token')->value
        );
        $this->assertEquals(
            $developerNotificationGracePeriod->id,
            $this->subscriptionMetaRepository->findBySubscriptionAndKey($subscriptionGracePeriod, 'google_play_billing_developer_notification_id')->value
        );
        $this->assertEquals(
            $orderIdGracePeriod,
            $this->subscriptionMetaRepository->findBySubscriptionAndKey($subscriptionGracePeriod, 'google_play_billing_order_id')->value
        );
        $this->assertTrue(
            (bool) $this->subscriptionMetaRepository->findBySubscriptionAndKey($subscriptionGracePeriod, 'google_play_billing_grace_period_subscription')->value
        );
    }

    public function testSuccessCurrentDates()
    {
        /* ***************************************************************** *
         * 1.) FIRST PURCHASE ********************************************** *
         * ***************************************************************** */
        $purchaseTokenFirstPurchase = $this->purchaseTokensRepository->add(
            'purchase_token_' . Random::generate(),
            $this->googlePlayPackage,
            $this->getGooglePlaySubscriptionTypeWeb()->subscription_id
        );
        $developerNotificationFirstPurchase = $this->developerNotificationsRepository->add(
            $purchaseTokenFirstPurchase,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED
        );
        $hermesMessageFirstPurchase = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationFirstPurchase->getPrimary(),
            ],
        );

        $now = new DateTime();
        $now->setTime($now->format('H'), $now->format('i'), $now->format('s'));

        $orderIdFirstPurchase = 'GPA.1111-1111-1111-11111';
        $startTimeMillisFirstPurchase = $now->modifyClone('-1 month');
        $expiryTimeMillisFirstPurchase = $now->modifyClone('-1 hour');
        $priceAmountMicrosFirstPurchase = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // acknowledgementState: 1 -> set to acknowledged, so we don't need to mock acknowledgement service
        // autoRenewing: true -> first purchase set to create recurrent payment
        $googleResponseFirstPurchase = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": true,
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisFirstPurchase->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "linkedPurchaseToken": null,
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdFirstPurchase}",
                "paymentState": 1,
                "priceAmountMicros": "{$priceAmountMicrosFirstPurchase}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisFirstPurchase->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseFirstPurchase);

        // validate state before handling
        $this->assertEquals(0, $this->paymentsRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(0, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount());

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageFirstPurchase);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationFirstPurchaseUpdated = $this->developerNotificationsRepository->find($developerNotificationFirstPurchase->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationFirstPurchaseUpdated->status
        );

        // payment, payment_meta and subscription created
        $this->assertEquals(1, $this->paymentsRepository->totalCount()); // new payment
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount()); // new subscription
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount()); // 3 new payment meta
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount()); // no subscription meta

        // payment status & subscription_type checks
        $paymentFirstPurchase = $this->paymentsRepository->getTable()->fetch();
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $paymentFirstPurchase->status);
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $paymentFirstPurchase->subscription_type_id);
        $this->assertEquals($startTimeMillisFirstPurchase, $paymentFirstPurchase->subscription_start_at);
        $this->assertEquals($expiryTimeMillisFirstPurchase, $paymentFirstPurchase->subscription_end_at);

        // check payment meta against order id and purchase token
        $this->assertEquals(
            $purchaseTokenFirstPurchase->purchase_token,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchase, GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN)->value
        );
        $this->assertEquals(
            $developerNotificationFirstPurchase->id,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchase, GooglePlayBillingModule::META_KEY_DEVELOPER_NOTIFICATION_ID)->value
        );
        $this->assertEquals(
            $orderIdFirstPurchase,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchase, GooglePlayBillingModule::META_KEY_ORDER_ID)->value
        );

        // check payment, subscription type & start/end times against Google play validation response
        $subscriptionFirstPurchase = $this->subscriptionsRepository->getTable()->fetch();
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscriptionFirstPurchase->subscription_type_id);

        /*  if subscription->start_time is in the past, it uses payment->paid_at as start time.
            we want to mock situation with subscription with start and end time as it was created by notification in the past (1 month ago)
        */
        $subscription = $this->subscriptionsRepository->getTable()->fetch();
        $this->subscriptionsRepository->update($subscription, [
            'start_time' => $startTimeMillisFirstPurchase,
            'end_time' => $expiryTimeMillisFirstPurchase,
        ]);

        /* ***************************************************************** *
         * 2.) GRACE PERIOD NOTIFICATION *********************************** *
         * ***************************************************************** */

        $purchaseTokenGracePeriod = $purchaseTokenFirstPurchase;
        $developerNotificationGracePeriod = $this->developerNotificationsRepository->add(
            $purchaseTokenGracePeriod,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_IN_GRACE_PERIOD,
        );
        $hermesMessageGracePeriod = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationGracePeriod->getPrimary(),
            ],
        );

        // orderID is same, only suffix is different
        $orderIdGracePeriod = $orderIdFirstPurchase . '..0';
        // start of subscription within grace period notification is same as start of previous subscription
        $startTimeMillisGracePeriod = $startTimeMillisFirstPurchase;
        // expiry of grace period subscription will be cca 2 days after expiration of previous purchase
        $expiryTimeMillisGracePeriod = $expiryTimeMillisFirstPurchase->modifyClone('+1 day');
        // price doesn't matter now
        $priceAmountMicrosGracePeriod = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // other settings:
        // - acknowledgementState: set to 1 (acknowledged) so we don't need to mock acknowledgement service
        // - paymentState: set to 0 (payment pending); payment did not go through (that's why grace period was initiated)
        $paymentState = 0;
        // - autoRenewing: still true; grace period was initiated because there is issue with charging,
        //                 but user has still change to switch card / payment option
        $autoRenewing = true;
        $googleResponseGracePeriod = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": {$autoRenewing},
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisGracePeriod->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdGracePeriod}",
                "paymentState": {$paymentState},
                "priceAmountMicros": "{$priceAmountMicrosGracePeriod}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisGracePeriod->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseGracePeriod);

        // handler returns only bool value; check expected log for result (nothing logged)
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->never())
            ->method('log');
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageGracePeriod);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationGracePeriodUpdated = $this->developerNotificationsRepository->find($developerNotificationGracePeriod->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationGracePeriodUpdated->status
        );

        // NO new payment and NO payment_meta
        // NEW subscription & NEW subscription_meta
        $this->assertEquals(1, $this->paymentsRepository->totalCount()); // no change
        $this->assertEquals(2, $this->subscriptionsRepository->totalCount()); // new subscription
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount()); // no change
        $this->assertEquals(4, $this->subscriptionMetaRepository->totalCount()); // 4 new subscription meta

        $subscriptionGracePeriod = $this->subscriptionMetaRepository->findSubscriptionBy(GooglePlayBillingModule::META_KEY_GRACE_PERIOD_SUBSCRIPTION, true);
        $this->assertNotNull($subscriptionGracePeriod);

        // grace subscription starts NOW, subscription->end_time < $subscriptionGracePeriod->start_time <= NOW()
        $this->assertGreaterThan($subscription->end_time, $subscriptionGracePeriod->start_time);
        $this->assertLessThanOrEqual(new DateTime(), $subscriptionGracePeriod->start_time);

        // grace subscription ends in the same time as end time provided in notification, we store only seconds in DB
        $this->assertEquals(
            $expiryTimeMillisGracePeriod,
            $subscriptionGracePeriod->end_time
        );

        $paymentFirstPurchaseReload = $this->paymentsRepository->getTable()->fetch();
        $subscriptionFirstPurchaseReload = $this->subscriptionsRepository->getTable()->order('end_time ASC')->fetch();

        // check first payment against original purchase (check if something was overridden)
        // $paymentFirstPurchase and $subscriptionFirstPurchase are previously loaded rows of first purchase
        $this->assertEquals($paymentFirstPurchase->toArray(), $paymentFirstPurchaseReload->toArray());

        // check meta
        $this->assertEquals(
            $purchaseTokenFirstPurchase->purchase_token,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchaseReload, GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN)->value
        );
        $this->assertEquals(
            $developerNotificationFirstPurchase->id,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchaseReload, GooglePlayBillingModule::META_KEY_DEVELOPER_NOTIFICATION_ID)->value
        );
        $this->assertEquals(
            $orderIdFirstPurchase,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchaseReload, GooglePlayBillingModule::META_KEY_ORDER_ID)->value
        );

        // check new subscription (grace period) & meta
        $this->assertEquals($subscriptionFirstPurchaseReload->subscription_type_id, $subscriptionGracePeriod->subscription_type_id);
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscriptionGracePeriod->subscription_type_id);
        $this->assertEquals(
            $purchaseTokenGracePeriod->purchase_token,
            $this->subscriptionMetaRepository->findBySubscriptionAndKey($subscriptionGracePeriod, GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN)->value
        );
        $this->assertEquals(
            $developerNotificationGracePeriod->id,
            $this->subscriptionMetaRepository->findBySubscriptionAndKey($subscriptionGracePeriod, GooglePlayBillingModule::META_KEY_DEVELOPER_NOTIFICATION_ID)->value
        );
        $this->assertEquals(
            $orderIdGracePeriod,
            $this->subscriptionMetaRepository->findBySubscriptionAndKey($subscriptionGracePeriod, GooglePlayBillingModule::META_KEY_ORDER_ID)->value
        );
        $this->assertTrue(
            (bool) $this->subscriptionMetaRepository->findBySubscriptionAndKey($subscriptionGracePeriod, GooglePlayBillingModule::META_KEY_GRACE_PERIOD_SUBSCRIPTION)->value
        );
    }

    public function testPurchaseInGracePeriod()
    {
        /* ***************************************************************** *
        * 1.) FIRST PURCHASE ********************************************** *
        * ***************************************************************** */
        $purchaseTokenFirstPurchase = $this->purchaseTokensRepository->add(
            'purchase_token_' . Random::generate(),
            $this->googlePlayPackage,
            $this->getGooglePlaySubscriptionTypeWeb()->subscription_id
        );
        $developerNotificationFirstPurchase = $this->developerNotificationsRepository->add(
            $purchaseTokenFirstPurchase,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED
        );
        $hermesMessageFirstPurchase = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationFirstPurchase->getPrimary(),
            ],
        );

        $now = new DateTime();
        $now->setTime($now->format('H'), $now->format('i'), $now->format('s'));

        $orderIdFirstPurchase = 'GPA.1111-1111-1111-11111';
        $startTimeMillisFirstPurchase = $now->modifyClone('-1 month');
        $expiryTimeMillisFirstPurchase = $now->modifyClone('-1 hour');
        $priceAmountMicrosFirstPurchase = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // acknowledgementState: 1 -> set to acknowledged, so we don't need to mock acknowledgement service
        // autoRenewing: true -> first purchase set to create recurrent payment
        $googleResponseFirstPurchase = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": true,
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisFirstPurchase->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "linkedPurchaseToken": null,
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdFirstPurchase}",
                "paymentState": 1,
                "priceAmountMicros": "{$priceAmountMicrosFirstPurchase}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisFirstPurchase->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseFirstPurchase);

        // validate state before handling
        $this->assertEquals(0, $this->paymentsRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(0, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount());

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageFirstPurchase);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationFirstPurchaseUpdated = $this->developerNotificationsRepository->find($developerNotificationFirstPurchase->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationFirstPurchaseUpdated->status
        );

        // payment, payment_meta and subscription created
        $this->assertEquals(1, $this->paymentsRepository->totalCount()); // new payment
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount()); // new subscription
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount()); // 3 new payment meta
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount()); // no subscription meta


        /*  if subscription->start_time is in the past, it uses payment->paid_at as start time.
            we want to mock situation with subscription with start and end time as it was created by notification in the past (1 month ago)
        */
        $subscription = $this->subscriptionsRepository->getTable()->fetch();
        $this->subscriptionsRepository->update($subscription, [
            'start_time' => $startTimeMillisFirstPurchase,
            'end_time' => $expiryTimeMillisFirstPurchase,
        ]);

        /* ***************************************************************** *
         * 2.) GRACE PERIOD NOTIFICATION *********************************** *
         * ***************************************************************** */

        $purchaseTokenGracePeriod = $purchaseTokenFirstPurchase;
        $developerNotificationGracePeriod = $this->developerNotificationsRepository->add(
            $purchaseTokenGracePeriod,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_IN_GRACE_PERIOD,
        );
        $hermesMessageGracePeriod = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationGracePeriod->getPrimary(),
            ],
        );

        // orderID is same, only suffix is different
        $orderIdGracePeriod = $orderIdFirstPurchase . '..0';
        // start of subscription within grace period notification is same as start of previous subscription
        $startTimeMillisGracePeriod = $startTimeMillisFirstPurchase;
        // expiry of grace period subscription will be cca 2 days after expiration of previous purchase
        $expiryTimeMillisGracePeriod = $expiryTimeMillisFirstPurchase->modifyClone('+1 day');
        // price doesn't matter now
        $priceAmountMicrosGracePeriod = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // other settings:
        // - acknowledgementState: set to 1 (acknowledged) so we don't need to mock acknowledgement service
        // - paymentState: set to 0 (payment pending); payment did not go through (that's why grace period was initiated)
        $paymentState = 0;
        // - autoRenewing: still true; grace period was initiated because there is issue with charging,
        //                 but user has still change to switch card / payment option
        $autoRenewing = true;
        $googleResponseGracePeriod = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": {$autoRenewing},
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisGracePeriod->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdGracePeriod}",
                "paymentState": {$paymentState},
                "priceAmountMicros": "{$priceAmountMicrosGracePeriod}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisGracePeriod->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseGracePeriod);

        // handler returns only bool value; check expected log for result (nothing logged)
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->never())
            ->method('log');
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageGracePeriod);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationGracePeriodUpdated = $this->developerNotificationsRepository->find($developerNotificationGracePeriod->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationGracePeriodUpdated->status
        );

        // NO new payment and NO payment_meta
        // NEW subscription & NEW subscription_meta
        $this->assertEquals(1, $this->paymentsRepository->totalCount()); // no change
        $this->assertEquals(2, $this->subscriptionsRepository->totalCount()); // new subscription
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount()); // no change
        $this->assertEquals(4, $this->subscriptionMetaRepository->totalCount()); // 4 new subscription meta

        $graceSubscription = $this->subscriptionMetaRepository->findSubscriptionBy(GooglePlayBillingModule::META_KEY_GRACE_PERIOD_SUBSCRIPTION, true);
        $this->assertNotNull($graceSubscription);

        // grace subscription starts NOW, subscription->end_time < $graceSubscription->start_time <= NOW()
        $this->assertGreaterThan($subscription->end_time, $graceSubscription->start_time);
        $this->assertLessThanOrEqual(new DateTime(), $graceSubscription->start_time);

        // grace subscription ends in the same time as end time provided in notification, we store only seconds in DB
        $this->assertEquals(
            $expiryTimeMillisGracePeriod,
            $graceSubscription->end_time
        );

        // mock we are already some time in grace period
        $this->subscriptionsRepository->update($graceSubscription, [
            'start_time' => new DateTime('-30 minutes')
        ]);

        /* ***************************************************************** *
         * 3.) SECOND PURCHASE ********************************************* *
         * ***************************************************************** */
        $purchaseTokenSecondPurchase = $purchaseTokenFirstPurchase;
        $developerNotificationSecondPurchase = $this->developerNotificationsRepository->add(
            $purchaseTokenSecondPurchase,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED
        );
        $hermesMessageSecondPurchase = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationSecondPurchase->getPrimary(),
            ],
        );

        /* ***************************************************************** *
         * orderID is same as first grace period *************************** *
         * ***************************************************************** */
        $orderIdSecondPurchase = $orderIdFirstPurchase . '..0';
        // start of next paid subscription starts after end of grace period
        $startTimeMillisSecondPurchase = $startTimeMillisFirstPurchase;
        $expiryTimeMillisSecondPurchase = $now->modifyClone('+1 month');
        $priceAmountMicrosSecondPurchase = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // acknowledgementState: 1 -> set to acknowledged, so we don't need to mock acknowledgement service
        // autoRenewing: true -> first purchase set to create recurrent payment
        $googleResponseSecondPurchase = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": true,
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisSecondPurchase->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "linkedPurchaseToken": null,
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdSecondPurchase}",
                "paymentState": 1,
                "priceAmountMicros": "{$priceAmountMicrosSecondPurchase}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisSecondPurchase->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseSecondPurchase);

        // validate previous state - first purchase + first grace period
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(2, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(4, $this->subscriptionMetaRepository->totalCount());

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageSecondPurchase);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationSecondPurchaseUpdated = $this->developerNotificationsRepository->find($developerNotificationSecondPurchase->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationSecondPurchaseUpdated->status
        );

        // payment, payment_meta and subscription created
        $this->assertEquals(2, $this->paymentsRepository->totalCount()); // 1 new payment
        $this->assertEquals(3, $this->subscriptionsRepository->totalCount()); // 1 new subscription
        $this->assertEquals(6, $this->paymentMetaRepository->totalCount()); // 3 new meta
        $this->assertEquals(4, $this->subscriptionMetaRepository->totalCount()); // no change

        $paymentMeta = $this->paymentMetaRepository->findByMeta(GooglePlayBillingModule::META_KEY_ORDER_ID, $orderIdSecondPurchase);
        $this->assertNotNull($paymentMeta);

        $secondPayment = $paymentMeta->payment;
        $secondSubscription = $secondPayment->subscription;
        $this->assertNotNull($secondSubscription);

        // refresh grace subscription
        $graceSubscription = $this->subscriptionMetaRepository->findSubscriptionBy(GooglePlayBillingModule::META_KEY_GRACE_PERIOD_SUBSCRIPTION, true);

        // second subscription starts at payment->paid_at, graceSubscription->end_time <= secondSubscription->start_time
        $this->assertEquals($secondPayment->paid_at, $secondSubscription->start_time);
        $this->assertLessThanOrEqual($graceSubscription->end_time, $secondSubscription->start_time);

        // second subscription ends in the same time as end time provided in notification, we store only seconds in DB
        $this->assertEquals(
            $expiryTimeMillisSecondPurchase,
            $secondSubscription->end_time
        );

        // subscription purchased in grace period -> active grace period subscription updated end time to NOW
        $this->assertLessThanOrEqual(new DateTime(), $graceSubscription->end_time);
    }

    public function testFailedLateGracePeriodNotification()
    {
        $this->assertEquals(0, $this->paymentsRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(0, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount());

        /* ***************************************************************** *
         * FIRST PURCHASE ************************************************** *
         * ***************************************************************** */
        $purchaseTokenFirstPurchase = $this->purchaseTokensRepository->add(
            'purchase_token_' . Random::generate(),
            $this->googlePlayPackage,
            $this->getGooglePlaySubscriptionTypeWeb()->subscription_id
        );
        $developerNotificationFirstPurchase = $this->developerNotificationsRepository->add(
            $purchaseTokenFirstPurchase,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED
        );
        $hermesMessageFirstPurchase = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationFirstPurchase->getPrimary(),
            ],
        );

        $orderIdFirstPurchase = 'GPA.1111-1111-1111-11111';
        $startTimeMillisFirstPurchase = new DateTime('2030-04-27 19:20:57');
        $expiryTimeMillisFirstPurchase = new DateTime('2030-05-27 19:20:57');
        $priceAmountMicrosFirstPurchase = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // acknowledgementState: 1 -> set to acknowledged, so we don't need to mock acknowledgement service
        // autoRenewing: true -> first purchase set to create recurrent payment
        $googleResponseFirstPurchase = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": true,
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisFirstPurchase->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "linkedPurchaseToken": null,
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdFirstPurchase}",
                "paymentState": 1,
                "priceAmountMicros": "{$priceAmountMicrosFirstPurchase}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisFirstPurchase->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseFirstPurchase);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageFirstPurchase);
        $this->assertTrue($result);

        /* ***************************************************************** *
         * AUTO RENEWED PURCHASE ******************************************* *
         * ***************************************************************** */

        $developerNotificationRenewed = $this->developerNotificationsRepository->add(
            $purchaseTokenFirstPurchase,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_RENEWED
        );
        $hermesMessageRenewed = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationRenewed->getPrimary(),
            ],
        );

        $orderIdRenewed = $orderIdFirstPurchase . '..0';
        $startTimeMillisRenewed = $expiryTimeMillisFirstPurchase;
        $expiryTimeMillisRenewed = $startTimeMillisRenewed->modifyClone('+1 month');
        $priceAmountMicrosRenewed = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // acknowledgementState: 1 -> set to acknowledged, so we don't need to mock acknowledgement service
        // autoRenewing: true -> first purchase set to create recurrent payment
        $googleResponseRenewed = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": true,
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisRenewed->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "linkedPurchaseToken": null,
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdRenewed}",
                "paymentState": 1,
                "priceAmountMicros": "{$priceAmountMicrosRenewed}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisRenewed->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseRenewed);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageRenewed);
        $this->assertTrue($result);

        $this->assertEquals(2, $this->paymentsRepository->totalCount());
        $this->assertEquals(2, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(6, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount());
        // load renewed subscription for later check of log
        $subscriptionFirstPurchase = $this->subscriptionsRepository->getTable()->order('id ASC')->limit(1)->fetch();
        $subscriptionRenewed = $subscriptionFirstPurchase->next_subscription;

        /* ***************************************************************** *
         * GRACE PERIOD NOTIFICATION *************************************** *
         * ***************************************************************** */

        $purchaseTokenGracePeriod = $purchaseTokenFirstPurchase;
        $developerNotificationGracePeriod = $this->developerNotificationsRepository->add(
            $purchaseTokenGracePeriod,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_IN_GRACE_PERIOD,
        );
        $hermesMessageGracePeriod = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationGracePeriod->getPrimary(),
            ],
        );

        // orderID is same as renewed payment
        $orderIdGracePeriod = $orderIdFirstPurchase . '..0';
        // start time is the same as renewed subscription
        $startTimeMillisGracePeriod = $startTimeMillisRenewed;
        // end time is the same as renewed subscription
        $expiryTimeMillisGracePeriod = $expiryTimeMillisRenewed;
        // price doesn't matter now
        $priceAmountMicrosGracePeriod = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // other settings:
        // - acknowledgementState: set to 1 (acknowledged) so we don't need to mock acknowledgement service
        // - paymentState: set to 0 (payment pending); payment did not go through (that's why grace period was initiated)
        $paymentState = 0;
        // - autoRenewing: still true; grace period was initiated because there is issue with charging,
        //                 but user has still change to switch card / payment option
        $autoRenewing = true;
        $googleResponseGracePeriod = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": {$autoRenewing},
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisGracePeriod->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdGracePeriod}",
                "paymentState": {$paymentState},
                "priceAmountMicros": "{$priceAmountMicrosGracePeriod}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisGracePeriod->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseGracePeriod);

        // handler returns only bool value; check expected log for result (error should be logged)
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->once())
            ->method('log')
            ->with(
                "Unable to create grace period subscription. " .
                "DeveloperNotification ID: [{$developerNotificationGracePeriod->id}]. Error: [" .
                "There is already subscription ID [{$subscriptionRenewed->id}] with later end time " .
                "linked through purchase token [{$developerNotificationGracePeriod->purchase_token}].]"
            );
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageGracePeriod);
        $this->assertFalse($result);

        // no new payment, subscription or meta were created
        $this->assertEquals(2, $this->paymentsRepository->totalCount());
        $this->assertEquals(2, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(6, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount());
    }

    public function testFailedGracePeriodNoPreviousSubscription()
    {
        // nothing exists
        $this->assertEquals(0, $this->paymentsRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(0, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount());

        /* ***************************************************************** *
         * GRACE PERIOD NOTIFICATION *************************************** *
         * ***************************************************************** */

        $purchaseTokenGracePeriod = $this->purchaseTokensRepository->add(
            'purchase_token_' . Random::generate(),
            $this->googlePlayPackage,
            $this->getGooglePlaySubscriptionTypeWeb()->subscription_id
        );
        $developerNotificationGracePeriod = $this->developerNotificationsRepository->add(
            $purchaseTokenGracePeriod,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_IN_GRACE_PERIOD,
        );
        $hermesMessageGracePeriod = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationGracePeriod->getPrimary(),
            ],
        );

        $orderIdGracePeriod = 'GPA.2222-2222-2222-22222..1';
        $startTimeMillisGracePeriod = new DateTime('2030-04-27 19:20:57');
        $expiryTimeMillisGracePeriod = new DateTime('2030-05-27 19:20:57');
        // price doesn't matter now
        $priceAmountMicrosGracePeriod = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // other settings:
        // - acknowledgementState: set to 1 (acknowledged) so we don't need to mock acknowledgement service
        // - paymentState: set to 0 (payment pending); payment did not go through (that's why grace period was initiated)
        $paymentState = 0;
        // - autoRenewing: still true; grace period was initiated because there is issue with charging,
        //                 but user has still change to switch card / payment option
        $autoRenewing = true;
        $googleResponseGracePeriod = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": {$autoRenewing},
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisGracePeriod->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdGracePeriod}",
                "paymentState": {$paymentState},
                "priceAmountMicros": "{$priceAmountMicrosGracePeriod}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisGracePeriod->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseGracePeriod);

        // handler returns only bool value; check expected log for result (error should be logged)
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->once())
            ->method('log')
            ->with(
                "Unable to create grace period subscription. " .
                "DeveloperNotification ID: [{$developerNotificationGracePeriod->id}]. Error: [" .
                "Cannot grant grace period without previous purchase. " .
                "Unable to find payment with purchase token [{$developerNotificationGracePeriod->purchase_token}].]"
            );
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageGracePeriod);
        $this->assertFalse($result);

        // no new payment, subscription or meta were created
        $this->assertEquals(0, $this->paymentsRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(0, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount());
    }

    /* **************************************************************** *
     * HELPER METHODS
     * **************************************************************** */

    private function injectSubscriptionResponseIntoGooglePlayValidatorMock(string $expectedGoogleValidatorSubscriptionResponse): void
    {
        /** @var Validator|MockInterface $googlePlayValidatorMocked */
        $googlePlayValidatorMocked = Mockery::mock(Validator::class);
        $googlePlayValidatorMocked->shouldReceive('setPackageName->setPurchaseToken->setProductId->validateSubscription')
            ->andReturn(new SubscriptionResponse(new SubscriptionPurchase(
                Json::decode($expectedGoogleValidatorSubscriptionResponse, Json::FORCE_ARRAY)
            )))
            ->getMock();
        $this->developerNotificationReceivedHandler->setGooglePlayValidator($googlePlayValidatorMocked);
    }

    private function getUser(): ActiveRow
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $this->user = $this->usersRepository->findBy('email', 'test@example.com');
        if (!$this->user) {
            $this->user = $this->usersRepository->add('test@example.com', 'password');
        }

        return $this->user;
    }

    private function getGooglePlaySubscriptionTypeWeb(): ActiveRow
    {
        if ($this->googlePlaySubscriptionTypeWeb !== null) {
            return $this->googlePlaySubscriptionTypeWeb;
        }

        $googlePlaySubscriptionIdWeb = 'test.package.dennikn.test.web';
        $subscriptionTypeCodeWeb = 'google_inapp_web';

        $subscriptionTypeWeb = $this->subscriptionTypesRepository->findByCode($subscriptionTypeCodeWeb);
        if (!$subscriptionTypeWeb) {
            $subscriptionTypeWeb = $this->subscriptionTypeBuilder->createNew()
                ->setName('Google Pay test subscription WEB month')
                ->setUserLabel('Google Pay test subscription WEB month')
                ->setPrice(6.99)
                ->setCode($subscriptionTypeCodeWeb)
                ->setLength(31)
                ->setActive(true)
                ->save();
        }
        $this->googlePlaySubscriptionTypeWeb = $this->googlePlaySubscriptionTypesRepository->findByGooglePlaySubscriptionId($googlePlaySubscriptionIdWeb);
        if (!$this->googlePlaySubscriptionTypeWeb) {
            $this->googlePlaySubscriptionTypeWeb = $this->googlePlaySubscriptionTypesRepository->add(
                $googlePlaySubscriptionIdWeb,
                $subscriptionTypeWeb
            );
        }

        return $this->googlePlaySubscriptionTypeWeb;
    }
}
