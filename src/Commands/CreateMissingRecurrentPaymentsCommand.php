<?php

namespace Crm\GooglePlayBillingModule\Commands;

use Crm\ApplicationModule\Models\Config\ApplicationConfig;
use Crm\GooglePlayBillingModule\Gateways\GooglePlayBilling;
use Crm\GooglePlayBillingModule\GooglePlayBillingModule;
use Crm\GooglePlayBillingModule\Repositories\DeveloperNotificationsRepository;
use Crm\GooglePlayBillingModule\Repositories\GooglePlaySubscriptionTypesRepository;
use Crm\PaymentsModule\Models\Payment\PaymentStatusEnum;
use Crm\PaymentsModule\Models\RecurrentPayment\RecurrentPaymentStateEnum;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repositories\PaymentMetaRepository;
use Crm\PaymentsModule\Repositories\PaymentMethodsRepository;
use Crm\PaymentsModule\Repositories\PaymentsRepository;
use Crm\PaymentsModule\Repositories\RecurrentPaymentsRepository;
use Nette\Utils\DateTime;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateMissingRecurrentPaymentsCommand extends Command
{

    public function __construct(
        private readonly PaymentsRepository $paymentsRepository,
        private readonly PaymentGatewaysRepository $paymentGatewaysRepository,
        private readonly PaymentMetaRepository $paymentMetaRepository,
        private readonly RecurrentPaymentsRepository $recurrentPaymentsRepository,
        private readonly ApplicationConfig $applicationConfig,
        private readonly GooglePlaySubscriptionTypesRepository $googlePlaySubscriptionTypesRepository,
        private readonly DeveloperNotificationsRepository $developerNotificationsRepository,
        private readonly PaymentMethodsRepository $paymentMethodsRepository,
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('google:create-missing-recurrent')
            ->addOption(
                'datetime',
                null,
                InputOption::VALUE_OPTIONAL,
                'Set datetime to create recurrent payments with the same created_at datetime. Format is YYYY-MM-DD HH:mm.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('');
        $output->writeln('<info>***** CREATE MISSING RECURRENT PAYMENTS *****</info>');
        $output->writeln('');

        $datetime = $input->getOption('datetime');
        if ($datetime) {
            $this->recurrentPaymentsRepository->setNow(new DateTime($datetime));
        }

        $gateway = $this->paymentGatewaysRepository->findByCode(GooglePlayBilling::GATEWAY_CODE);

        $purchaseTokens = $this->paymentMetaRepository->getTable()->select('value AS purchase_token')
            ->where('key', GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN)
            ->group('value');

        foreach ($purchaseTokens as $purchaseToken) {
            $output->writeln("Processing <info>{$purchaseToken->purchase_token}</info>:");
            $googlePayments = $this->paymentsRepository
                ->all(payment_gateway: $gateway, status: PaymentStatusEnum::Prepaid->value)
                ->where([
                    ':payment_meta.key' => GooglePlayBillingModule::META_KEY_PURCHASE_TOKEN,
                    ':payment_meta.value' => $purchaseToken->purchase_token,
                ])
                ->order('paid_at ASC');

            $googlePaymentsCount = $googlePayments->count('*');
            $output->writeln("  * Total payments with purchase token: <comment>{$googlePaymentsCount}</comment>");

            if (!$googlePaymentsCount) {
                continue;
            }

            $lastPayment = null;
            $lastRecurrent = null;
            foreach ($googlePayments as $payment) {
                $output->writeln("  * Processing payment <info>#{$payment->id}</info> ($payment->paid_at) of user <info>#{$payment->user_id}</info>: ");
                $orderId = $payment->related('payment_meta')
                    ->where('key', GooglePlayBillingModule::META_KEY_ORDER_ID)
                    ->fetch();

                if (!$orderId) {
                    $output->writeln("  * <error>ERROR</error>: Missing order ID");
                    continue;
                }

                $googleSubscriptionType = $this->googlePlaySubscriptionTypesRepository->findBy('subscription_type_id', $payment->subscription_type_id);
                $usedPeriods = $this->getUsedPeriods($orderId->value);

                // it is recurrent charge
                if ($usedPeriods && $lastRecurrent && $lastPayment) {
                    $output->write("    * setting <comment>charged</comment> status for previous recurrent #{$lastRecurrent->id}: ");
                    $this->setCharged($lastRecurrent, $payment);
                    $output->writeln("OK");
                }

                $output->write("    * creating next recurrent: ");
                $recurrent = $this->createRecurrentFromPayment($payment, $purchaseToken->purchase_token, $googleSubscriptionType, $usedPeriods, $lastRecurrent);
                $output->writeln("OK (#{$recurrent->id})");
                $lastPayment = $payment;
                $lastRecurrent = $recurrent;
            }

            if (!$lastRecurrent) {
                continue;
            }

            $cancelReason = $lastPayment->related('payment_meta')
                ->where('key', 'cancel_reason')
                ->fetch();

            if ($cancelReason) {
                $isRestarted = $this->developerNotificationsRepository->getTable()
                    ->where([
                        'purchase_token' => $purchaseToken->purchase_token,
                        'notification_type' => DeveloperNotificationsRepository::NOTIFICATION_TYPE_SUBSCRIPTION_RESTARTED,
                        'event_time > ?' => $lastPayment->subscription->start_time,
                        'event_time < ?' => $lastPayment->subscription->end_time,
                    ])->fetch();

                if (!$isRestarted) {
                    $output->write("    * setting <comment>user_stop</comment> status: ");
                    $this->userStopRecurrent($lastRecurrent);
                    $output->writeln("OK");
                    continue;
                }
            }

            // we are at the present with active recurrent in the future
            if ($lastRecurrent->charge_at > new \DateTime('now')) {
                continue;
            }

            $output->write("    * setting <comment>system_stop</comment> status: ");
            $this->systemStopRecurrent($lastRecurrent);
            $output->writeln("OK");
        }

        return Command::SUCCESS;
    }

    private function getUsedPeriods(string $orderId): int
    {
        $matches = [];
        // Order ID for renewal payments ends with '..0', e.g. 'GPA.1111-1111-1111-11111..0'
        preg_match('/\.\.(\d+)$/', $orderId, $matches);

        if (isset($matches[1])) {
            // Renewal starts from 0; first renewal order id ends with '..0'; initial payment has no sufix
            return (int)$matches[1] + 2;
        }

        return 0;
    }

    private function userStopRecurrent($recurrentPayment): void
    {
        if ($recurrentPayment->state === RecurrentPaymentStateEnum::UserStop->value) {
            return;
        }

        $this->recurrentPaymentsRepository->update($recurrentPayment, [
            'state' => RecurrentPaymentStateEnum::UserStop->value,
        ]);
    }

    private function systemStopRecurrent($recurrentPayment): void
    {
        if (in_array(
            $recurrentPayment->state,
            [RecurrentPaymentStateEnum::SystemStop->value, RecurrentPaymentStateEnum::ChargeFailed->value],
            true,
        )) {
            return;
        }

        $this->recurrentPaymentsRepository->update($recurrentPayment, [
            'state' => RecurrentPaymentStateEnum::SystemStop->value,
        ]);
    }

    private function createRecurrentFromPayment($payment, $recurrentToken, $googleSubscriptionType, $usedPeriods, $lastRecurrent)
    {
        // check if recurrent payment already exists and return existing instance
        $recurrentPayment = $this->recurrentPaymentsRepository->recurrent($payment);
        if ($recurrentPayment) {
            return $recurrentPayment;
        }

        $retriesConfig = $this->applicationConfig->get('recurrent_payment_charges');
        if ($retriesConfig) {
            $retries = count(explode(',', $retriesConfig));
        } else {
            $retries = 1;
        }

        $chargeAt = $this->calculateChargeAt($payment);

        $paymentMethod = $this->paymentMethodsRepository->findOrAdd(
            $payment->user->id,
            $payment->payment_gateway->id,
            $recurrentToken,
        );

        $recurrent = $this->recurrentPaymentsRepository->add(
            $paymentMethod,
            $payment,
            $chargeAt,
            null,
            --$retries,
        );

        if (isset($googleSubscriptionType->offer_periods) && $googleSubscriptionType->offer_periods === ($usedPeriods + 1)) {
            $this->recurrentPaymentsRepository->update($recurrent, [
                'next_subscription_type_id' => $payment->subscription_type->next_subscription_type_id,
            ]);
        } elseif (!isset($googleSubscriptionType->offer_periods) && isset($payment->subscription_type->next_subscription_type_id)) {
            $this->recurrentPaymentsRepository->update($recurrent, [
                'next_subscription_type_id' => $payment->subscription_type->next_subscription_type_id,
            ]);
        }

        if (isset($lastRecurrent->next_subscription_type_id)) {
            $this->recurrentPaymentsRepository->update($recurrent, [
                'subscription_type_id' => $lastRecurrent->next_subscription_type_id,
            ]);
        }

        return $recurrent;
    }

    private function calculateChargeAt($payment)
    {
        $subscription = $payment->subscription;
        $subscriptionType = $payment->subscription_type;

        if (!$subscription) {
            $endTime = (clone $payment->paid_at)->add(new \DateInterval("P{$payment->subscription_type->length}D"));
        } else {
            $endTime = clone $subscription->end_time;
        }

        $chargeBefore = $subscriptionType->recurrent_charge_before;
        if ($chargeBefore) {
            if ($chargeBefore < 0) {
                $chargeBefore = abs($chargeBefore);
                $endTime->add(new \DateInterval("PT{$chargeBefore}H"));
            } else {
                $endTime->sub(new \DateInterval("PT{$chargeBefore}H"));
            }
        }

        return $endTime;
    }

    private function setCharged($recurrentPayment, $payment): void
    {
        if ($recurrentPayment->state === RecurrentPaymentStateEnum::Charged->value) {
            return;
        }

        $this->recurrentPaymentsRepository->update($recurrentPayment, [
            'payment_id' => $payment->id,
            'state' => RecurrentPaymentStateEnum::Charged->value,
            'status' => 'OK',
            'approval' => 'OK',
        ]);
    }
}
