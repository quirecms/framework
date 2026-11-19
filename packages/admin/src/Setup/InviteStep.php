<?php

declare(strict_types=1);

namespace Quire\Admin\Setup;

use Quire\Admin\Controller\UsersController;
use Quire\Setup\SetupStep;
use Quire\Setup\SetupStepInterface;
use Quire\User\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Invite someone to help": done once the site has a second account.
 *
 * @internal
 */
final readonly class InviteStep implements SetupStepInterface
{
    public function __construct(
        private Security $security,
        private UserRepository $users,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function step(): ?SetupStep
    {
        if (!$this->security->isGranted(UsersController::ABILITY)) {
            return null;
        }

        return new SetupStep(
            'quire-admin.invite',
            $this->translator->trans('Invite someone to help', [], 'quire-admin'),
            $this->translator->trans('They get an email to choose their own password.', [], 'quire-admin'),
            $this->users->search(limit: 1)['total'] > 1,
            $this->urls->generate('quire_admin_user_new'),
            $this->translator->trans('Invite', [], 'quire-admin'),
        );
    }
}
