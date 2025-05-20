<?php

namespace Crm\GooglePlayBillingModule\Seeders;

use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\GooglePlayBillingModule\Gateways\GooglePlayBilling;
use Crm\PaymentsModule\Repositories\PaymentGatewaysRepository;
use Symfony\Component\Console\Output\OutputInterface;

class PaymentGatewaysSeeder implements ISeeder
{
    private $paymentGatewaysRepository;

    /** @var OutputInterface */
    private $output;

    public function __construct(PaymentGatewaysRepository $paymentGatewaysRepository)
    {
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
    }

    public function seed(OutputInterface $output)
    {
        $this->output = $output;

        $sorting = 240;
        $this->addPaymentGateway(
            GooglePlayBilling::GATEWAY_CODE,
            GooglePlayBilling::GATEWAY_NAME,
            false,
            $sorting++,
        );
    }

    private function addPaymentGateway(string $gatewayCode, string $gatewayName, bool $recurrent, int $sorting)
    {
        if (!$this->paymentGatewaysRepository->exists($gatewayCode)) {
            $this->paymentGatewaysRepository->add(
                $gatewayName,
                $gatewayCode,
                $sorting,
                true,
                $recurrent,
            );
            $this->output->writeln("  <comment>* payment type <info>{$gatewayCode}</info> created</comment>");
        } else {
            $this->output->writeln("  * payment type <info>{$gatewayCode}</info> exists");
        }
    }
}
