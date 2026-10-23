<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Quire\QuireBundle;
use Quire\Testing\TestInstallation;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * An application with users of its own, like Cupmanager, that Quire is installed in: its firewall signs
 * in its users, and Quire maps their roles ("quire.users.host").
 */
final class HostAppKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new SecurityBundle();
        yield new QuireBundle();
    }

    public function getProjectDir(): string
    {
        return TestInstallation::projectDir();
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/host-app-test';
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir().'/var/log';
    }

    public function tournaments(): Response
    {
        return new Response('The application\'s tournaments');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'host-app-test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'mailer' => ['dsn' => 'null://null'],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('framework', ['asset_mapper' => ['paths' => ['assets/']]]);
        $container->extension('doctrine', ['dbal' => ['url' => '%env(resolve:DATABASE_URL)%', 'dbname_suffix' => '_test']]);

        // The application's own users, roles and way to sign in.
        $container->extension('security', [
            'providers' => ['organizers' => ['id' => HostUserProvider::class]],
            'role_hierarchy' => ['ROLE_OWNER' => ['ROLE_ORGANIZER']],
            'firewalls' => ['main' => ['lazy' => true, 'provider' => 'organizers', 'http_basic' => true]],
        ]);

        $container->services()->set(HostUserProvider::class);

        $container->extension('quire', [
            'extensions' => ['pinned' => true, 'enabled' => [], 'installed_json' => TestInstallation::writeInstalledJson($this->getCacheDir())],
            'users' => ['host' => ['roles' => ['ROLE_ORGANIZER' => 'editor', 'ROLE_OWNER' => ['admin']]]],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('app_tournaments', '/tournaments')->controller($this->tournaments(...));
        $routes->import('@QuireBundle/config/routes.php');
    }
}
