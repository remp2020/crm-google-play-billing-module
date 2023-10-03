<?php

namespace Crm\GooglePlayBillingModule\Tests\Hermes;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\GooglePlayBillingModule\Hermes\DeveloperNotificationReceivedHandler;
use Crm\GooglePlayBillingModule\Repository\DeveloperNotificationsRepository;
use Crm\GooglePlayBillingModule\Repository\GooglePlaySubscriptionTypesRepository;
use Crm\GooglePlayBillingModule\Repository\PurchaseDeviceTokensRepository;
use Crm\GooglePlayBillingModule\Repository\PurchaseTokensRepository;
use Crm\GooglePlayBillingModule\Seeders\PaymentGatewaysSeeder;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentItemMetaRepository;
use Crm\PaymentsModule\Repository\PaymentItemsRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Repository\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypeNamesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
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
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
            PaymentGatewaysSeeder::class,
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionLengthMethodSeeder::class,
            SubscriptionTypeNamesSeeder::class,
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
        $emitter->removeAllListeners(\Crm\PaymentsModule\Events\PaymentChangeStatusEvent::class);
        $emitter->addListener(
            \Crm\PaymentsModule\Events\PaymentChangeStatusEvent::class,
            $this->inject(\Crm\PaymentsModule\Events\PaymentStatusChangeHandler::class)
        );
    }

    public function tearDown(): void
    {
        /** @var Emitter $emitter */
        $emitter = $this->inject(Emitter::class);
        $emitter->removeAllListeners(\Crm\PaymentsModule\Events\PaymentChangeStatusEvent::class);

        parent::tearDown();
    }

    public function testSuccess()
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
        $startTimeMillisGracePeriod = $startTimeMillisFirstPurchase;
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
        $this->assertEquals(3, $this->subscriptionMetaRepository->totalCount());

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
        $this->assertEquals($expiryTimeMillisFirstPurchase, $subscriptionGracePeriod->start_time); // grace period should start after last valid Google subscription
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
    }

    // same as testSuccess() until SECOND GRACE PERIOD section
    public function testSuccessSecondGracePeriod()
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
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount());

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
        $startTimeMillisGracePeriod = $startTimeMillisFirstPurchase;
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
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount());

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
        $this->assertEquals(3, $this->subscriptionMetaRepository->totalCount()); // 3 new subscription meta

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
        $this->assertEquals($expiryTimeMillisFirstPurchase, $subscriptionGracePeriod->start_time); // grace period should start after last valid Google subscription
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

        /* ***************************************************************** *
         * SECOND GRACE PERIOD NOTIFICATION ******************************** *
         * ***************************************************************** */

        $purchaseTokenGracePeriodSecond = $purchaseTokenFirstPurchase;
        $developerNotificationGracePeriodSecond = $this->developerNotificationsRepository->add(
            $purchaseTokenGracePeriodSecond,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_IN_GRACE_PERIOD,
        );
        $hermesMessageGracePeriodSecond = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationGracePeriodSecond->getPrimary(),
            ],
        );

        // orderID is same, only suffix is different
        $orderIdGracePeriodSecond = $orderIdFirstPurchase . '..1';
        // start of subscription within grace period notification is same as start of previous regular subscription
        $startTimeMillisGracePeriodSecond = $startTimeMillisFirstPurchase;
        // expiry of grace period subscription will be cca 2 days after expiration of previous purchase
        $expiryTimeMillisGracePeriodSecond = $expiryTimeMillisGracePeriod->modifyClone('+2 days');
        // price doesn't matter now
        $priceAmountMicrosGracePeriodSecond = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // other settings:
        // - acknowledgementState: set to 1 (acknowledged) so we don't need to mock acknowledgement service
        // - paymentState: set to 0 (payment pending); payment did not go through (that's why grace period was initiated)
        $paymentState = 0;
        // - autoRenewing: still true; grace period was initiated because there is issue with charging,
        //                 but user has still change to switch card / payment option
        $autoRenewing = true;
        $googleResponseGracePeriodSecond = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": {$autoRenewing},
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisGracePeriodSecond->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdGracePeriodSecond}",
                "paymentState": {$paymentState},
                "priceAmountMicros": "{$priceAmountMicrosGracePeriodSecond}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisGracePeriodSecond->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseGracePeriodSecond);

        // validate previous state - first purchase + first grace period
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(2, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(3, $this->subscriptionMetaRepository->totalCount());

        // handler returns only bool value; check expected log for result (nothing logged)
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->never())
            ->method('log');
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageGracePeriodSecond);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationGracePeriodSecondUpdated = $this->developerNotificationsRepository->find($developerNotificationGracePeriodSecond->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationGracePeriodSecondUpdated->status
        );

        // NO new payment and NO payment_meta
        // NEW subscription & NEW subscription_meta
        $this->assertEquals(1, $this->paymentsRepository->totalCount()); // no change
        $this->assertEquals(3, $this->subscriptionsRepository->totalCount()); // new subscription
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount()); // no change
        $this->assertEquals(6, $this->subscriptionMetaRepository->totalCount()); // 3 new subscription meta

        $payments = $this->paymentsRepository->getTable()->order('created_at')->fetchAll();
        $paymentFirstPurchaseReload = reset($payments);
        $subscriptions = $this->subscriptionsRepository->getTable()->order('created_at')->fetchAll();
        $subscriptionFirstPurchaseReload = reset($subscriptions);
        $subscriptionGracePeriod = next($subscriptions);
        $subscriptionGracePeriodSecond = next($subscriptions);

        // check first payment against original purchase (check if something was overridden)
        // $paymentFirstPurchase and $subscriptionFirstPurchase are previously loaded rows of first purchase
        $this->assertEquals($paymentFirstPurchase->toArray(), $paymentFirstPurchaseReload->toArray());
        // first subscription freshly loaded from database is still linked to first grace period
        $this->assertEquals($subscriptionGracePeriod->id, $subscriptionFirstPurchaseReload->next_subscription_id);
        // and first grace period subscription freshly loaded from database is now linked to second grace period
        $this->assertEquals($subscriptionGracePeriodSecond->id, $subscriptionGracePeriod->next_subscription_id);

        // check new subscription (second grace period) & meta
        $this->assertEquals($subscriptionFirstPurchaseReload->subscription_type_id, $subscriptionGracePeriodSecond->subscription_type_id);
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscriptionGracePeriodSecond->subscription_type_id);
        $this->assertEquals($expiryTimeMillisGracePeriod, $subscriptionGracePeriodSecond->start_time);
        $this->assertEquals($expiryTimeMillisGracePeriodSecond, $subscriptionGracePeriodSecond->end_time);
        $this->assertEquals(
            $purchaseTokenGracePeriodSecond->purchase_token,
            $this->subscriptionMetaRepository->findBySubscriptionAndKey($subscriptionGracePeriodSecond, 'google_play_billing_purchase_token')->value
        );
        $this->assertEquals(
            $developerNotificationGracePeriodSecond->id,
            $this->subscriptionMetaRepository->findBySubscriptionAndKey($subscriptionGracePeriodSecond, 'google_play_billing_developer_notification_id')->value
        );
        $this->assertEquals(
            $orderIdGracePeriodSecond,
            $this->subscriptionMetaRepository->findBySubscriptionAndKey($subscriptionGracePeriodSecond, 'google_play_billing_order_id')->value
        );
    }

    // Test paid subscription > grace subscription > paid subscription > grace subscription
    // (eg. repeating grace period every month because of prepaid card...).
    // This test is only checking how many payments/subscriptions and meta were created
    // and than one final check of order. Rest should be checked by previous tests.
    public function testSuccess4SubscriptionsPaidGracePaidGrace()
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
        $this->assertEquals(0, $this->subscriptionMetaRepository->totalCount());

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
        $this->assertEquals(3, $this->subscriptionMetaRepository->totalCount()); // 3 new subscription meta

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

        // orderID is same, only suffix is different
        $orderIdSecondPurchase = $orderIdFirstPurchase . '..1';
        // start of next paid subscription starts after end of grace period
        $startTimeMillisSecondPurchase = $expiryTimeMillisGracePeriod;
        $expiryTimeMillisSecondPurchase = $startTimeMillisSecondPurchase->modifyClone('+1 month');
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
        $this->assertEquals(3, $this->subscriptionMetaRepository->totalCount());

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
        $this->assertEquals(3, $this->subscriptionMetaRepository->totalCount()); // no change

        /* ***************************************************************** *
         * 4.) SECOND GRACE PERIOD NOTIFICATION **************************** *
         * ***************************************************************** */

        $purchaseTokenGracePeriodSecond = $purchaseTokenFirstPurchase;
        $developerNotificationGracePeriodSecond = $this->developerNotificationsRepository->add(
            $purchaseTokenGracePeriodSecond,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_IN_GRACE_PERIOD,
        );
        $hermesMessageGracePeriodSecond = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationGracePeriodSecond->getPrimary(),
            ],
        );

        // orderID is same, only suffix is different
        $orderIdGracePeriodSecond = $orderIdFirstPurchase . '..2';
        // start of subscription within grace period notification is same as start of previous subscription
        $startTimeMillisGracePeriodSecond = $startTimeMillisSecondPurchase;
        // expiry of grace period subscription will be cca 2 days after expiration of previous purchase
        $expiryTimeMillisGracePeriodSecond = $expiryTimeMillisSecondPurchase->modifyClone('+2 days');
        // price doesn't matter now
        $priceAmountMicrosGracePeriodSecond = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        // other settings:
        // - acknowledgementState: set to 1 (acknowledged) so we don't need to mock acknowledgement service
        // - paymentState: set to 0 (payment pending); payment did not go through (that's why grace period was initiated)
        $paymentState = 0;
        // - autoRenewing: still true; grace period was initiated because there is issue with charging,
        //                 but user has still change to switch card / payment option
        $autoRenewing = true;
        $googleResponseGracePeriodSecond = <<<JSON
            {
                "acknowledgementState": 1,
                "autoRenewing": {$autoRenewing},
                "autoResumeTimeMillis": null,
                "cancelReason": null,
                "countryCode": "SK",
                "developerPayload": "",
                "emailAddress": null,
                "expiryTimeMillis": "{$expiryTimeMillisGracePeriodSecond->format('Uv')}",
                "externalAccountId": null,
                "familyName": null,
                "givenName": null,
                "kind": "androidpublisher#subscriptionPurchase",
                "obfuscatedExternalAccountId": "{$this->getUser()->id}",
                "obfuscatedExternalProfileId": null,
                "orderId": "{$orderIdGracePeriodSecond}",
                "paymentState": {$paymentState},
                "priceAmountMicros": "{$priceAmountMicrosGracePeriodSecond}",
                "priceCurrencyCode": "EUR",
                "profileId": null,
                "profileName": null,
                "promotionCode": null,
                "promotionType": null,
                "purchaseType": null,
                "startTimeMillis": "{$startTimeMillisGracePeriodSecond->format('Uv')}",
                "userCancellationTimeMillis": null
            }
        JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseGracePeriodSecond);

        // validate previos state - purchase + grace + purchase
        $this->assertEquals(2, $this->paymentsRepository->totalCount());
        $this->assertEquals(3, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(6, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(3, $this->subscriptionMetaRepository->totalCount());

        // handler returns only bool value; check expected log for result (nothing logged)
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->never())
            ->method('log');
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageGracePeriodSecond);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationGracePeriodSecondUpdated = $this->developerNotificationsRepository->find($developerNotificationGracePeriodSecond->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationGracePeriodSecondUpdated->status
        );

        // NO new payment and NO payment_meta
        // NEW subscription & NEW subscription_meta
        $this->assertEquals(2, $this->paymentsRepository->totalCount()); // no change
        $this->assertEquals(4, $this->subscriptionsRepository->totalCount()); // new subscription
        $this->assertEquals(6, $this->paymentMetaRepository->totalCount()); // no change
        $this->assertEquals(6, $this->subscriptionMetaRepository->totalCount()); // 3 new subscription meta

        /* ***************************************************************** *
         * FINAL CHECK ***************************************************** *
         * ***************************************************************** */

        // check order IDs in payments
        $payments = $this->paymentsRepository->getTable()
            ->where(['status' => PaymentsRepository::STATUS_PREPAID])
            ->order('created_at')
            ->fetchAll();
        $payment1 = reset($payments);
        $payment2 = next($payments);

        $this->assertEquals(
            $orderIdFirstPurchase,
            $this->paymentMetaRepository->findByPaymentAndKey(
                $payment1,
                'google_play_billing_order_id'
            )->value,
        );
        $this->assertEquals(
            $orderIdSecondPurchase,
            $this->paymentMetaRepository->findByPaymentAndKey(
                $payment2,
                'google_play_billing_order_id'
            )->value,
        );

        // check order IDs in subscriptions
        $subscriptions = $this->subscriptionsRepository->getTable()->order('created_at')->fetchAll();
        $subscription1 = reset($subscriptions);
        $subscription2 = next($subscriptions);
        $subscription3 = next($subscriptions);
        $subscription4 = next($subscriptions);

        // regular purchases ($subscription 1 and 3) have order ID stored in payment meta
        $this->assertNull(
            $this->subscriptionMetaRepository->findBySubscriptionAndKey(
                $subscription1,
                'google_play_billing_order_id'
            )
        );
        $this->assertNull(
            $this->subscriptionMetaRepository->findBySubscriptionAndKey(
                $subscription3,
                'google_play_billing_order_id'
            )
        );

        // grace period ($subscription 2 and 4) have order ID stored in subscription meta
        $this->assertEquals(
            $orderIdGracePeriod,
            $this->subscriptionMetaRepository->findBySubscriptionAndKey(
                $subscription2,
                'google_play_billing_order_id'
            )->value
        );
        $this->assertEquals(
            $orderIdGracePeriodSecond,
            $this->subscriptionMetaRepository->findBySubscriptionAndKey(
                $subscription4,
                'google_play_billing_order_id'
            )->value
        );

        // check subscription links to payments
        $payment1FromSubscription1 = $this->paymentsRepository->subscriptionPayment($subscription1);
        $noPaymentFromSubscription2 = $this->paymentsRepository->subscriptionPayment($subscription2);
        $payment2FromSubscription3 = $this->paymentsRepository->subscriptionPayment($subscription3);
        $noPaymentFromSubscription4 = $this->paymentsRepository->subscriptionPayment($subscription4);
        $this->assertEquals($payment1FromSubscription1->id, $payment1->id);
        $this->assertNull($noPaymentFromSubscription2);
        $this->assertEquals($payment2FromSubscription3->id, $payment2->id);
        $this->assertNull($noPaymentFromSubscription4);

        // check order of subscriptions
        $this->assertEquals($subscription1->end_time, $subscription2->start_time);
        $this->assertEquals($subscription2->end_time, $subscription3->start_time);
        $this->assertEquals($subscription3->end_time, $subscription4->start_time);
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

        $orderIdRenewed = $orderIdFirstPurchase . '..1'; // set to 1 to simulate that it was "generated" after grace period
        $startTimeMillisRenewed = $expiryTimeMillisFirstPurchase;
        $expiryTimeMillisRenewed = new DateTime('2030-06-27 19:20:57');
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

        // orderID is same, only suffix is different
        $orderIdGracePeriod = $orderIdFirstPurchase . '..0'; // set to 0 to simulate that it was "generated" after grace period
        // start of subscription within grace period notification is same as start of previous subscription
        $startTimeMillisGracePeriod = $startTimeMillisFirstPurchase;
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
