<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Quire\Permission\ViewAs;
use Quire\Testing\AdminTestCase;
use Quire\User\Role;

/**
 * Seeing the site and the admin as another user, without being able to change anything.
 */
final class ViewAsTest extends AdminTestCase
{
    public function testAnAdminSeesTheAdminAsAnEditorWithoutChangingAnything(): void
    {
        $admin = $this->signIn('admin');
        $kim = $this->createUser('Kim Ono', [Role::EDITOR]);

        // From Kim's page in the admin.
        $crawler = $this->client->request('GET', '/admin/users/'.$kim->id);
        $this->client->submit($crawler->selectButton('See the admin as Kim')->form());
        self::assertResponseRedirects('/admin', 303);
        $this->assertAudited('user.viewed_as', $kim->id);

        $crawler = $this->client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.q-account .q-menu__label', 'Kim Ono', 'As Kim.');
        self::assertNotContains('Site', $crawler->filter('.q-rail__item')->each(static fn ($item): string => trim($item->text())), 'With Kim\'s menu.');
        $this->client->request('GET', '/admin/settings');
        self::assertResponseStatusCodeSame(403, 'Kim may not change settings.');

        // Nothing changes while seeing as them, not even what Kim may do.
        $this->client->request('POST', '/admin/content/post/new', ['title' => 'By Kim?', '_token' => $this->csrfToken('quire_admin_content_editor')]);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('You\'re seeing the site as Kim Ono, so nothing can be changed.', (string) $this->client->getResponse()->getContent());

        // Every page says so, the site's too, and stopping is let through: they're themselves again.
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorTextContains('.quire-view-as', 'You’re seeing this as Kim Ono: nothing can be changed.');
        $this->client->submit($crawler->selectButton('Stop seeing as Kim Ono')->form());
        self::assertResponseRedirects('/', 303);
        $this->client->request('GET', '/admin');
        self::assertSelectorTextSame('.q-account .q-menu__label', $admin->displayName);
        self::assertSelectorNotExists('.quire-view-as');

        // Someone who can't open the admin is seen on the site.
        $crawler = $this->client->request('GET', '/admin/users/'.$this->createUser('Mia Ruiz', [Role::MEMBER])->id);
        self::assertSame('/', $crawler->selectButton('See the site as Mia')->form()->getValues()['back'] ?? null);
    }

    public function testNobodySeesMoreThanTheyMay(): void
    {
        $viewAs = function (string $user, string $token): int {
            $this->client->request('POST', '/_quire/view-as', ['_token' => $token, 'user' => $user]);

            return $this->client->getResponse()->getStatusCode();
        };
        $boss = $this->createUser('Boss', [Role::ADMIN]);
        $editor = $this->signIn('editor');

        self::assertSame(403, $viewAs($boss->id, $this->csrfToken('quire_view_as')), 'Editors don\'t have the ability.');

        $this->service(\Quire\Permission\Permissions::class)->grant(Role::EDITOR, [ViewAs::ABILITY]);
        self::assertSame(403, $viewAs($boss->id, $this->csrfToken('quire_view_as')), 'Admins may do more than editors.');
        self::assertStringContainsString('They may do what you may not', (string) $this->client->getResponse()->getContent());
        self::assertSame(403, $viewAs($editor->id, $this->csrfToken('quire_view_as')), 'Themselves.');
        self::assertSame(403, $viewAs($this->createUser('Member', [Role::MEMBER])->id, 'wrong'), 'Without the form\'s token.');
        self::assertSame(303, $viewAs($this->createUser('Mia', [Role::MEMBER])->id, $this->csrfToken('quire_view_as')), 'A member, who may do less.');
    }
}
