<?php

namespace Crm\GooglePlayBillingModule\User;

use Crm\ApplicationModule\Config\Repository\ConfigsRepository;
use Crm\ApplicationModule\User\UserDataProviderInterface;
use Crm\GooglePlayBillingModule\Gateways\GooglePlayBilling;
use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\GooglePlayBillingModule\Model\Config;
use Crm\GooglePlayBillingModule\Model\GooglePlayValidatorFactory;
use Crm\GooglePlayBillingModule\Repository\PurchaseTokensRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Exception;
use Nette\Localization\ITranslator;
use ReceiptValidator\GooglePlay\Validator;

class GooglePlayUserDataProvider implements UserDataProviderInterface
{
    private $translator;

    /** @var Validator */
    private $googlePlayValidator;

    private $googlePlayValidatorFactory;

    private $paymentsRepository;

    private $paymentMetaRepository;

    private $purchaseTokensRepository;

    private $configsRepository;

    public function __construct(
        ConfigsRepository $configsRepository,
        GooglePlayValidatorFactory $googlePlayValidatorFactory,
        PaymentsRepository $paymentsRepository,
        PaymentMetaRepository $paymentMetaRepository,
        PurchaseTokensRepository $purchaseTokensRepository,
        ITranslator $translator
    ) {
        $this->translator = $translator;
        $this->googlePlayValidatorFactory = $googlePlayValidatorFactory;
        $this->paymentsRepository = $paymentsRepository;
        $this->paymentMetaRepository = $paymentMetaRepository;
        $this->purchaseTokensRepository = $purchaseTokensRepository;
        $this->configsRepository = $configsRepository;
    }

    public static function identifier(): string
    {
        return 'google_play';
    }

    public function data($userId)
    {
        return [];
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
        return [];
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
                } catch (Exception | \GuzzleHttp\Exception\GuzzleException | \Google_Exception $e) {
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
