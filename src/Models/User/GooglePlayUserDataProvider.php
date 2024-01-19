<?php

namespace Crm\GooglePlayBillingModule\Models\User;

use Crm\ApplicationModule\Models\User\UserDataProviderInterface;
use Crm\ApplicationModule\Repositories\ConfigsRepository;
use Crm\GooglePlayBillingModule\Gateways\GooglePlayBilling;
use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\GooglePlayBillingModule\Models\Config;
use Crm\GooglePlayBillingModule\Models\GooglePlayValidatorFactory;
use Crm\GooglePlayBillingModule\Repositories\PurchaseTokensRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionMetaRepository;
use Crm\SubscriptionsModule\Repositories\SubscriptionsRepository;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Nette\Localization\Translator;
use ReceiptValidator\GooglePlay\Validator;

class GooglePlayUserDataProvider implements UserDataProviderInterface
{
    private $translator;

    /** @var Validator */
    private $googlePlayValidator;

    private $googlePlayValidatorFactory;

    private $paymentsRepository;

    private $paymentMetaRepository;

    private $subscriptionsRepository;

    private $subscriptionMetaRepository;

    private $purchaseTokensRepository;

    private $configsRepository;

    public function __construct(
        ConfigsRepository $configsRepository,
        GooglePlayValidatorFactory $googlePlayValidatorFactory,
        PaymentsRepository $paymentsRepository,
        PaymentMetaRepository $paymentMetaRepository,
        SubscriptionsRepository $subscriptionsRepository,
        SubscriptionMetaRepository $subscriptionMetaRepository,
        PurchaseTokensRepository $purchaseTokensRepository,
        Translator $translator
    ) {
        $this->translator = $translator;
        $this->googlePlayValidatorFactory = $googlePlayValidatorFactory;
        $this->paymentsRepository = $paymentsRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->subscriptionMetaRepository = $subscriptionMetaRepository;
        $this->purchaseTokensRepository = $purchaseTokensRepository;
        $this->configsRepository = $configsRepository;
    }

    public static function identifier(): string
    {
        return 'google_play';
    }

    public function data($userId): ?array
    {
        return null;
    }

    public function download($userId)
    {
        return [];
    }

    public function downloadAttachments($userId)
    {
        return [];
    }

    public function delete($userId, $protectedData = [])
    {
        $metaKeys = [
            GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN,
            GooglePlayBillingModule::META_KEY_ORDER_ID,
            GooglePlayBillingModule::META_KEY_DEVELOPER_NOTIFICATION_ID,
        ];
        $userPayments = $this->paymentsRepository->userPayments($userId);
        if ($userPayments) {
            foreach ($userPayments as $userPayment) {
                foreach ($metaKeys as $key => $value) {
                    $row = $this->paymentMetaRepository->findByPaymentAndKey($userPayment, $value);
                    if ($row) {
                        $this->paymentMetaRepository->delete($row);
                    }
                }
            }
        }

        $userSubscriptions = $this->subscriptionsRepository->userSubscriptions($userId);
        if ($userSubscriptions) {
            foreach ($userSubscriptions as $userSubscription) {
                foreach ($metaKeys as $key => $value) {
                    $row = $this->subscriptionMetaRepository->findBySubscriptionAndKey($userSubscription, $value);
                    if ($row) {
                        $this->subscriptionMetaRepository->delete($row);
                    }
                }
            }
        }
    }

    public function protect($userId): array
    {
        return [];
    }

    public function canBeDeleted($userId): array
    {
        $configRow = $this->configsRepository->loadByName(Config::GOOGLE_BLOCK_ANONYMIZATION);
        if ($configRow && $configRow->value) {
            $userPayments = $this->paymentsRepository->userPayments($userId)->where('payment_gateway.code', GooglePlayBilling::GATEWAY_CODE);
            foreach ($userPayments as $userPayment) {
                $purchaseToken = $this->paymentMetaRepository->findByPaymentAndKey($userPayment, GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN);
                if (!$purchaseToken) {
                    throw new Exception('Missing purchase token for payment ID ' . $userPayment->id);
                }
                $purchaseTokenRow = $this->purchaseTokensRepository->findByPurchaseToken($purchaseToken->value);
                if (!$purchaseTokenRow) {
                    throw new Exception('Purchase token ' . $purchaseToken . ' missing in google_play_billing_purchase_tokens table');
                }

                $gSubscription = null;
                try {
                    $this->googlePlayValidator = $this->googlePlayValidatorFactory->create();
                    $gSubscription = $this->googlePlayValidator
                        ->setPackageName($purchaseTokenRow->package_name)
                        ->setPurchaseToken($purchaseTokenRow->purchase_token)
                        ->setProductId($purchaseTokenRow->subscription_id)
                        ->validateSubscription();
                } catch (\Google_Exception $e) {
                    if ($e->getCode() === 410) {
                        //The subscription purchase is no longer available for query because it has been expired for too long.
                        continue;
                    }

                    throw new Exception("Unable to validate Google Play payment. Error: [{$e->getMessage()}]");
                } catch (Exception | GuzzleException $e) {
                    throw new Exception("Unable to validate Google Play payment. Error: [{$e->getMessage()}]");
                }

                if ($gSubscription->getAutoRenewing()) {
                    return [false, $this->translator->translate('google_play_billing.data_provider.delete.active_subscription')];
                }
            }
        }

        return [true, null];
    }
}
