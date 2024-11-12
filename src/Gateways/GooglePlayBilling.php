<?php

namespace Crm\GooglePlayBillingModule\Gateways;

use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\GooglePlayBillingModule\Models\GooglePlayValidatorFactory;
use Crm\GooglePlayBillingModule\Models\SubscriptionResponseProcessor\SubscriptionResponseProcessorInterface;
use Crm\GooglePlayBillingModule\Repositories\DeveloperNotificationsRepository;
use Crm\PaymentsModule\Models\Gateways\ExternallyChargedRecurrentPaymentInterface;
use Crm\PaymentsModule\Models\Gateways\GatewayAbstract;
use Crm\PaymentsModule\Models\Gateways\RecurrentPaymentInterface;
use Crm\PaymentsModule\Models\RecurrentPaymentFailStop;
use Crm\PaymentsModule\Models\RecurrentPaymentFailTry;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Nette\Application\LinkGenerator;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Response;
use Nette\Localization\Translator;
use Omnipay\Common\Exception\InvalidRequestException;
use ReceiptValidator\GooglePlay\PurchaseResponse;
use Tracy\Debugger;
use Tracy\ILogger;

class GooglePlayBilling extends GatewayAbstract implements RecurrentPaymentInterface, ExternallyChargedRecurrentPaymentInterface
{
    public const GATEWAY_CODE = 'google_play_billing';
    public const GATEWAY_NAME = 'Google Play Billing';

    private bool $successful = false;

    private $purchaseToken;

    public function __construct(
        LinkGenerator $linkGenerator,
        ApplicationConfig $applicationConfig,
        Response $httpResponse,
        Translator $translator,
        private SubscriptionResponseProcessorInterface $subscriptionResponseProcessor,
        private DeveloperNotificationsRepository $developerNotificationsRepository,
        private GooglePlayValidatorFactory $googlePlayValidatorFactory,
        private PaymentsRepository $paymentsRepository,
        private PaymentMetaRepository $paymentMetaRepository,
        private SubscriptionsRepository $subscriptionsRepository,
        private SubscriptionMetaRepository $subscriptionMetaRepository,
        private RecurrentPaymentsRepository $recurrentPaymentsRepository,
    ) {
        parent::__construct($linkGenerator, $applicationConfig, $httpResponse, $translator);
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }

    public function process($allowRedirect = true)
    {
        throw new \Exception("GooglePlayBilling is not intended for use as standard payment gateway.");
    }

    protected function initialize()
    {
    }

    public function begin($payment)
    {
        throw new \Exception("GooglePlayBilling is not intended for use as standard payment gateway.");
    }

    public function complete($payment): ?bool
    {
        throw new \Exception("GooglePlayBilling is not intended for use as standard payment gateway.");
    }

    public function getChargedPaymentStatus(): string
    {
        return PaymentsRepository::STATUS_PREPAID;
    }

    public function getSubscriptionExpiration(string $cid = null): \DateTime
    {
        $developerNotification = $this->developerNotificationsRepository->findBy('purchase_token', $cid);
        if (!$developerNotification) {
            throw new \Exception("Missing developer notification with purchase token: [{$cid}].");
        }

        $googlePlayValidator = $this->googlePlayValidatorFactory->create();

        try {
            $gSubscription = $googlePlayValidator
                ->setPackageName($developerNotification->package_name)
                ->setPurchaseToken($developerNotification->purchase_token)
                ->setProductId($developerNotification->subscription_id)
                ->validateSubscription();
        } catch (\Exception  $e) {
            throw new \Exception(
                "Unable to validate Google Play notification [" . $developerNotification . "] loaded from purchase token [" . $cid . "]. Error: [{$e->getMessage()}]"
            );
        }

        return $this->subscriptionResponseProcessor->getSubscriptionEndAt($gSubscription);
    }

