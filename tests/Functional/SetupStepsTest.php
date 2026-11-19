<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Quire\Setup\SetupStep;
use Quire\Setup\SetupSteps;
use Quire\User\CreateUser;
use Quire\User\UserWriter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * One list of setup steps for the admin's home and a theme's empty home: the viewer's own, derived
 * from the site, and a page that shows it is theirs alone.
 */
final class SetupStepsTest extends WebTestCase
{
    public function testAVisitorGetsNoStepsAndAnAdminTheirsWithTheResponsePrivate(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();

        try {
            $request = Request::create('/');
            $requests = self::getContainer()->get(RequestStack::class);
            $requests->push($request);
            /** @var SetupSteps $steps */
            $steps = self::getContainer()->get(SetupSteps::class);

            self::assertNull($steps->pending(), 'A visitor may take no step.');
            self::assertFalse($request->attributes->has(SetupSteps::PRIVATE_ATTRIBUTE));

            $admin = self::getContainer()->get(UserWriter::class)->create(new CreateUser('Ada', 'ada@example.com', roles: ['admin']));
            self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($admin, 'main', $admin->getRoles()));
            $pending = $steps->pending();

            self::assertNotNull($pending);
            self::assertSame(['quire-admin.site-name', 'quire-admin.first-post', 'quire-admin.invite', 'quire-admin.theme'], array_map(static fn (SetupStep $step): string => $step->id, $pending['steps']), 'The admin\'s steps, in their order.');
            self::assertSame(0, $pending['done']);

            $response = new Response('<p>Home</p>');
            $kernel = self::$kernel;
            self::assertNotNull($kernel);
            self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response), KernelEvents::RESPONSE);
            self::assertTrue($response->headers->hasCacheControlDirective('private'), 'Shown to its viewer only.');
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        } finally {
            self::getContainer()->get(TokenStorageInterface::class)->setToken(null);
            $connection->rollBack();
        }
    }
}
