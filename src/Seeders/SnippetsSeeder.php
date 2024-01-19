<?php

namespace Crm\GooglePlayBillingModule\Seeders;

use Crm\ApplicationModule\Repositories\SnippetsRepository;
use Crm\ApplicationModule\Seeders\ISeeder;
use Symfony\Component\Console\Output\OutputInterface;

class SnippetsSeeder implements ISeeder
{
    private $snippetsRepository;

    public function __construct(SnippetsRepository $snippetsRepository)
    {
        $this->snippetsRepository = $snippetsRepository;
    }

    public function seed(OutputInterface $output)
    {
        $sorting = 1100;
        foreach (glob(__DIR__ . '/snippets/*.html') as $filename) {
            $info = pathinfo($filename);
            $key = $info['filename'];

            $snippet = $this->snippetsRepository->findBy('identifier', $key);
            $value = file_get_contents($filename);

            if (!$snippet) {
                $this->snippetsRepository->add($key, $key, $value, $sorting++, true, true);
                $output->writeln('  <comment>* snippet <info>' . $key . '</info> created</comment>');
            } elseif ($snippet->has_default_value && $snippet->html !== $value) {
                $this->snippetsRepository->update($snippet, ['html' => $value, 'has_default_value' => true]);
                $output->writeln('  <comment>* snippet <info>' . $key . '</info> updated</comment>');
            } else {
                $output->writeln('  * snippet <info>' . $key . '</info> exists');
            }
        }
    }
}
