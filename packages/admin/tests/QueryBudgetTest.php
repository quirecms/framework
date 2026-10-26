<?php

declare(strict_types=1);

namespace Quire\Admin\Tests;

use Quire\Audit\AuditLog;
use Quire\Testing\AdminTestCase;
use Quire\Testing\Database\QueryLog;
use Quire\User\Role;

/**
 * Budgets for the admin's screens: each takes at most so many queries, and as many for a little data as
 * for a lot (no query per row). The content list has its own, in ContentScreensTest.
 */
final class QueryBudgetTest extends AdminTestCase
{
    /** Rows made so far, so each made later is numbered on (titles and slugs stay unique) */
    private int $made = 0;

    public function testHome(): void
    {
        $this->setSetting('quire.content.changes_need_approval', true);
        $this->signIn('admin');
        $editor = $this->createUser('Grace Hopper', [Role::EDITOR]);
        $grow = function (int $from, int $to) use ($editor): void {
            foreach (range($from, $to) as $number) {
                $post = $this->content('post', 'Post '.$number)->body('Text '.$number)->published()->create();
                $this->changes($post)->by($editor)->body('Changed '.$number)->submitted()->create();
                $this->content('post', 'Soon '.$number)->body('![](/'.$number.'.png)')->scheduled(new \DateTimeImmutable('+'.$number.' hours'))->create();
            }
        };

        $this->assertBudget('/admin', 35, $grow);
    }

    public function testPeople(): void
    {
        $this->signIn('admin');

        $this->assertBudget('/admin/users', 15, function (int $from, int $to): void {
            foreach (range($from, $to) as $number) {
                $this->createUser('Person '.$number, [0 === $number % 2 ? Role::EDITOR : Role::MEMBER]);
            }
        });
    }

    public function testActivity(): void
    {
        $this->signIn('admin');

        // About real items and people, each the latest about them, so each can be undone.
        $grow = function (int $from, int $to): void {
            $log = $this->service(AuditLog::class);

            foreach (range($from, $to) as $number) {
                $post = $this->content('post', 'Post '.$number)->body('Text.')->published()->create();
                $log->record('content.transitioned', 'content', $post->id, $post->title, ['transition' => 'publish', 'from' => 'draft', 'to' => 'published']);
                $person = $this->createUser('Person '.$number);
                $log->record('user.activated', 'user', $person->id, $person->displayName);
            }
        };
        $grow(1, 2);
        $few = $this->queries('/admin/audit');
        self::assertLessThanOrEqual(20, $few, 'A budget for the activity page.');

        // Whether each may be undone is the undoing action's to say (Actions::denied()), and taking a
        // transition back loads its item: one query per item that can still be undone, none for the rest.
        $grow(3, 20);
        self::assertLessThanOrEqual($few + 18, $this->queries('/admin/audit'));
    }

    public function testTheEditorAndTheReview(): void
    {
        $this->setSetting('quire.content.changes_need_approval', true);
        $admin = $this->signIn('admin');
        $editor = $this->createUser('Grace Hopper', [Role::EDITOR]);
        $post = $this->content('post', 'Spring draw')->body("Sixteen teams.\n\nFour groups.")->published()->create();
        $pending = $this->changes($post)->by($editor)->body("Sixteen teams, four groups.\n\nFour groups.")->submitted()->create();
        $comments = function (int $from, int $to) use ($post, $admin): void {
            foreach (range($from, $to) as $number) {
                $this->changes($post)->commentedBy($admin, 'Note '.$number)->create();
            }
        };

        $this->assertBudget('/admin/content/post/'.$post->id, 20, $comments);
        $this->assertBudget('/admin/content/post/'.$post->id.'/changes/'.$pending->id, 20, $comments);
    }

    public function testSettingsAndFinding(): void
    {
        $this->signIn('admin');
        $grow = function (int $from, int $to): void {
            foreach (range($from, $to) as $number) {
                $this->content('post', 'Draw '.$number)->body('The draw.')->create();
                $this->createUser('Drew '.$number);
            }
        };

        self::assertLessThanOrEqual(10, $this->queries('/admin/settings'), 'The settings document.');
        $this->assertBudget('/admin/find?q=draw', 15, $grow);
        $this->assertBudget('/admin/find?q='.rawurlencode('publish draw 1'), 15, $grow);
    }

    /**
     * The page takes at most $budget queries, and no more after $grow added ten times the data (one fewer
     * is fine: the first request looks up a few things once).
     *
     * @param callable(int, int): void $grow Adds the rows numbered $from to $to
     */
    private function assertBudget(string $url, int $budget, callable $grow): void
    {
        $grow($this->made + 1, $this->made + 2);
        $few = $this->queries($url);
        self::assertLessThanOrEqual($budget, $few, \sprintf('%s: a budget of %d queries.', $url, $budget));

        $grow($this->made + 3, $this->made + 20);
        $this->made += 20;
        self::assertLessThanOrEqual($few, $this->queries($url), \sprintf('%s: as many queries for more rows.', $url));
    }

    private function queries(string $url): int
    {
        $log = $this->service(QueryLog::class);
        $log->start();
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return \count($log->stop());
    }
}
