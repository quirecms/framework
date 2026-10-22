<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomepageTest extends WebTestCase
{
    public function testAFreshSiteListsItsPostsOnTheHomepage(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('header a[href="/"]', 'Quire');
        self::assertSelectorTextContains('main', 'No posts yet.');
    }
}
