<?php

namespace Crm\GooglePlayBillingModule\DI;

use Kdyby\Translation\DI\ITranslationProvider;
use Nette\DI\CompilerExtension;

final class GooglePlayBillingModuleExtension extends CompilerExtension implements ITranslationProvider
{
    public function loadConfiguration()
    {
        // load services from config and register them to Nette\DI Container
        $this->compiler->loadDefinitionsFromConfig(
            $this->loadFromFile(__DIR__.'/../config/config.neon')['services']
        );
    }

    /**
     * Return array of directories, that contain resources for translator.
     * @return string[]
     */
    public function getTranslationResources()
    {
        return [__DIR__ . '/../lang/'];
    }
}
