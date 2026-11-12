<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use Quire\Privacy\Internal\Lint\BannerParityCheck;
use Quire\Privacy\Internal\Lint\ConsentLoadsCheck;
use Quire\Privacy\Internal\Lint\ConsentLoadsPass;
use Quire\Privacy\Services;
use Quire\Theme\ThemeProblem;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Twig\Environment;

/**
 * Templates that would load a service visitors are asked about before they consent stop the site from
 * being built, and "quire:theme:lint" names them; a theme's own banner keeps the choice fair.
 */
final class ThemeChecksTest extends PrivacyTestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/quire-theme-check-'.bin2hex(random_bytes(4));
        mkdir($this->directory.'/extensions/quire_privacy', recursive: true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach (['/*.twig', '/extensions/quire_privacy/*.twig'] as $pattern) {
            $files = glob($this->directory.$pattern);

            foreach (false === $files ? [] : $files as $file) {
                unlink($file);
            }
        }
        rmdir($this->directory.'/extensions/quire_privacy');
        rmdir($this->directory.'/extensions');
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testATemplateThatLoadsAServiceBeforeConsentIsAnError(): void
    {
        $this->template('layout.html.twig', "<head>\n<link rel=\"preconnect\" href=\"https://fonts.example\">\n<script src=\"https://spam-check.example/c.js\"></script>\n</head>");

        $problems = $this->service(ConsentLoadsCheck::class)->check('acme', ['layout.html.twig' => $this->directory.'/layout.html.twig']);

        self::assertSame([true], array_map(static fn (ThemeProblem $problem): bool => $problem->error, $problems), 'The necessary service may load; the font service visitors are asked about may not.');
        self::assertStringStartsWith('layout.html.twig line 2: loads https://fonts.example, from "Fixture Fonts" (fixture-fonts), which visitors are asked about: it would load before they consent.', $problems[0]->message);
    }

    public function testTheSiteIsntBuiltWithOne(): void
    {
        $this->template('layout.html.twig', '<iframe src="https://player.video.example/embed/1"></iframe>');
        $container = new ContainerBuilder();
        $container->register('twig.loader.native_filesystem')->addMethodCall('addPath', [$this->directory]);
        $container->setParameter(Services::PARAMETER, self::getContainer()->getParameter(Services::PARAMETER));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('~load services visitors are asked about before they consent:\n- .*layout\.html\.twig line 1: loads https://player\.video\.example/embed/1, from "Fixture Video"~');

        new ConsentLoadsPass()->process($container);
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testNorWithoutOne(): void
    {
        $this->template('layout.html.twig', '<img src="/logo.png" alt=""><a href="https://player.video.example/watch">A link loads nothing</a>');
        $container = new ContainerBuilder();
        $container->register('twig.loader.native_filesystem')->addMethodCall('addPath', [$this->directory]);
        $container->setParameter(Services::PARAMETER, self::getContainer()->getParameter(Services::PARAMETER));

        new ConsentLoadsPass()->process($container);
    }

    public function testQuiresOwnBannerIsFair(): void
    {
        $html = $this->service(Environment::class)->render('@quire_privacy/consent.html.twig', [
            'mode' => 'several', 'category' => \Quire\Privacy\Category::Marketing, 'layout' => 'bar', 'reject' => 'reject', 'test' => false, 'privacy_page' => null,
            'categories' => [], 'config' => [], 'loader' => '/consent.js',
        ]);

        self::assertSame([], $this->service(BannerParityCheck::class)->rules($html));
    }

    public function testAThemesOwnBannerThatKeepsTheRulesPasses(): void
    {
        $this->template('extensions/quire_privacy/consent.html.twig', "{% extends '@!quire_privacy/consent.html.twig' %}\n{% block consent_heading %}Cookies, please{% endblock %}");

        self::assertSame([], $this->parity());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unfair(): iterable
    {
        $actions = static fn (string $html): string => "{% extends '@!quire_privacy/consent.html.twig' %}\n{% block consent_actions %}".$html.'{% endblock %}';

        yield 'rejecting is a link' => [$actions('<button class="consent__btn" data-consent-accept>Accept</button><a class="consent__btn" href="#" data-consent-reject>Reject</a>'), 'must both be buttons'];
        yield 'accepting stands out' => [$actions('<button class="consent__btn consent__btn--primary" data-consent-accept>Accept</button><button class="consent__btn" data-consent-reject>Reject</button>'), 'have different classes'];
        yield 'no rejecting' => [$actions('<button class="consent__btn" data-consent-accept>Accept</button>'), '1 accept and 0 reject buttons'];
        yield 'rejecting hidden' => [$actions('<button class="consent__btn" data-consent-accept>Accept</button><span hidden><button class="consent__btn" data-consent-reject>Reject</button></span>'), 'rejecting is hidden'];
        yield 'rejecting without words' => [$actions('<button class="consent__btn" data-consent-accept>Accept</button><button class="consent__btn" data-consent-reject aria-label="Reject"></button>'), 'rejecting has no words'];
        yield 'no way to the details' => ["{% extends '@!quire_privacy/consent.html.twig' %}\n{% block consent_details_link %}{% endblock %}", 'no way to the details'];
        yield 'settings without saving' => ["{% extends '@!quire_privacy/consent.html.twig' %}\n{% block consent_dialog %}<dialog data-consent-dialog><button data-consent-reject>No</button></dialog>{% endblock %}", 'need a button to save'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unfair')]
    public function testAThemesOwnBannerThatBreaksARuleIsAnError(string $template, string $rule): void
    {
        $this->template('extensions/quire_privacy/consent.html.twig', $template);

        $problems = $this->parity();

        self::assertNotSame([], $problems);
        self::assertTrue($problems[0]->error);
        self::assertStringContainsString($rule, implode("\n", array_map(static fn (ThemeProblem $problem): string => $problem->message, $problems)));
    }

    /**
     * @return list<ThemeProblem>
     */
    private function parity(): array
    {
        return $this->service(BannerParityCheck::class)->check('acme', [BannerParityCheck::TEMPLATE => $this->directory.'/'.BannerParityCheck::TEMPLATE]);
    }

    private function template(string $name, string $source): void
    {
        file_put_contents($this->directory.'/'.$name, $source);
    }
}
