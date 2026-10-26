<?php

declare(strict_types=1);

namespace Quire\Admin\Content\Job;

use Psr\Log\LoggerInterface;
use Quire\Admin\Content\ContentLists;
use Quire\Content\Content;
use Quire\Content\ContentRepository;
use Quire\Content\Type\ContentTypeRegistry;
use Quire\User\CurrentUser;
use Quire\User\User;
use Quire\User\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Acts as the user who chose the action, so it's allowed, refused and recorded in the audit log as if
 * they'd clicked it for each item.
 *
 * @internal
 */
#[AsMessageHandler]
final readonly class ApplyToMatchingHandler
{
    private const int BATCH = 100;

    public function __construct(
        private ContentRepository $content,
        private ContentTypeRegistry $types,
        private ContentLists $lists,
        private UserRepository $users,
        private CurrentUser $currentUser,
        private TokenStorageInterface $tokens,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApplyToMatching $job): void
    {
        $user = $this->users->find($job->userId);

        if (!$user instanceof User || User::ACTIVE !== $user->status || !$this->types->has($job->type)) {
            $this->logger->warning('A content list action for all matching items was dropped: its user or type is gone.', ['type' => $job->type, 'action' => $job->action]);

            return;
        }

        $previous = $this->tokens->getToken();
        $this->tokens->setToken(new UsernamePasswordToken($user, 'quire_job', $user->getRoles()));
        $this->currentUser->reset();

        try {
            $type = $this->types->get($job->type);
            // Which items first, then the action: it changes what the query finds, e.g. drafts published.
            $ids = [];
            $query = $this->content->queryFromArray($job->query);

            for ($page = 1;; ++$page) {
                $found = $query->paginate($page, self::BATCH);
                $ids = [...$ids, ...array_map(static fn (Content $item): string => $item->id, $found->items)];

                if (!$found->hasNextPage()) {
                    break;
                }
            }

            $done = 0;
            $failed = 0;

            foreach (array_chunk($ids, self::BATCH) as $batch) {
                $result = $this->lists->apply($type, $job->action, $batch);
                $done += $result['done'];
                $failed += \count($result['failed']);
            }

            $this->logger->info('"{action}" was taken on {done} of {count} {type} items; {failed} failed.', ['action' => $job->action, 'done' => $done, 'count' => \count($ids), 'type' => $job->type, 'failed' => $failed]);
        } finally {
            $this->tokens->setToken($previous);
            $this->currentUser->reset();
        }
    }
}
