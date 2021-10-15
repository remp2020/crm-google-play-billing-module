<?php

namespace Crm\GooglePlayBillingModule\Commands;

use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\GooglePlayBillingModule\Repository\DeveloperNotificationsRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tomaj\Hermes\Emitter;

class RevalidateDeveloperNotificationCommand extends Command
{
    private $developerNotificationsRepository;

    private $hermesEmitter;

    public function __construct(
        DeveloperNotificationsRepository $developerNotificationsRepository,
        Emitter $hermesEmitter
    ) {
        parent::__construct();
        $this->developerNotificationsRepository = $developerNotificationsRepository;
        $this->hermesEmitter = $hermesEmitter;
    }

    protected function configure()
    {
        $this->setName('google:revalidate-developer-notification')
            ->addOption(
                'id',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                "ID of Developer Notification to revalidate."
            )
            ->setHelp(<<<EOH
This command triggers revalidation of developer notification against Google Billing. Use this command in case that
processing of developer notification was interrupted or failed because of an internal error. If payment/subscription
is missing, a revalidation process should create it.

---

Note: Command doesn't process developer notification immediately.
      Status of developer notification provided with <comment>`--id={id}`</comment> is set to <comment>`new`<comment>
      and hermes message <comment>`developer-notification-received`</comment> with this developer notification is emitted.
EOH
            )
            ->addUsage('--id=12345678')
            ->setDescription('Revalidate developer notification');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('');
        $output->writeln('<info>***** REVALIDATE DEVELOPER NOTIFICATION *****</info>');
        $output->writeln('');

        $developerNotificationsIds = $input->getOption('id');


        $developerNotifications = [];
        foreach ($developerNotificationsIds as $developerNotificationId) {
            $developerNotification = $this->developerNotificationsRepository->find($developerNotificationId);
            if (!$developerNotification) {
                $output->writeln("<error>Developer Notification with ID<comment> {$developerNotificationId} </comment>not found.</error>");
                // return failure if one of IDs is invalid
                // if case of revalidation of sequence of IDs, missing one developer notification (because of typo) could cause more problems
                return Command::FAILURE;
            }

            $developerNotifications[] = $developerNotification;
        }

        foreach ($developerNotifications as $developerNotification) {
            $this->developerNotificationsRepository->updateStatus($developerNotification, DeveloperNotificationsRepository::STATUS_NEW);

            $this->hermesEmitter->emit(new HermesMessage('developer-notification-received', [
                    'developer_notification_id' => $developerNotification->id,
                ]), HermesMessage::PRIORITY_HIGH);

            $output->writeln(" * Developer Notification with ID <comment>{$developerNotification->id}</comment> was <comment>queued for revalidation</comment>.");
        }

        $output->writeln("\nDone.");
        return Command::SUCCESS;
    }
}
