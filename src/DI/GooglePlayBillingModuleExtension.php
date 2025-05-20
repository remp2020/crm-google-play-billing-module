<?php

namespace Crm\GooglePlayBillingModule\DI;

use Contributte\Translation\DI\TranslationProviderInterface;
use Nette\DI\CompilerExtension;

final class GooglePlayBillingModuleExtension extends CompilerExtension implements TranslationProviderInterface
{
    public function loadConfiguration()
    {
        // load services from config and register them to Nette\DI Container
        $this->compiler->loadDefinitionsFromConfig(
            $this->loadFromFile(__DIR__.'/../config/config.neon')['services'],
        );
    }

    /**
     * Return array of directories, that contain resources for translator.
     * @return string[]
     */
    public function getTranslationResources(): array
    {
        return [__DIR__ . '/../lang/'];
    }
}