    public function charge($payment, $token): string
    {
        $developerNotification = $this->developerNotificationsRepository->findBy('purchase_token', $token);
        if (!$developerNotification) {
            throw new RecurrentPaymentFailStop('Unable to find notification for given purchase token: ' . $token);
        }

        $this->purchaseToken = $developerNotification->purchase_token;
        $googlePlayValidator = $this->googlePlayValidatorFactory->create();

        try {
            $gSubscription = $googlePlayValidator
                ->setPackageName($developerNotification->package_name)
                ->setPurchaseToken($developerNotification->purchase_token)
                ->setProductId($developerNotification->subscription_id)
                ->validateSubscription();
        } catch (\Exception  $e) {
            Debugger::log(
                "Unable to validate Google Play notification [" . $developerNotification . "] loaded from purchase token [" . $token . "]. Error: [{$e->getMessage()}]",
                Debugger::INFO
            );
            throw new RecurrentPaymentFailTry("Unable to validate google play subscription for purchase token: " . $token);
        }

        /* TODO: from docs -> paymentState: Not present for canceled, expired subscriptions.
                - we can stop when no present immediately */


        // validate google subscription payment state
        if (!in_array($gSubscription->getPaymentState(), [
            GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_CONFIRMED,
            GooglePlayValidatorFactory::SUBSCRIPTION_PAYMENT_STATE_FREE_TRIAL
        ], true)) {
            throw new RecurrentPaymentFailTry('Payment is not confirmed by Google yet.');
        }

        $subscriptionStartAt = $this->subscriptionResponseProcessor->getSubscriptionStartAt($gSubscription);
        $subscriptionEndAt = $this->subscriptionResponseProcessor->getSubscriptionEndAt($gSubscription);

        // load end_date of last subscription
        $recurrentPayment = $this->recurrentPaymentsRepository->findByPayment($payment);

        // traverse to the latest successful parent payment
        /** @var ActiveRow $parentPayment */
        $parentPayment = $this->recurrentPaymentsRepository
            ->latestSuccessfulRecurrentPayment($recurrentPayment)
            ->parent_payment ?? null;

        if (!isset($parentPayment->subscription_id)) {
            // TODO: can be this fixed before next tries?
            Debugger::log("Unable to find previous subscription for payment ID [{$payment->id}], cannot determine if it was renewed.", ILogger::ERROR);
            throw new RecurrentPaymentFailTry();
        }

        $subscriptionEndDate = $parentPayment->subscription->end_time;
        // Google stores milliseconds in datetime, we don't
        $subscriptionEndAtZeroMillis = (clone $subscriptionEndAt)->setTime(
            $subscriptionEndAt->format('H'),
            $subscriptionEndAt->format('i'),
            $subscriptionEndAt->format('s')
        );
        if ($subscriptionEndAtZeroMillis <= $subscriptionEndDate || $subscriptionEndAtZeroMillis < new \DateTime()) {
            throw new RecurrentPaymentFailTry();
        }

        // make sure the created subscription matches Google's purchase/expiration dates
        $this->paymentsRepository->update($payment, [
            'subscription_start_at' => $subscriptionStartAt,
            'subscription_end_at' => $subscriptionEndAt,
        ]);

        $this->paymentMetaRepository->add(
            $payment,
            GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN,
            $developerNotification->purchase_token
        );
        $this->paymentMetaRepository->add(
            $payment,
            GooglePlayBillingModule::META_KEY_ORDER_ID,
            $gSubscription->getRawResponse()->getOrderId()
        );
        $this->paymentMetaRepository->add(
            $payment,
            GooglePlayBillingModule::META_KEY_DEVELOPER_NOTIFICATION_ID,
            $developerNotification->id
        );

        // check if grace period subscription exists
        $subscription = $this->subscriptionMetaRepository->findSubscriptionBy(
            GooglePlayBillingModule::META_KEY_ORDER_ID,
            $gSubscription->getRawResponse()->getOrderId()
        );
        if ($subscription && $subscription->end_time > new \DateTime()) {
            $this->subscriptionsRepository->update($subscription, [
                'end_time' => new \DateTime()
            ]);
        }

        // everything is ok; google charged customer and subscription was created
        $this->successful = true;

        return RecurrentPaymentInterface::CHARGE_OK;
    }

    public function checkValid($token)
    {
        $googlePlayValidator = $this->googlePlayValidatorFactory->create();
        $developerNotification = $this->developerNotificationsRepository->findBy('purchase_token', $token);
        $this->purchaseToken = $developerNotification->purchase_token;

        $gPurchase = $googlePlayValidator
            ->setPackageName($developerNotification->package_name)
            ->setPurchaseToken($developerNotification->purchase_token)
            ->setProductId($developerNotification->subscription_id)
            ->validatePurchase();

        return $gPurchase->getPurchaseState() !== PurchaseResponse::PURCHASE_STATE_CANCELED;
    }

    public function checkExpire($recurrentPayments)
    {
        throw new InvalidRequestException(self::GATEWAY_CODE . " gateway doesn't support token expiration checking (it should never expire)");
    }

    public function hasRecurrentToken(): bool
    {
        return isset($this->purchaseToken);
    }

    public function getRecurrentToken()
    {
        return $this->purchaseToken ?? null;
    }

    public function getResultCode(): string
    {
        return 'OK';
    }

    public function getResultMessage(): string
    {
        return 'OK';
    }
}
