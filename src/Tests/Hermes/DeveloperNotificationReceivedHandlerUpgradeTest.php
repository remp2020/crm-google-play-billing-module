<?php

namespace Crm\GooglePlayBillingModule\Tests\Hermes;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\GooglePlayBillingModule\Hermes\DeveloperNotificationReceivedHandler;
use Crm\GooglePlayBillingModule\Model\GooglePlayValidatorFactory;
use Crm\GooglePlayBillingModule\Model\SubscriptionResponseProcessor;
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

    private DeveloperNotificationsRepository $developerNotificationsRepository;

    private GooglePlaySubscriptionTypesRepository $googlePlaySubscriptionTypesRepository;

    private PurchaseTokensRepository $purchaseTokensRepository;

    private PaymentsRepository $paymentsRepository;

    private PaymentMetaRepository $paymentMetaRepository;

    private SubscriptionTypesRepository $subscriptionTypesRepository;

    private SubscriptionTypeBuilder $subscriptionTypeBuilder;

    private SubscriptionsRepository $subscriptionsRepository;

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

        $this->developerNotificationsRepository = $this->getRepository(DeveloperNotificationsRepository::class);
        $this->googlePlaySubscriptionTypesRepository = $this->getRepository(GooglePlaySubscriptionTypesRepository::class);
        $this->purchaseTokensRepository = $this->getRepository(PurchaseTokensRepository::class);

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->paymentMetaRepository = $this->getRepository(PaymentMetaRepository::class);
        $this->subscriptionTypesRepository = $this->getRepository(SubscriptionTypesRepository::class);
        $this->subscriptionTypeBuilder = $this->inject(SubscriptionTypeBuilder::class);
        $this->subscriptionsRepository = $this->getRepository(SubscriptionsRepository::class);
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

    public function testCurrentIncorrectState()
    {
        /* ************************************************************ *
         * FIRST PURCHASE ********************************************* *
         * ************************************************************ */

        $purchaseTokenFirstPurchase = $this->purchaseTokensRepository->add(
            md5('random'),
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
        // acknowledgementState: 1 -> set to acknowledged so we don't need to mock acknowledgement service
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

        $developerNotificationReceivedHandler = $this->getDeveloperNotificationReceivedHandlerWithMocks($googleResponseFirstPurchase);

        $this->assertEquals(0, $this->paymentsRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(0, $this->paymentMetaRepository->totalCount());

        $res = $developerNotificationReceivedHandler->handle($hermesMessageFirstPurchase);
        $this->assertTrue($res);

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

        // payment status & subscription_type checks
        $payment = $this->paymentsRepository->getTable()->fetch();
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $payment->status);
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $payment->subscription_type_id);

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
            md5('random'),
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

        $developerNotificationReceivedHandler = $this->getDeveloperNotificationReceivedHandlerWithMocks($googleResponseUpgradePurchase);

        // state from original notification - first purchase
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());

        // handler returns only bool value; check expected log for result
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->once())
            ->method('log')
            ->with(
                $this->matches('Processing stopped, no further attempts. Reason: [Payment with same purchase token and end datetime already exists.]'),
                $this->matches(DeveloperNotificationReceivedHandler::INFO_LOG_LEVEL)
            );
        Debugger::setLogger($mockLogger);

        $res = $developerNotificationReceivedHandler->handle($hermesMessageUpgradePurchase);
        $this->assertFalse($res);

        // developer notification status changed to do_not_retry
        $developerNotificationUpgradePurchaseUpdated = $this->developerNotificationsRepository->find($developerNotificationUpgradePurchase->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_DO_NOT_RETRY,
            $developerNotificationUpgradePurchaseUpdated->status
        );

        // no change
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());

        $payment = $this->paymentsRepository->getTable()->fetch();
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $payment->status);

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

        $developerNotificationReceivedHandler = $this->getDeveloperNotificationReceivedHandlerWithMocks($googleResponseExpired);

        // state from original notification - first purchase; nothing changed yet
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());

        // handler returns only bool value; check expected log for result
        $mockLogger = $this->createMock(ILogger::class);
        $mockLogger->expects($this->once())
            ->method('log')
            ->with(
                $this->matches("Nothing done with DeveloperNotification ID: [{$developerNotificationExpired->id}]. Reason: [Notification type 13 - SUBSCRIPTION_EXPIRED not handled.]"),
                $this->matches(DeveloperNotificationReceivedHandler::INFO_LOG_LEVEL)
            );
        Debugger::setLogger($mockLogger);

        $res = $developerNotificationReceivedHandler->handle($hermesMessageExpired);
        $this->assertTrue($res);

        // developer notification marked as processed (because it was processed, we just didn't change anything)
        $developerNotificationExpiredUpdated = $this->developerNotificationsRepository->find($developerNotificationExpired->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationExpiredUpdated->status
        );

        // still no change; no new payment / subscription
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());

        // we didn't cancel existing payment
        $payment = $this->paymentsRepository->getTable()->fetch();
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $payment->subscription_type_id);
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $payment->status);

        // and subscription has same subscription type, start and end times as original purchase / original validation response
        $subscription = $this->subscriptionsRepository->getTable()->fetch();
        $this->assertEquals($this->getGooglePlaySubscriptionTypeWeb()->subscription_type_id, $subscription->subscription_type_id);
        $this->assertEquals($startTimeMillisFirstPurchase, $subscription->start_time);
        $this->assertEquals($expiryTimeMillisFirstPurchase, $subscription->end_time);
        $this->assertEquals($subscription->id, $payment->subscription_id);
    }

    /* **************************************************************** *
     * HELPER METHODS
     * **************************************************************** */

    private function getDeveloperNotificationReceivedHandlerWithMocks(string $expectedGoogleValidatorSubscriptionResponse)
    {
        /** @var Validator|MockInterface $googlePlayValidatorMocked */
        $googlePlayValidatorMocked = Mockery::mock(Validator::class);
        $googlePlayValidatorMocked
            ->shouldReceive('setPackageName->setPurchaseToken->setProductId->validateSubscription')
            ->andReturn(new SubscriptionResponse(new SubscriptionPurchase(
                Json::decode($expectedGoogleValidatorSubscriptionResponse, Json::FORCE_ARRAY)
            )))
            ->getMock();

        /** @var GooglePlayValidatorFactory|MockInterface $googlePlayValidatorFactoryMocked */
        $googlePlayValidatorFactoryMocked = Mockery::mock(GooglePlayValidatorFactory::class)
            ->shouldReceive('create')
            ->andReturn($googlePlayValidatorMocked)
            ->getMock();

        return new DeveloperNotificationReceivedHandler(
            $this->inject(SubscriptionResponseProcessor::class),
            $this->developerNotificationsRepository,
            $this->inject(GooglePlaySubscriptionTypesRepository::class),
            $googlePlayValidatorFactoryMocked,
            $this->inject(PaymentGatewaysRepository::class),
            $this->inject(PaymentMetaRepository::class),
            $this->inject(PaymentsRepository::class),
            $this->inject(SubscriptionsRepository::class),
            $this->inject(SubscriptionMetaRepository::class)
        );
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
