<?php

namespace Crm\GooglePlayBillingModule\Tests\Hermes;

use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Config\Repository\ConfigCategoriesRepository;
use Crm\ApplicationModule\Config\Repository\ConfigsRepository;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Seeders\ConfigsSeeder as ApplicationConfigsSeeder;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
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
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
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

class DeveloperNotificationReceivedHandlerTest extends DatabaseTestCase
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

    public function testSubscriptionPurchased()
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

        // payment, payment_meta, recurrent payment and subscription created
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(1, $this->recurrentPaymentsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());

        // payment status & subscription_type checks
        $payment = $this->paymentsRepository->getTable()->fetch();
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $payment->status);
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $payment->subscription_type_id);

        // recurrent payment checks
        $recurrent = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(RecurrentPaymentsRepository::STATE_ACTIVE, $recurrent->state);
        $this->assertEquals($purchaseTokenFirstPurchase->purchase_token, $recurrent->cid);

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
    }

    public function testSubscriptionCancelled()
    {
        $orderId = 'GPA.1111-1111-1111-11111';
        $purchaseToken = 'purchase_token_' . Random::generate();
        $startTime = new DateTime('2030-04-27 19:20:57');
        $expiryTime = new DateTime('2030-05-27 19:20:57');
        $cancellationTime = new DateTime('2020-05-20 19:20:57');

        $this->processPurchaseNotification(
            orderId: $orderId,
            purchaseToken: $purchaseToken,
            startTime: $startTime,
            expiryTime: $expiryTime,
        );

        /* ************************************************************ *
         * CANCEL NOTIFICATION ************************** *
         * ************************************************************ */

        // purchase token is same as original purchase
        $purchaseTokenCancelled = $this->purchaseTokensRepository->findByPurchaseToken($purchaseToken);
        $developerNotificationCancelled = $this->developerNotificationsRepository->add(
            $purchaseTokenCancelled,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_CANCELED
        );
        $hermesMessageCancelled = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationCancelled->getPrimary(),
            ],
        );

        $orderIdCancelled = $orderId; // order ID is same as original purchase which should be expired by this change
        $startTimeMillisCancelled = $startTime; // original startTime
        $expiryTimeMillisCancelled = $expiryTime; // expiry of expiration is same as start of upgraded purchase

        $googleResponseCancelled = <<<JSON
{
    "acknowledgementState": 1,
    "autoRenewing": false,
    "autoResumeTimeMillis": null,
    "cancelReason": 0,
    "countryCode": "SK",
    "developerPayload": "",
    "emailAddress": null,
    "expiryTimeMillis": "{$expiryTimeMillisCancelled->format('Uv')}",
    "externalAccountId": null,
    "familyName": null,
    "givenName": null,
    "kind": "androidpublisher#subscriptionPurchase",
    "linkedPurchaseToken": null,
    "obfuscatedExternalAccountId": "{$this->getUser()->id}",
    "obfuscatedExternalProfileId": null,
    "orderId": "{$orderIdCancelled}",
    "paymentState": 1,
    "priceAmountMicros": "10",
    "priceCurrencyCode": "EUR",
    "profileId": null,
    "profileName": null,
    "promotionCode": null,
    "promotionType": null,
    "purchaseType": null,
    "startTimeMillis": "{$startTimeMillisCancelled->format('Uv')}",
    "userCancellationTimeMillis": "{$cancellationTime->format('Uv')}"
}
JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseCancelled);

        // handler returns only bool value; check expected log for result (nothing logged)
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->never())
            ->method('log');
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageCancelled);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationExpiredUpdated = $this->developerNotificationsRepository->find($developerNotificationCancelled->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationExpiredUpdated->status
        );

        // number of payments & subscriptions didn't change
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(1, $this->recurrentPaymentsRepository->totalCount());
        // but new payment_meta is present
        $this->assertEquals(5, $this->paymentMetaRepository->totalCount());

        // just cancelled, no change in payment status
        $payment = $this->paymentsRepository->getTable()->order('created_at')->fetch();
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $payment->status);

        // we stopped recurrent
        $paymentRecurrent = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(RecurrentPaymentsRepository::STATE_USER_STOP, $paymentRecurrent->state);

        $subscription = $payment->subscription;
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscription->subscription_type_id);
        $this->assertEquals($startTime, $payment->subscription_start_at);
        $this->assertEquals($expiryTime, $payment->subscription_end_at);
        $this->assertEquals($startTime, $subscription->start_time);
        // subscription end time didn't change
        $this->assertEquals($expiryTime, $subscription->end_time);
        // old payment meta data are same
        $this->assertEquals(
            $purchaseTokenCancelled->purchase_token,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_purchase_token')->value
        );
        $this->assertEquals(
            $orderId,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_order_id')->value
        );
        $this->assertEquals(
            'cancelled_by_user',
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'cancel_reason')->value
        );
        $this->assertEquals(
            $cancellationTime->format('Y-m-d H:i:s'),
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'cancel_datetime')->value
        );
    }

    public function testSubscriptionRevoked()
    {
        $orderId = 'GPA.1111-1111-1111-11111';
        $purchaseToken = 'purchase_token_' . Random::generate();
        $startTime = new DateTime('2030-04-27 19:20:57');
        $expiryTime = new DateTime('2030-05-27 19:20:57');
        $cancellationTime = new DateTime('2020-05-20 19:20:57');

        $this->processPurchaseNotification(
            orderId: $orderId,
            purchaseToken: $purchaseToken,
            startTime: $startTime,
            expiryTime: $expiryTime,
        );

        /* ************************************************************ *
         * REVOKE NOTIFICATION ************************** *
         * ************************************************************ */

        // purchase token is same as original purchase
        $purchaseTokenCancelled = $this->purchaseTokensRepository->findByPurchaseToken($purchaseToken);
        $developerNotificationCancelled = $this->developerNotificationsRepository->add(
            $purchaseTokenCancelled,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_REVOKED
        );
        $hermesMessageCancelled = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationCancelled->getPrimary(),
            ],
        );

        $orderIdCancelled = $orderId; // order ID is same as original purchase which should be expired by this change
        $startTimeMillisCancelled = $startTime; // original startTime
        $expiryTimeMillisCancelled = $expiryTime; // expiry of expiration is same as start of upgraded purchase

        $googleResponseCancelled = <<<JSON
{
    "acknowledgementState": 1,
    "autoRenewing": false,
    "autoResumeTimeMillis": null,
    "cancelReason": 0,
    "countryCode": "SK",
    "developerPayload": "",
    "emailAddress": null,
    "expiryTimeMillis": "{$expiryTimeMillisCancelled->format('Uv')}",
    "externalAccountId": null,
    "familyName": null,
    "givenName": null,
    "kind": "androidpublisher#subscriptionPurchase",
    "linkedPurchaseToken": null,
    "obfuscatedExternalAccountId": "{$this->getUser()->id}",
    "obfuscatedExternalProfileId": null,
    "orderId": "{$orderIdCancelled}",
    "paymentState": 1,
    "priceAmountMicros": "10",
    "priceCurrencyCode": "EUR",
    "profileId": null,
    "profileName": null,
    "promotionCode": null,
    "promotionType": null,
    "purchaseType": null,
    "startTimeMillis": "{$startTimeMillisCancelled->format('Uv')}",
    "userCancellationTimeMillis": "{$cancellationTime->format('Uv')}"
}
JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseCancelled);

        // handler returns only bool value; check expected log for result (nothing logged)
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->never())
            ->method('log');
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageCancelled);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationExpiredUpdated = $this->developerNotificationsRepository->find($developerNotificationCancelled->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationExpiredUpdated->status
        );

        // number of payments & subscriptions didn't change
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(1, $this->recurrentPaymentsRepository->totalCount());
        // but new payment_metas are present
        $this->assertEquals(5, $this->paymentMetaRepository->totalCount());

        // payment status changed to refund
        $payment = $this->paymentsRepository->getTable()->order('created_at')->fetch();
        $this->assertEquals(PaymentsRepository::STATUS_REFUND, $payment->status);

        // we stopped recurrent
        $paymentRecurrent = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(RecurrentPaymentsRepository::STATE_USER_STOP, $paymentRecurrent->state);

        $subscription = $payment->subscription;
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscription->subscription_type_id);
        $this->assertEquals($startTime, $payment->subscription_start_at);
        $this->assertEquals($expiryTime, $payment->subscription_end_at);
        $this->assertEquals($startTime, $subscription->start_time);
        // subscription end time changed
        $this->assertEquals($expiryTime, $subscription->end_time);
        // old payment meta data are same
        $this->assertEquals(
            $purchaseTokenCancelled->purchase_token,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_purchase_token')->value
        );
        $this->assertEquals(
            $orderId,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_order_id')->value
        );
        $this->assertEquals(
            'cancelled_by_user',
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'cancel_reason')->value
        );
        $this->assertEquals(
            $cancellationTime->format('Y-m-d H:i:s'),
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'cancel_datetime')->value
        );
    }

    public function testSubscriptionRestarted()
    {
        $orderId = 'GPA.1111-1111-1111-11111';
        $purchaseToken = 'purchase_token_' . Random::generate();
        $startTime = new DateTime('2030-04-27 19:20:57');
        $expiryTime = new DateTime('2030-05-27 19:20:57');
        $cancellationTime = new DateTime('2020-05-20 19:20:57');

        $this->processPurchaseNotification(
            orderId: $orderId,
            purchaseToken: $purchaseToken,
            startTime: $startTime,
            expiryTime: $expiryTime,
        );

        $this->processCancelNotification(
            orderId: $orderId,
            purchaseToken: $purchaseToken,
            startTime: $startTime,
            expiryTime: $expiryTime,
            cancellationTime: $cancellationTime,
        );

        /* ************************************************************ *
         * RESTART NOTIFICATION ************************** *
         * ************************************************************ */

        // purchase token is same as original purchase
        $purchaseTokenCancelled = $this->purchaseTokensRepository->findByPurchaseToken($purchaseToken);
        $developerNotificationCancelled = $this->developerNotificationsRepository->add(
            $purchaseTokenCancelled,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_RESTARTED
        );
        $hermesMessageCancelled = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationCancelled->getPrimary(),
            ],
        );

        $orderIdRestarted = $orderId; // order ID is same as original purchase which should be expired by this change
        $startTimeMillisRestarted = $startTime; // original startTime
        $expiryTimeMillisRestarted = $expiryTime; // expiry of expiration is same as start of upgraded purchase

        $googleResponseRestarted = <<<JSON
{
    "acknowledgementState": 1,
    "autoRenewing": false,
    "autoResumeTimeMillis": null,
    "cancelReason": 0,
    "countryCode": "SK",
    "developerPayload": "",
    "emailAddress": null,
    "expiryTimeMillis": "{$expiryTimeMillisRestarted->format('Uv')}",
    "externalAccountId": null,
    "familyName": null,
    "givenName": null,
    "kind": "androidpublisher#subscriptionPurchase",
    "linkedPurchaseToken": null,
    "obfuscatedExternalAccountId": "{$this->getUser()->id}",
    "obfuscatedExternalProfileId": null,
    "orderId": "{$orderIdRestarted}",
    "paymentState": 1,
    "priceAmountMicros": "10",
    "priceCurrencyCode": "EUR",
    "profileId": null,
    "profileName": null,
    "promotionCode": null,
    "promotionType": null,
    "purchaseType": null,
    "startTimeMillis": "{$startTimeMillisRestarted->format('Uv')}",
    "userCancellationTimeMillis": "{$cancellationTime->format('Uv')}"
}
JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseRestarted);

        // handler returns only bool value; check expected log for result (nothing logged)
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->never())
            ->method('log');
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageCancelled);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationExpiredUpdated = $this->developerNotificationsRepository->find($developerNotificationCancelled->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationExpiredUpdated->status
        );

        // number of payments & subscriptions didn't change
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(1, $this->recurrentPaymentsRepository->totalCount());
        // but new payment_meta is present
        $this->assertEquals(6, $this->paymentMetaRepository->totalCount());

        // just restarted, no change in payment status
        $payment = $this->paymentsRepository->getTable()->order('created_at')->fetch();
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $payment->status);

        // we restart recurrent
        $paymentRecurrent = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(RecurrentPaymentsRepository::STATE_ACTIVE, $paymentRecurrent->state);

        $subscription = $payment->subscription;
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscription->subscription_type_id);
        $this->assertEquals($startTime, $payment->subscription_start_at);
        $this->assertEquals($expiryTime, $payment->subscription_end_at);
        $this->assertEquals($startTime, $subscription->start_time);
        // subscription end time didn't change
        $this->assertEquals($expiryTime, $subscription->end_time);
        // old payment meta data are same
        $this->assertEquals(
            $purchaseTokenCancelled->purchase_token,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_purchase_token')->value
        );
        $this->assertEquals(
            $orderId,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_order_id')->value
        );
        $this->assertEquals(
            'cancelled_by_user',
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'cancel_reason')->value
        );
        $this->assertEquals(
            $cancellationTime->format('Y-m-d H:i:s'),
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'cancel_datetime')->value
        );
        $this->assertNotNull(
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'restart_datetime')
        );
    }

    public function testSubscriptionRestartedNoRecurrentPayment()
    {
        $orderId = 'GPA.1111-1111-1111-11111';
        $purchaseToken = 'purchase_token_' . Random::generate();
        $startTime = new DateTime('2030-04-27 19:20:57');
        $expiryTime = new DateTime('2030-05-27 19:20:57');
        $cancellationTime = new DateTime('2020-05-20 19:20:57');

        $this->processPurchaseNotification(
            orderId: $orderId,
            purchaseToken: $purchaseToken,
            startTime: $startTime,
            expiryTime: $expiryTime,
        );

        $this->processCancelNotification(
            orderId: $orderId,
            purchaseToken: $purchaseToken,
            startTime: $startTime,
            expiryTime: $expiryTime,
            cancellationTime: $cancellationTime,
        );

        // Mock there is no recurrent payment created
        $this->recurrentPaymentsRepository->getTable()->delete();

        $this->assertEquals(0, $this->recurrentPaymentsRepository->totalCount());

        /* ************************************************************ *
         * RESTART NOTIFICATION ************************** *
         * ************************************************************ */

        // purchase token is same as original purchase
        $purchaseTokenCancelled = $this->purchaseTokensRepository->findByPurchaseToken($purchaseToken);
        $developerNotificationCancelled = $this->developerNotificationsRepository->add(
            $purchaseTokenCancelled,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_RESTARTED
        );
        $hermesMessageCancelled = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationCancelled->getPrimary(),
            ],
        );

        $orderIdRestarted = $orderId; // order ID is same as original purchase which should be expired by this change
        $startTimeMillisRestarted = $startTime; // original startTime
        $expiryTimeMillisRestarted = $expiryTime; // expiry of expiration is same as start of upgraded purchase

        $googleResponseRestarted = <<<JSON
{
    "acknowledgementState": 1,
    "autoRenewing": false,
    "autoResumeTimeMillis": null,
    "cancelReason": 0,
    "countryCode": "SK",
    "developerPayload": "",
    "emailAddress": null,
    "expiryTimeMillis": "{$expiryTimeMillisRestarted->format('Uv')}",
    "externalAccountId": null,
    "familyName": null,
    "givenName": null,
    "kind": "androidpublisher#subscriptionPurchase",
    "linkedPurchaseToken": null,
    "obfuscatedExternalAccountId": "{$this->getUser()->id}",
    "obfuscatedExternalProfileId": null,
    "orderId": "{$orderIdRestarted}",
    "paymentState": 1,
    "priceAmountMicros": "10",
    "priceCurrencyCode": "EUR",
    "profileId": null,
    "profileName": null,
    "promotionCode": null,
    "promotionType": null,
    "purchaseType": null,
    "startTimeMillis": "{$startTimeMillisRestarted->format('Uv')}",
    "userCancellationTimeMillis": "{$cancellationTime->format('Uv')}"
}
JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponseRestarted);

        // handler returns only bool value; check expected log for result (nothing logged)
        $mockLogger = $this->createMock(ILogger::class);
        Debugger::setLogger($mockLogger);

        $result = $this->developerNotificationReceivedHandler->handle($hermesMessageCancelled);
        $this->assertTrue($result);

        // developer notification marked as processed
        $developerNotificationExpiredUpdated = $this->developerNotificationsRepository->find($developerNotificationCancelled->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationExpiredUpdated->status
        );

        // number of payments & subscriptions didn't change
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(1, $this->recurrentPaymentsRepository->totalCount());
        // but new payment_meta is present
        $this->assertEquals(6, $this->paymentMetaRepository->totalCount());

        // just restarted, no change in payment status
        $payment = $this->paymentsRepository->getTable()->order('created_at')->fetch();
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $payment->status);

        // we restart recurrent
        $paymentRecurrent = $this->recurrentPaymentsRepository->recurrent($payment);
        $this->assertEquals(RecurrentPaymentsRepository::STATE_ACTIVE, $paymentRecurrent->state);

        $subscription = $payment->subscription;
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscription->subscription_type_id);
        $this->assertEquals($startTime, $payment->subscription_start_at);
        $this->assertEquals($expiryTime, $payment->subscription_end_at);
        $this->assertEquals($startTime, $subscription->start_time);
        // subscription end time didn't change
        $this->assertEquals($expiryTime, $subscription->end_time);
        // old payment meta data are same
        $this->assertEquals(
            $purchaseTokenCancelled->purchase_token,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_purchase_token')->value
        );
        $this->assertEquals(
            $orderId,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_order_id')->value
        );
        $this->assertEquals(
            'cancelled_by_user',
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'cancel_reason')->value
        );
        $this->assertEquals(
            $cancellationTime->format('Y-m-d H:i:s'),
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'cancel_datetime')->value
        );
        $this->assertNotNull(
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'restart_datetime')
        );
    }

    /* **************************************************************** *
     * HELPER METHODS
     * **************************************************************** */

    private function processPurchaseNotification(
        string $orderId,
        string $purchaseToken,
        ?DateTime $startTime = null,
        ?DateTime $expiryTime = null,
    ): void {
        $startTime ??= new DateTime('2030-04-27 19:20:57');
        $expiryTime ??= new DateTime('2030-05-27 19:20:57');

        $purchaseTokenPurchase = $this->purchaseTokensRepository->add(
            $purchaseToken,
            $this->googlePlayPackage,
            $this->getGooglePlaySubscriptionTypeWeb()->subscription_id
        );
        $developerNotificationPurchase = $this->developerNotificationsRepository->add(
            $purchaseTokenPurchase,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED
        );
        $hermesMessageFirstPurchase = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationPurchase->getPrimary(),
            ],
        );

        $priceAmountMicrosPurchase = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        $googleResponsePurchase = <<<JSON
{
    "acknowledgementState": 1,
    "autoRenewing": true,
    "autoResumeTimeMillis": null,
    "cancelReason": null,
    "countryCode": "SK",
    "developerPayload": "",
    "emailAddress": null,
    "expiryTimeMillis": "{$expiryTime->format('Uv')}",
    "externalAccountId": null,
    "familyName": null,
    "givenName": null,
    "kind": "androidpublisher#subscriptionPurchase",
    "linkedPurchaseToken": null,
    "obfuscatedExternalAccountId": "{$this->getUser()->id}",
    "obfuscatedExternalProfileId": null,
    "orderId": "{$orderId}",
    "paymentState": 1,
    "priceAmountMicros": "{$priceAmountMicrosPurchase}",
    "priceCurrencyCode": "EUR",
    "profileId": null,
    "profileName": null,
    "promotionCode": null,
    "promotionType": null,
    "purchaseType": null,
    "startTimeMillis": "{$startTime->format('Uv')}",
    "userCancellationTimeMillis": null
}
JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponsePurchase);

        $this->developerNotificationReceivedHandler->handle($hermesMessageFirstPurchase);
    }

    private function processCancelNotification(
        string $orderId,
        string $purchaseToken,
        ?DateTime $startTime = null,
        ?DateTime $expiryTime = null,
        ?DateTime $cancellationTime = null,
    ): void {
        $startTime ??= new DateTime('2030-04-27 19:20:57');
        $expiryTime ??= new DateTime('2030-05-27 19:20:57');
        $cancellationTime ??= new DateTime('2020-05-20 19:20:57');

        $purchaseTokenPurchase = $this->purchaseTokensRepository->add(
            $purchaseToken,
            $this->googlePlayPackage,
            $this->getGooglePlaySubscriptionTypeWeb()->subscription_id
        );
        $developerNotificationPurchase = $this->developerNotificationsRepository->add(
            $purchaseTokenPurchase,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_CANCELED
        );
        $hermesMessageFirstPurchase = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationPurchase->getPrimary(),
            ],
        );

        $priceAmountMicrosPurchase = $this->getGooglePlaySubscriptionTypeWeb()->subscription_type->price * 1000000;
        $googleResponsePurchase = <<<JSON
{
    "acknowledgementState": 1,
    "autoRenewing": true,
    "autoResumeTimeMillis": null,
    "cancelReason": null,
    "countryCode": "SK",
    "developerPayload": "",
    "emailAddress": null,
    "expiryTimeMillis": "{$expiryTime->format('Uv')}",
    "externalAccountId": null,
    "familyName": null,
    "givenName": null,
    "kind": "androidpublisher#subscriptionPurchase",
    "linkedPurchaseToken": null,
    "obfuscatedExternalAccountId": "{$this->getUser()->id}",
    "obfuscatedExternalProfileId": null,
    "orderId": "{$orderId}",
    "paymentState": 1,
    "priceAmountMicros": "{$priceAmountMicrosPurchase}",
    "priceCurrencyCode": "EUR",
    "profileId": null,
    "profileName": null,
    "promotionCode": null,
    "promotionType": null,
    "purchaseType": null,
    "startTimeMillis": "{$startTime->format('Uv')}",
    "userCancellationTimeMillis": "{$cancellationTime->format('Uv')}"
}
JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponsePurchase);

        $this->developerNotificationReceivedHandler->handle($hermesMessageFirstPurchase);
    }


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
