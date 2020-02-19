<?php

namespace Crm\GooglePlayBillingModule\Seeders;

use Crm\ApplicationModule\Builder\ConfigBuilder;
use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Config\Repository\ConfigCategoriesRepository;
use Crm\ApplicationModule\Config\Repository\ConfigsRepository;
use Crm\ApplicationModule\Seeders\ISeeder;
use Nette\Database\Table\ActiveRow;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigsSeeder implements ISeeder
{
    private $configCategoriesRepository;

    private $configsRepository;

    private $configBuilder;

    /** @var OutputInterface */
    private $output;

    public function __construct(
        ConfigCategoriesRepository $configCategoriesRepository,
        ConfigsRepository $configsRepository,
        ConfigBuilder $configBuilder
    ) {
        $this->configCategoriesRepository = $configCategoriesRepository;
        $this->configsRepository = $configsRepository;
        $this->configBuilder = $configBuilder;
    }

    public function seed(OutputInterface $output)
    {
        $this->output = $output;

        $categoryName = 'payments.config.category';
        $category = $this->configCategoriesRepository->loadByName($categoryName);
        if (!$category) {
            $this->output->writeln("  * <error>config category <info>$categoryName</info> is missing. Is <info>PaymentsModule</info> enabled?</error>");
        }

        $sorting = 1700;
        $this->addPaymentConfig(
            $category,
            'google_play_billing_service_account_credentials_json',
            'google_play_billing.config.service_account.display_name',
            'google_play_billing.config.service_account.description',
            '',
            $sorting++
        );
    }

    private function addPaymentConfig(ActiveRow $category, string $name, string $displayName, string $description, string $value, int $sorting)
    {
        $config = $this->configsRepository->loadByName($name);
        if (!$config) {
            $this->configBuilder->createNew()
                ->setName($name)
                ->setDisplayName($displayName)
                ->setDescription($description)
                ->setValue($value)
                ->setType(ApplicationConfig::TYPE_STRING)
                ->setAutoload(true)
                ->setConfigCategory($category)
                ->setSorting($sorting)
                ->save();
            $this->output->writeln("  <comment>* config item <info>$name</info> created</comment>");
        } else {
            $this->output->writeln("  * config item <info>$name</info> exists");

            if ($config->has_default_value && $config->value !== $value) {
                $this->configsRepository->update($config, ['value' => $value, 'has_default_value' => true]);
                $this->output->writeln("  <comment>* config item <info>$name</info> updated</comment>");
            }

            if ($config->category->name != $category->name) {
                $this->configsRepository->update($config, [
                    'config_category_id' => $category->id
                ]);
                $this->output->writeln("  <comment>* config item <info>$name</info> updated</comment>");
            }
        }
    }
}
