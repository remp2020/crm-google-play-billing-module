<?php

namespace Crm\GooglePlayBillingModule\Tests\Hermes;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\GooglePlayBillingModule\Gateways\GooglePlayBilling;
use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\GooglePlayBillingModule\Hermes\VoidedPurchaseNotificationReceivedHandler;
use Crm\GooglePlayBillingModule\Repositories\GooglePlaySubscriptionTypesRepository;
use Crm\GooglePlayBillingModule\Repositories\PurchaseTokensRepository;
use Crm\GooglePlayBillingModule\Repositories\VoidedPurchaseNotificationsRepository;
use Crm\GooglePlayBillingModule\Seeders\PaymentGatewaysSeeder;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\PaymentStatusChangeHandler;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Models\RecurrentPayment\RecurrentPaymentStateEnum;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Models\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Models\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repositories\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UsersModule\Repositories\UsersRepository;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Utils\DateTime;

class VoidedPurchaseNotificationReceivedHandlerTest extends DatabaseTestCase
{
    private string $googlePlayPackage = 'test.package';
    private ?ActiveRow $googlePlaySubscriptionTypeWeb = null;
    private ?ActiveRow $user = null;

    private VoidedPurchaseNotificationsRepository $voidedPurchaseNotificationsRepository;
    private SubscriptionTypesRepository $subscriptionTypesRepository;
    private GooglePlaySubscriptionTypesRepository $googlePlaySubscriptionTypesRepository;
    private PurchaseTokensRepository $purchaseTokensRepository;
    private PaymentGatewaysRepository $paymentGatewaysRepository;
    private PaymentsRepository $paymentsRepository;
    private UsersRepository $usersRepository;
    private SubscriptionsRepository $subscriptionsRepository;
    private RecurrentPaymentsRepository $recurrentPaymentsRepository;

    private SubscriptionTypeBuilder $subscriptionTypeBuilder;
    private VoidedPurchaseNotificationReceivedHandler $voidedPurchaseNotificationReceivedHandler;

