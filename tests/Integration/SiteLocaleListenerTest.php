<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Doctrine\DBAL\Connection;
use Quire\Http\EventListener\SiteLocaleListener;
use Quire\Routing\ExtensionRouteLoader;
use Quire\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SiteLocaleListenerTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        self::getContainer()->get(Settings::class)->set('site.locale', 'de');
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testQuiresPagesAreInTheSitesLanguage(): void
    {
        self::assertSame('de', $this->localeOf(new Request(attributes: [ExtensionRouteLoader::QUIRE_ROUTE => true])));
    }

    public function testTheApplicationsOwnPagesAndLocaleRoutesKeepTheirs(): void
    {
        self::assertSame('en', $this->localeOf(new Request()), 'A page of the application Quire was added to.');
        self::assertSame('fr', $this->localeOf(new Request(attributes: [ExtensionRouteLoader::QUIRE_ROUTE => true, '_locale' => 'fr'])));
    }

    private function localeOf(Request $request): string
    {
        $request->setDefaultLocale('en');

        if ($request->attributes->has('_locale')) {
            $request->setLocale($request->attributes->getString('_locale'));
        }

        new SiteLocaleListener(self::getContainer()->get(Settings::class))(new RequestEvent(self::$kernel ?? throw new \LogicException('No kernel.'), $request, HttpKernelInterface::MAIN_REQUEST));

        return $request->getLocale();
    }
}
