<?php

namespace Crm\GooglePlayBillingModule\Tests\Hermes;

use Crm\ApplicationModule\Config\Repository\ConfigCategoriesRepository;
use Crm\ApplicationModule\Config\Repository\ConfigsRepository;
use Crm\ApplicationModule\Hermes\HermesMessage;
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

class DeveloperNotificationReceivedHandlerOfferPeriodsTest extends DatabaseTestCase
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

    // Make sure everything works fine so we don't have to test it everytime.
    public function testFirstPurchaseSuccess()
    {
        $this->assertEquals(0, $this->paymentsRepository->totalCount());
        $this->assertEquals(0, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(0, $this->paymentMetaRepository->totalCount());

        $purchaseToken = 'purchase_token_' . Random::generate();
        $orderId = 'GPA.1111-1111-1111-11111';
        $googleSubscriptionType = $this->getGooglePlaySubscriptionTypeWeb();
        $startTimeMillisPurchase = new DateTime('2030-04-27 19:20:57');
        $expiryTimeMillisPurchase = new DateTime('2030-05-27 19:20:57');

        $developerNotificationFirstPurchase = $this->preparePayment($purchaseToken, $orderId, $startTimeMillisPurchase, $expiryTimeMillisPurchase, $googleSubscriptionType);

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
        $this->assertEquals($googleSubscriptionType->subscription_type_id, $payment->subscription_type_id);

        // check payment meta against order id and purchase token
        $purchaseTokenFirstPurchase = $this->purchaseTokensRepository->findByPurchaseToken($purchaseToken);
        $this->assertEquals(
            $purchaseTokenFirstPurchase->purchase_token,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_purchase_token')->value
        );
        $this->assertEquals(
            $developerNotificationFirstPurchase->id,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_developer_notification_id')->value
        );
        $this->assertEquals(
            $orderId,
            $this->paymentMetaRepository->findByPaymentAndKey($payment, 'google_play_billing_order_id')->value
        );

        // check subscription type & start/end times against Google play validation response
        $subscription = $this->subscriptionsRepository->getTable()->fetch();
        $this->assertEquals($googleSubscriptionType->subscription_type_id, $subscription->subscription_type_id);
        $this->assertEquals($startTimeMillisPurchase, $payment->subscription_start_at);
        $this->assertEquals($expiryTimeMillisPurchase, $payment->subscription_end_at);
        $this->assertEquals($startTimeMillisPurchase, $subscription->start_time);
        $this->assertEquals($expiryTimeMillisPurchase, $subscription->end_time);
        $this->assertEquals($subscription->id, $payment->subscription_id);
    }

    // offer_periods set NULL equals offer_periods set 1 when next_subscription_type set for subscription type.
    public function testOfferPeriodsSetNull()
    {
        /* ************************************************************ *
         * FIRST PURCHASE ********************************************* *
         * ************************************************************ */

        $purchaseToken = 'purchase_token_' . Random::generate();
        $orderId = 'GPA.1111-1111-1111-11111';
        $googleSubscriptionType = $this->getGooglePlaySubscriptionTypeWeb();
        $startTimeMillisPurchase = new DateTime('2030-04-27 19:20:57');
        $expiryTimeMillisPurchase = new DateTime('2030-05-27 19:20:57');

        $this->preparePayment($purchaseToken, $orderId, $startTimeMillisPurchase, $expiryTimeMillisPurchase, $googleSubscriptionType);

        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());

        /* ************************************************************ *
         * SECOND PURCHASE ********************************************* *
         * ************************************************************ */

        $orderId = 'GPA.1111-1111-1111-11111..0';
        $startTimeMillisPurchase = new DateTime('2030-05-27 19:20:57');
        $expiryTimeMillisPurchase = new DateTime('2030-06-27 19:20:57');

        $developerNotificationSecondPurchase = $this->preparePayment($purchaseToken, $orderId, $startTimeMillisPurchase, $expiryTimeMillisPurchase, $googleSubscriptionType);

        // developer notification marked as processed
        $developerNotificationSecondPurchaseUpdated = $this->developerNotificationsRepository->find($developerNotificationSecondPurchase->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationSecondPurchaseUpdated->status
        );

        // new payment, subscription & payment_meta
        $this->assertEquals(2, $this->paymentsRepository->totalCount());
        $this->assertEquals(2, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(6, $this->paymentMetaRepository->totalCount());

        $payments = $this->paymentsRepository->getTable()->order('created_at')->fetchAll();
        reset($payments);
        $paymentSecondPurchase = next($payments);

        $subscriptionTypeAfterOfferPeriods = $this->getSubscriptionTypeAfterOfferPeriods();

        // check new payment (second) & meta against second purchase
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $paymentSecondPurchase->status);
        $this->assertEquals($subscriptionTypeAfterOfferPeriods->id, $paymentSecondPurchase->subscription_type_id);

        $subscriptionSecondPurchase = $paymentSecondPurchase->subscription;
        $this->assertEquals($subscriptionTypeAfterOfferPeriods->id, $subscriptionSecondPurchase->subscription_type_id);
        $this->assertEquals($startTimeMillisPurchase, $paymentSecondPurchase->subscription_start_at);
        $this->assertEquals($expiryTimeMillisPurchase, $paymentSecondPurchase->subscription_end_at);
        $this->assertEquals($startTimeMillisPurchase, $subscriptionSecondPurchase->start_time);
        $this->assertEquals($expiryTimeMillisPurchase, $subscriptionSecondPurchase->end_time);
    }

    public function testOfferPeriodsSetInt()
    {
        /* ************************************************************ *
         * FIRST PURCHASE ********************************************* *
         * ************************************************************ */

        $purchaseToken = 'purchase_token_' . Random::generate();
        $orderId = 'GPA.1111-1111-1111-11111';
        $googleSubscriptionType = $this->getGooglePlaySubscriptionTypeWeb(2);
        $startTimeMillisPurchase = new DateTime('2030-04-27 19:20:57');
        $expiryTimeMillisPurchase = new DateTime('2030-05-27 19:20:57');

        $this->preparePayment($purchaseToken, $orderId, $startTimeMillisPurchase, $expiryTimeMillisPurchase, $googleSubscriptionType);

        // After first purchase
        $this->assertEquals(1, $this->paymentsRepository->totalCount());
        $this->assertEquals(1, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(3, $this->paymentMetaRepository->totalCount());

        /* ************************************************************ *
         * SECOND PURCHASE ********************************************* *
         * ************************************************************ */

        $orderId = 'GPA.1111-1111-1111-11111..0';
        $startTimeMillisPurchase = new DateTime('2030-05-27 19:20:57');
        $expiryTimeMillisPurchase = new DateTime('2030-06-27 19:20:57');

        $this->preparePayment($purchaseToken, $orderId, $startTimeMillisPurchase, $expiryTimeMillisPurchase, $googleSubscriptionType);

        // After second purchase
        $this->assertEquals(2, $this->paymentsRepository->totalCount());
        $this->assertEquals(2, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(6, $this->paymentMetaRepository->totalCount());

        // Check second payment still has offer period subscription type
        $payments = $this->paymentsRepository->getTable()->order('created_at')->fetchAll();
        reset($payments);
        $paymentSecondPurchase = next($payments);

        // check new payment (second) & meta against second purchase
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $paymentSecondPurchase->status);
        $this->assertEquals($googleSubscriptionType->subscription_type_id, $paymentSecondPurchase->subscription_type_id);

        /* ************************************************************ *
         * THIRD PURCHASE ********************************************* *
         * ************************************************************ */

        $orderId = 'GPA.1111-1111-1111-11111..1';
        $startTimeMillisPurchase = new DateTime('2030-06-27 19:20:57');
        $expiryTimeMillisPurchase = new DateTime('2030-07-27 19:20:57');

        $developerNotificationThirdPurchase = $this->preparePayment($purchaseToken, $orderId, $startTimeMillisPurchase, $expiryTimeMillisPurchase, $googleSubscriptionType);

        // After third purchase
        $this->assertEquals(3, $this->paymentsRepository->totalCount());
        $this->assertEquals(3, $this->subscriptionsRepository->totalCount());
        $this->assertEquals(9, $this->paymentMetaRepository->totalCount());

        // developer notification marked as processed
        $developerNotificationThirdPurchaseUpdated = $this->developerNotificationsRepository->find($developerNotificationThirdPurchase->id);
        $this->assertEquals(
            DeveloperNotificationsRepository::STATUS_PROCESSED,
            $developerNotificationThirdPurchaseUpdated->status
        );

        $payments = $this->paymentsRepository->getTable()->order('created_at')->fetchAll();
        reset($payments);
        next($payments);
        $paymentThirdPurchase = next($payments);
        $subscriptionTypeAfterOfferPeriods = $this->getSubscriptionTypeAfterOfferPeriods();

        // check third payment & meta against third purchase
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $paymentThirdPurchase->status);
        $this->assertEquals($subscriptionTypeAfterOfferPeriods->id, $paymentThirdPurchase->subscription_type_id);

        $subscriptionSecondPurchase = $paymentThirdPurchase->subscription;
        $this->assertEquals($subscriptionTypeAfterOfferPeriods->id, $subscriptionSecondPurchase->subscription_type_id);
        $this->assertEquals($startTimeMillisPurchase, $paymentThirdPurchase->subscription_start_at);
        $this->assertEquals($expiryTimeMillisPurchase, $paymentThirdPurchase->subscription_end_at);
        $this->assertEquals($startTimeMillisPurchase, $subscriptionSecondPurchase->start_time);
        $this->assertEquals($expiryTimeMillisPurchase, $subscriptionSecondPurchase->end_time);
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

    private function getGooglePlaySubscriptionTypeWeb(?int $offerPeriods = null): ActiveRow
    {
        if ($this->googlePlaySubscriptionTypeWeb !== null) {
            return $this->googlePlaySubscriptionTypeWeb;
        }

        $googlePlaySubscriptionIdWeb = 'test.package.dennikn.test.web';
        $subscriptionTypeCodeWeb = 'google_inapp_web';

        $subscriptionTypeWeb = $this->subscriptionTypesRepository->findByCode($subscriptionTypeCodeWeb);
        $subscriptionTypeAfterOfferPeriods = $this->getSubscriptionTypeAfterOfferPeriods();

        if (!$subscriptionTypeWeb) {
            $subscriptionTypeWeb = $this->subscriptionTypeBuilder->createNew()
                ->setName('Google Pay test subscription WEB month')
                ->setUserLabel('Google Pay test subscription WEB month')
                ->setPrice(6.99)
                ->setCode($subscriptionTypeCodeWeb)
                ->setLength(31)
                ->setActive(true)
                ->setNextSubscriptionTypeIdFromCode($subscriptionTypeAfterOfferPeriods->code)
                ->save();
        }
        $this->googlePlaySubscriptionTypeWeb = $this->googlePlaySubscriptionTypesRepository->findByGooglePlaySubscriptionId($googlePlaySubscriptionIdWeb);
        if (!$this->googlePlaySubscriptionTypeWeb) {
            $this->googlePlaySubscriptionTypeWeb = $this->googlePlaySubscriptionTypesRepository->add(
                $googlePlaySubscriptionIdWeb,
                $subscriptionTypeWeb,
                $offerPeriods
            );
        }

        return $this->googlePlaySubscriptionTypeWeb;
    }

    private function getSubscriptionTypeAfterOfferPeriods()
    {
        $subscriptionTypeCode = 'google_inapp_after_offer_periods';

        $subscriptionType = $this->subscriptionTypesRepository->findByCode($subscriptionTypeCode);
        if (!$subscriptionType) {
            $subscriptionType = $this->subscriptionTypeBuilder->createNew()
                ->setName('Google Pay test subscription after offer periods')
                ->setUserLabel('Google Pay test subscription after offer periods')
                ->setPrice(6.99)
                ->setCode($subscriptionTypeCode)
                ->setLength(31)
                ->setActive(true)
                ->save();
        }

        return $subscriptionType;
    }

    private function preparePayment($purchaseTokenString, $orderIdPurchase, $startTimeMillisPurchase, $expiryTimeMillisPurchase, $googleSubscriptionType)
    {
        $purchaseToken = $this->purchaseTokensRepository->add(
            $purchaseTokenString,
            $this->googlePlayPackage,
            $googleSubscriptionType->subscription_id
        );
        $developerNotificationPurchase = $this->developerNotificationsRepository->add(
            $purchaseToken,
            new DateTime(),
            DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_PURCHASED
        );
        $hermesMessagePurchase = new HermesMessage(
            'developer-notification-received',
            [
                'developer_notification_id' => $developerNotificationPurchase->getPrimary(),
            ],
        );

        $priceAmountMicrosPurchase = $googleSubscriptionType->subscription_type->price * 1000000;
        // acknowledgementState: 1 -> set to acknowledged so we don't need to mock acknowledgement service
        // autoRenewing: true -> first purchase set to create recurrent payment
        $googleResponsePurchase = <<<JSON
{
    "acknowledgementState": 1,
    "autoRenewing": true,
    "autoResumeTimeMillis": null,
    "cancelReason": null,
    "countryCode": "SK",
    "developerPayload": "",
    "emailAddress": null,
    "expiryTimeMillis": "{$expiryTimeMillisPurchase->format('Uv')}",
    "externalAccountId": null,
    "familyName": null,
    "givenName": null,
    "kind": "androidpublisher#subscriptionPurchase",
    "linkedPurchaseToken": null,
    "obfuscatedExternalAccountId": "{$this->getUser()->id}",
    "obfuscatedExternalProfileId": null,
    "orderId": "{$orderIdPurchase}",
    "paymentState": 1,
    "priceAmountMicros": "{$priceAmountMicrosPurchase}",
    "priceCurrencyCode": "EUR",
    "profileId": null,
    "profileName": null,
    "promotionCode": null,
    "promotionType": null,
    "purchaseType": null,
    "startTimeMillis": "{$startTimeMillisPurchase->format('Uv')}",
    "userCancellationTimeMillis": null
}
JSON;

        $this->injectSubscriptionResponseIntoGooglePlayValidatorMock($googleResponsePurchase);

        $this->developerNotificationReceivedHandler->handle($hermesMessagePurchase);

        return $developerNotificationPurchase;
    }
}