    protected function requiredRepositories(): array
    {
        return [
            VoidedPurchaseNotificationsRepository::class,
            SubscriptionTypesRepository::class,
            GooglePlaySubscriptionTypesRepository::class,
            PurchaseTokensRepository::class,
            PaymentGatewaysRepository::class,
            PaymentsRepository::class,
            UsersRepository::class,
            SubscriptionsRepository::class,

            RecurrentPaymentsRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
            PaymentGatewaysSeeder::class,
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionTypeNamesSeeder::class,
            SubscriptionLengthMethodSeeder::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // repositories
        $this->voidedPurchaseNotificationsRepository = $this->inject(VoidedPurchaseNotificationsRepository::class);
        $this->subscriptionTypesRepository = $this->inject(SubscriptionTypesRepository::class);
        $this->googlePlaySubscriptionTypesRepository = $this->inject(GooglePlaySubscriptionTypesRepository::class);
        $this->purchaseTokensRepository = $this->inject(PurchaseTokensRepository::class);
        $this->paymentGatewaysRepository = $this->inject(PaymentGatewaysRepository::class);
        $this->paymentsRepository = $this->inject(PaymentsRepository::class);
        $this->usersRepository = $this->inject(UsersRepository::class);
        $this->subscriptionsRepository = $this->inject(SubscriptionsRepository::class);
        $this->recurrentPaymentsRepository = $this->inject(RecurrentPaymentsRepository::class);

        // handler
        $this->voidedPurchaseNotificationReceivedHandler = $this->inject(VoidedPurchaseNotificationReceivedHandler::class);

        // other
        $this->subscriptionTypeBuilder = $this->inject(SubscriptionTypeBuilder::class);

        // add event handler to create subscription
        /** @var Emitter $emitter */
        $emitter = $this->inject(Emitter::class);
        // clear initialized handlers (we do not want duplicated listeners)
        $emitter->removeAllListeners(PaymentChangeStatusEvent::class);
        $emitter->addListener(
            PaymentChangeStatusEvent::class,
            $this->inject(PaymentStatusChangeHandler::class),
        );
    }

    public function tearDown(): void
    {
        /** @var Emitter $emitter */
        $emitter = $this->inject(Emitter::class);
        $emitter->removeAllListeners(PaymentChangeStatusEvent::class);

        parent::tearDown();
    }

    public function testVoidedPurchaseNotificationHandler()
    {
        $orderId = 'GPA.1111-1111-1111-11111';
        $productType = 1;
        $refundType = 1;
        $eventTime = new DateTime();

        [$payment, $recurrentPayment, $purchaseToken] = $this->prepareData(
            orderId: $orderId,
            purchaseToken: '786fs3dg87dfg',
        );

        // verify there is active subscription
        $subscriptionIsActiveOtInFuture = $this->subscriptionsRepository
            ->subscriptionIsActiveOrInFuture($payment->subscription);
        $this->assertTrue($subscriptionIsActiveOtInFuture);

        $voidedPurchaseNotification = $this->voidedPurchaseNotificationsRepository->add(
            $purchaseToken,
            $orderId,
            $productType,
            $refundType,
            $eventTime,
        );

        $hermesMessage = new HermesMessage(
            type: 'voided-purchase-notification-received',
            payload: [
                'voided_purchase_notification_id' => $voidedPurchaseNotification->getPrimary(),
            ],
        );
        $this->voidedPurchaseNotificationReceivedHandler->handle($hermesMessage);

        // check payment status
        $payment = $this->paymentsRepository->find($payment->id);
        $this->assertEquals(PaymentStatusEnum::Refund->value, $payment->status);

        // check subscription cancelled
        $subscriptionIsActiveOtInFuture = $this->subscriptionsRepository
            ->subscriptionIsActiveOrInFuture($payment->subscription);
        $this->assertFalse($subscriptionIsActiveOtInFuture);

        // check recurrent payment status
        $recurrentPayment = $this->recurrentPaymentsRepository->find($recurrentPayment->id);
        $this->assertEquals(RecurrentPaymentStateEnum::SystemStop->value, $recurrentPayment->state);
    }

    public function testVoidedPurchaseNotificationSubscriptionAlreadyStoppedHandler()
    {
        $orderId = 'GPA.1111-1111-1111-11111';
        $productType = 1;
        $refundType = 1;
        $eventTime = new DateTime();

        [$payment, $recurrentPayment, $purchaseToken] = $this->prepareData(
            orderId: $orderId,
            purchaseToken: '786fs3dg87dfg',
        );

        // ***** STOP AND VERIFY SUBSCRIPTION BEGIN *****
        $subscriptionIsActiveOtInFuture = $this->subscriptionsRepository
            ->subscriptionIsActiveOrInFuture($payment->subscription);
        $this->assertTrue($subscriptionIsActiveOtInFuture);

        // stop everything; simulates that Google already send REFUND / CANCEL notification
        $recurrentPayment = $this->recurrentPaymentsRepository->stoppedBySystem($recurrentPayment);
        $payment = $this->paymentsRepository->updateStatus($payment, PaymentStatusEnum::Refund->value);
        $subscription = $this->subscriptionsRepository->update($payment->subscription, ['end_time' => $payment->subscription->start_time]);

        // verify there is no active subscription
        $userHasActiveSubscription = $this->subscriptionsRepository
            ->lastActiveUserSubscription($payment->user_id)->count('*');
        $this->assertEquals(0, $userHasActiveSubscription);
        // ***** STOP AND VERIFY SUBSCRIPTION END *****

        $voidedPurchaseNotification = $this->voidedPurchaseNotificationsRepository->add(
            $purchaseToken,
            $orderId,
            $productType,
            $refundType,
            $eventTime,
        );

        $hermesMessage = new HermesMessage(
            type: 'voided-purchase-notification-received',
            payload: [
                'voided_purchase_notification_id' => $voidedPurchaseNotification->getPrimary(),
            ],
        );
        $this->voidedPurchaseNotificationReceivedHandler->handle($hermesMessage);

        // check payment status
        $payment = $this->paymentsRepository->find($payment->id);
        $this->assertEquals(PaymentStatusEnum::Refund->value, $payment->status);

        // check subscription cancelled
        $subscriptionIsActiveOtInFuture = $this->subscriptionsRepository
            ->subscriptionIsActiveOrInFuture($payment->subscription);
        $this->assertFalse($subscriptionIsActiveOtInFuture);

        // check recurrent payment status
        $recurrentPayment = $this->recurrentPaymentsRepository->find($recurrentPayment->id);
        $this->assertEquals(RecurrentPaymentStateEnum::SystemStop->value, $recurrentPayment->state);
    }

    private function prepareData(
        string $orderId,
        string $purchaseToken,
        ?DateTime $startTime = null,
        ?DateTime $expiryTime = null,
    ): array {
        $startTime ??= new DateTime('2030-04-27 19:20:57');
        $expiryTime ??= new DateTime('2030-05-27 19:20:57');

        $purchaseTokenPurchase = $this->purchaseTokensRepository->add(
            $purchaseToken,
            $this->googlePlayPackage,
            $this->getGooglePlaySubscriptionType()->subscription_id,
        );

        $user = $this->getUser();
        $paymentGateway = $this->paymentGatewaysRepository->findByCode(GooglePlayBilling::GATEWAY_CODE);

        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems(
                SubscriptionTypePaymentItem::fromSubscriptionType(
                    $this->getGooglePlaySubscriptionType()->subscription_type,
                ),
            );

        $payment = $this->paymentsRepository->add(
            subscriptionType: $this->getGooglePlaySubscriptionType()->subscription_type,
            paymentGateway: $paymentGateway,
            user: $user,
            paymentItemContainer: $paymentItemContainer,
            recurrentCharge: true,
            metaData: [
                GooglePlayBillingModule::META_KEY_ORDER_ID => $orderId,
                GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN => $purchaseToken,
            ],
        );

        $payment = $this->paymentsRepository->updateStatus($payment, PaymentStatusEnum::Paid->value);

        $recurrentPayment = $this->recurrentPaymentsRepository->createFromPayment(
            $payment,
            $purchaseTokenPurchase->purchase_token,
            $expiryTime,
        );

        return [$payment, $recurrentPayment, $purchaseTokenPurchase];
    }

    private function getGooglePlaySubscriptionType(): ActiveRow
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
                $subscriptionTypeWeb,
            );
        }

        return $this->googlePlaySubscriptionTypeWeb;
    }

    protected function getUser()
    {
        if (!$this->user) {
            $this->user = $this->usersRepository->add('asfsaoihf@afasf.sk', 'q039uewt');
        }
        return $this->user;
    }
}
