<?php

namespace Crm\GooglePlayBillingModule\Tests\Hermes;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\ApplicationModule\Repositories\ConfigCategoriesRepository;
use Crm\ApplicationModule\Repositories\ConfigsRepository;
use Crm\ApplicationModule\Seeders\ConfigsSeeder as ApplicationConfigsSeeder;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\GooglePlayBillingModule\Hermes\DeveloperNotificationReceivedHandler;
use Crm\GooglePlayBillingModule\Repositories\DeveloperNotificationsRepository;
use Crm\GooglePlayBillingModule\Repositories\GooglePlaySubscriptionTypesRepository;
use Crm\GooglePlayBillingModule\Repositories\PurchaseDeviceTokensRepository;
use Crm\GooglePlayBillingModule\Repositories\PurchaseTokensRepository;
use Crm\GooglePlayBillingModule\Seeders\PaymentGatewaysSeeder;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\PaymentStatusChangeHandler;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\RecurrentPayment\RecurrentPaymentStateEnum;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentItemMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentItemsRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\PaymentsModule\Seeders\ConfigsSeeder as PaymentsConfigsSeeder;
use Crm\SubscriptionsModule\Models\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypeNamesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\SubscriptionsModule\Seeders\ConfigSeeder as SubscriptionsConfigSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repositories\UsersRepository;
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

class DeveloperNotificationReceivedHandlerUpgradeTest extends DatabaseTestCase
{
    private string $googlePlayPackage = 'test.package';
    private ?ActiveRow $googlePlaySubscriptionTypeWeb = null;
    private ?ActiveRow $googlePlaySubscriptionTypeStandard = null;
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

    private UsersRepository $usersRepository;

    private RecurrentPaymentsRepository $recurrentPaymentsRepository;

    private ApplicationConfig $applicationConfig;

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
            RecurrentPaymentsRepository::class,
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
            ApplicationConfigsSeeder::class,
            PaymentsConfigsSeeder::class,
            SubscriptionsConfigSeeder::class,
        ];
    }

    //public static function setUpBeforeClass(): void
    //{
    //    self::$googlePlayValidatorFactoryMocked = Mockery::mock(GooglePlayValidatorFactory::class);
    //}

    public function setUp(): void
    {
        parent::setUp();

        $this->applicationConfig = $this->inject(ApplicationConfig::class);

        $this->developerNotificationReceivedHandler = $this->inject(DeveloperNotificationReceivedHandler::class);

        $this->developerNotificationsRepository = $this->getRepository(DeveloperNotificationsRepository::class);
        $this->googlePlaySubscriptionTypesRepository = $this->getRepository(GooglePlaySubscriptionTypesRepository::class);
        $this->purchaseTokensRepository = $this->getRepository(PurchaseTokensRepository::class);

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->paymentMetaRepository = $this->getRepository(PaymentMetaRepository::class);
        $this->subscriptionTypesRepository = $this->getRepository(SubscriptionTypesRepository::class);
        $this->subscriptionTypeBuilder = $this->inject(SubscriptionTypeBuilder::class);
        $this->subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);
        $this->usersRepository = $this->getRepository(UsersRepository::class);
        $this->recurrentPaymentsRepository = $this->getRepository(RecurrentPaymentsRepository::class);


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

    public function testSuccess()
    {
        /* ************************************************************ *
         * FIRST PURCHASE ********************************************* *
         * ************************************************************ */
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

        $this->assertEquals(0, $this->paymentsRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(0, $this->paymentMetaRepository->totalCount());
        $this->assertEquals(0, $this->recurrentPaymentsRepository->totalCount());

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
        $this->assertEquals(1, $this->recurrentPaymentsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());

        // payment status & subscription_type checks
        $payment = $this->paymentsRepository->getTable()->fetch();
        $this->assertEquals(PaymentStatusEnum::Prepaid->value, $payment->status);
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $payment->subscription_type_id);

        $recurrent = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(RecurrentPaymentStateEnum::Active->value, $recurrent->state);
        $this->assertEquals($purchaseTokenFirstPurchase->purchase_token, $recurrent->payment_method->external_token);

        // check payment meta against order id and purchase token
        $this->assertEquals(
            $purchaseTokenFirstPurchase->purchase_token,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_purchase_token')->value
        );
        $this->assertEquals(
            $developerNotificationFirstPurchase->id,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_developer_notification_id')->value
        );
        $this->assertEquals(
            $orderIdFirstPurchase,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_order_id')->value
        );

        // check subscription type & start/end times against Google play validation response
        $subscription = $this->subscriptionsRepository->getTable()->fetch();
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscription->subscription_type_id);
        $this->assertEquals($startTimeMillisFirstPurchase, $payment->subscription_start_at);
        $this->assertEquals($expiryTimeMillisFirstPurchase, $payment->subscription_end_at);
        $this->assertEquals($startTimeMillisFirstPurchase, $subscription->start_time);
        $this->assertEquals($expiryTimeMillisFirstPurchase, $subscription->end_time);
        $this->assertEquals($subscription->id, $payment->subscription_id);

        /* ************************************************************ *
         * UPGRADE - NEW PURCHASE NOTIFICATION ************************ *
         *  New purchase is sent before EXPIRED notification; which     *
         *  complicates processing but we have to work with this state. *
         * ************************************************************ */

        $purchaseTokenUpgradePurchase = $this->purchaseTokensRepository->add(
            'purchase_token_' . Random::generate(),
            $this->googlePlayPackage,
            $this->getGooglePlaySubscriptionTypeStandard()->subscription_id // upgraded subscription
        );
        $developerNotificationUpgradePurchase = $this->developerNotificationsRepository->add(
            $purchaseTokenUpgradePurchase,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED
        );
        $hermesMessageUpgradePurchase = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationUpgradePurchase->getPrimary(),
            ],
        );

        $orderIdUpgradePurchase = 'GPA.2222-2222-2222-22222'; // order ID of new purchase is different than first purchase
        $startTimeMillisUpgradePurchase = new DateTime('2030-05-02 15:18:11');
        $expiryTimeMillisUpgradePurchase = $expiryTimeMillisFirstPurchase; // expiry of upgraded purchase is same as expiry of original purchase
        $linkedPurchaseTokenUpgradePurchase = $purchaseTokenFirstPurchase->purchase_token; // linked token links to original purchase
        $priceAmountMicrosUpgradePurchase = $this->getGooglePlaySubscriptionTypeStandard()->subscription_type->price * 1000000;
        // acknowledgementState: set to acknowledged so we don't need to mock acknowledgement service
        $googleResponseUpgradePurchase = <<<JSON
{
    "acknowledgementState": 1,
    "autoRenewing": true,
    "autoResumeTimeMillis": null,
    "cancelReason": null,
    "countryCode": "SK",
    "developerPayload": "",
    "emailAddress": null,
    "expiryTimeMillis": "{$expiryTimeMillisUpgradePurchase->format('Uv')}",
    "externalAccountId": null,
    "familyName": null,
    "givenName": null,
    "kind": "androidpublisher#subscriptionPurchase",
    "linkedPurchaseToken": "{$linkedPurchaseTokenUpgradePurchase}",
    "obfuscatedExternalAccountId": "{$this->getUser()->id}",
    "obfuscatedExternalProfileId": null,
    "orderId": "{$orderIdUpgradePurchase}",
    "paymentState": 1,
    "priceAmountMicros": "{$priceAmountMicrosUpgradePurchase}",
    "priceCurrencyCode": "EUR",
    "profileId": null,
    "profileName": null,
    "promotionCode": null,
    "promotionType": null,
    "purchaseType": null,
    "startTimeMillis": "{$startTimeMillisUpgradePurchase->format('Uv')}",
    "userCancellationTimeMillis": null
}
JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseUpgradePurchase);

        // state from original notification - first purchase
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(1, $this->recurrentPaymentsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());

        // handler returns only bool value; check expected log for result (nothing logged)
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->never())
            ->method('log');
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageUpgradePurchase);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationUpgradePurchaseUpdated = $this->developerNotificationsRepository->find($developerNotificationUpgradePurchase->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationUpgradePurchaseUpdated->status
        );

        // new payment, subscription & payment_meta
        $this->assertEquals(2, $this->paymentsRepository->totalCount());
        $this->assertEquals(2, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(2, $this->recurrentPaymentsRepository->totalCount());
        $this->assertEquals(6, $this->paymentMetaRepository->totalCount());

        $payments = $this->paymentsRepository->getTable()->order('created_at')->fetchAll();
        $paymentFirstPurchase = reset($payments);
        $paymentUpgradePurchase = next($payments);

        // check first payment & meta against original purchase
        $this->assertEquals(PaymentStatusEnum::Prepaid->value, $paymentFirstPurchase->status);
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $paymentFirstPurchase->subscription_type_id);
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

        // recurrent still active
        $paymentFirstPurchaseRecurrent = $this->recurrentPaymentsRepository->recurrent($paymentFirstPurchase);
        $this->assertEquals(RecurrentPaymentStateEnum::Active->value, $paymentFirstPurchaseRecurrent->state);
        $this->assertEquals($purchaseTokenFirstPurchase->purchase_token, $paymentFirstPurchaseRecurrent->payment_method->external_token);

        // check new payment (upgrade) & meta against upgrade purchase
        $this->assertEquals(PaymentStatusEnum::Prepaid->value, $paymentUpgradePurchase->status);
        $this->assertEquals($this->getGooglePlaySubscriptionTypeStandard()->subscription_type_id, $paymentUpgradePurchase->subscription_type_id);
        $this->assertEquals(
            $purchaseTokenUpgradePurchase->purchase_token,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentUpgradePurchase, 'google_play_billing_purchase_token')->value
        );
        $this->assertEquals(
            $developerNotificationUpgradePurchase->id,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentUpgradePurchase, 'google_play_billing_developer_notification_id')->value
        );
        $this->assertEquals(
            $orderIdUpgradePurchase,
            $this->paymentMetaRepository->findByPaymentAndKey($paymentUpgradePurchase, 'google_play_billing_order_id')->value
        );

        $paymentUpgradePurchaseRecurrent = $this->recurrentPaymentsRepository->recurrent($paymentUpgradePurchase);
        $this->assertEquals(RecurrentPaymentStateEnum::Active->value, $paymentUpgradePurchaseRecurrent->state);
        $this->assertEquals($purchaseTokenUpgradePurchase->purchase_token, $paymentUpgradePurchaseRecurrent->payment_method->external_token);

        // check subscriptions type & start/end times against Google play validation responses
        $subscriptionFirstPurchase = $paymentFirstPurchase->subscription;
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscriptionFirstPurchase->subscription_type_id);
        $this->assertEquals($startTimeMillisFirstPurchase, $paymentFirstPurchase->subscription_start_at);
        $this->assertEquals($expiryTimeMillisFirstPurchase, $paymentFirstPurchase->subscription_end_at);
        $this->assertEquals($startTimeMillisFirstPurchase, $subscriptionFirstPurchase->start_time);
        $this->assertEquals($expiryTimeMillisFirstPurchase, $subscriptionFirstPurchase->end_time);

        $subscriptionUpgradePurchase = $paymentUpgradePurchase->subscription;
        $this->assertEquals($this->getGooglePlaySubscriptionTypeStandard()->subscription_type_id, $subscriptionUpgradePurchase->subscription_type_id);
        $this->assertEquals($startTimeMillisUpgradePurchase, $paymentUpgradePurchase->subscription_start_at);
        $this->assertEquals($expiryTimeMillisUpgradePurchase, $paymentUpgradePurchase->subscription_end_at);
        $this->assertEquals($startTimeMillisUpgradePurchase, $subscriptionUpgradePurchase->start_time);
        $this->assertEquals($expiryTimeMillisUpgradePurchase, $subscriptionUpgradePurchase->end_time);

        /* ************************************************************ *
         * UPGRADE - EXPIRATION NOTIFICATION ************************** *
         *  This notification is received after new (upgrade) purchase. *
         * ************************************************************ */

        // purchase token is same as original purchase
        $purchaseTokenExpired = $this->purchaseTokensRepository->findByPurchaseToken($purchaseTokenFirstPurchase->purchase_token);
        $developerNotificationExpired = $this->developerNotificationsRepository->add(
            $purchaseTokenExpired,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_EXPIRED
        );
        $hermesMessageExpired = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationExpired->getPrimary(),
            ],
        );

        $orderIdExpired = $orderIdFirstPurchase; // order ID is same as original purchase which should be expired by this change
        $startTimeMillisExpired = $startTimeMillisFirstPurchase; // original startTime
        $expiryTimeMillisExpired = $startTimeMillisUpgradePurchase; // expiry of expiration is same as start of upgraded purchase
        $priceAmountMicrosExpired = $priceAmountMicrosFirstPurchase; // price of original purchase
        // acknowledgementState: set to acknowledged so we don't need to mock acknowledgement service
        // autoRenewing: changed to false from original purchase's true
        // linkedPurchaseToken: null
        $googleResponseExpired = <<<JSON
{
    "acknowledgementState": 1,
    "autoRenewing": false,
    "autoResumeTimeMillis": null,
    "cancelReason": 2,
    "countryCode": "SK",
    "developerPayload": "",
    "emailAddress": null,
    "expiryTimeMillis": "{$expiryTimeMillisExpired->format('Uv')}",
    "externalAccountId": null,
    "familyName": null,
    "givenName": null,
    "kind": "androidpublisher#subscriptionPurchase",
    "linkedPurchaseToken": null,
    "obfuscatedExternalAccountId": "{$this->getUser()->id}",
    "obfuscatedExternalProfileId": null,
    "orderId": "{$orderIdExpired}",
    "paymentState": 1,
    "priceAmountMicros": "{$priceAmountMicrosExpired}",
    "priceCurrencyCode": "EUR",
    "profileId": null,
    "profileName": null,
    "promotionCode": null,
    "promotionType": null,
    "purchaseType": null,
    "startTimeMillis": "{$startTimeMillisExpired->format('Uv')}",
    "userCancellationTimeMillis": null
}
JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseExpired);

        // handler returns only bool value; check expected log for result (nothing logged)
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->never())
            ->method('log');
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageExpired);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationExpiredUpdated = $this->developerNotificationsRepository->find($developerNotificationExpired->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationExpiredUpdated->status
        );

        // number of payments & subscriptions didn't change
        $this->assertEquals(2, $this->paymentsRepository->totalCount());
        $this->assertEquals(2, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(2, $this->recurrentPaymentsRepository->totalCount());
        // but new payment_meta is present
        $this->assertEquals(7, $this->paymentMetaRepository->totalCount());

        $payments = $this->paymentsRepository->getTable()->order('created_at')->fetchAll();
        $paymentFirstPurchase = reset($payments);
        $paymentUpgradePurchase = next($payments);

        // we cancelled first subscription (original purchase)
        $this->assertEquals(PaymentStatusEnum::Prepaid->value, $paymentFirstPurchase->status);

        // we stopped first recurrent
        $paymentFirstPurchaseRecurrent = $this->recurrentPaymentsRepository->recurrent($paymentFirstPurchase);
        $this->assertEquals(RecurrentPaymentStateEnum::UserStop->value, $paymentFirstPurchaseRecurrent->state);

        $subscriptionFirstPurchase = $paymentFirstPurchase->subscription;
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscriptionFirstPurchase->subscription_type_id);
        $this->assertEquals($startTimeMillisFirstPurchase, $paymentFirstPurchase->subscription_start_at);
        $this->assertEquals($expiryTimeMillisFirstPurchase, $paymentFirstPurchase->subscription_end_at);
        $this->assertEquals($startTimeMillisFirstPurchase, $subscriptionFirstPurchase->start_time);
        // end time of original subscription changed:
        // - it's not not same as original google response
        // - it's set to same datetime as start of upgrade purchase
        $this->assertNotEquals($expiryTimeMillisFirstPurchase, $subscriptionFirstPurchase->end_time);
        $this->assertEquals($startTimeMillisUpgradePurchase, $subscriptionFirstPurchase->end_time);
        // old payment meta data are same
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
        // there is one new meta data
        $this->assertEquals(
            'replaced_by_new_subscription',
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchase, 'cancel_reason')->value
        );
        // but cancel datetime (usually sent with cancel_reason) is not set for expiration notification after upgrade
        $this->assertNull(
            $this->paymentMetaRepository->findByPaymentAndKey($paymentFirstPurchase, 'cancel_datetime')
        );

        // but we didn't touch second (upgrade purchase)
        $this->assertEquals(PaymentStatusEnum::Prepaid->value, $paymentUpgradePurchase->status);

        // didn't touch recurrent either
        $paymentUpgradePurchaseRecurrent = $this->recurrentPaymentsRepository->recurrent($paymentUpgradePurchase);
        $this->assertEquals(RecurrentPaymentStateEnum::Active->value, $paymentUpgradePurchaseRecurrent->state);

        $subscriptionUpgradePurchase = $paymentUpgradePurchase->subscription;
        $this->assertEquals($this->getGooglePlaySubscriptionTypeStandard()->subscription_type_id, $subscriptionUpgradePurchase->subscription_type_id);
        $this->assertEquals($startTimeMillisUpgradePurchase, $paymentUpgradePurchase->subscription_start_at);
        $this->assertEquals($expiryTimeMillisUpgradePurchase, $paymentUpgradePurchase->subscription_end_at);
        $this->assertEquals($startTimeMillisUpgradePurchase, $subscriptionUpgradePurchase->start_time);
        $this->assertEquals($expiryTimeMillisUpgradePurchase, $subscriptionUpgradePurchase->end_time);
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

    private function getGooglePlaySubscriptionTypeStandard(): ActiveRow
    {
        if ($this->googlePlaySubscriptionTypeStandard !== null) {
            return $this->googlePlaySubscriptionTypeStandard;
        }

        $googlePlaySubscriptionIdStandard = 'test.package.dennikn.test.standard';
        $subscriptionTypeCodeStandard = 'google_inapp_standard';

        $subscriptionTypeStandard = $this->subscriptionTypesRepository->findByCode($subscriptionTypeCodeStandard);
        if (!$subscriptionTypeStandard) {
            $subscriptionTypeStandard = $this->subscriptionTypeBuilder->createNew()
                ->setName('Google Pay test subscription STANDARD month')
                ->setUserLabel('Google Pay test subscription STANDARD month')
                ->setPrice(8.99)
                ->setCode($subscriptionTypeCodeStandard)
                ->setLength(31)
                ->setActive(true)
                ->save();
        }
        $this->googlePlaySubscriptionTypeStandard = $this->googlePlaySubscriptionTypesRepository->findByGooglePlaySubscriptionId($googlePlaySubscriptionIdStandard);
        if (!$this->googlePlaySubscriptionTypeStandard) {
            $this->googlePlaySubscriptionTypeStandard = $this->googlePlaySubscriptionTypesRepository->add(
                $googlePlaySubscriptionIdStandard,
                $subscriptionTypeStandard
            );
        }
        return $this->googlePlaySubscriptionTypeStandard;
    }
}
