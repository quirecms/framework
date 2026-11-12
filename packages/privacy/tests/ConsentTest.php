<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use Quire\Privacy\Consent;
use Quire\Privacy\PrivacySettings;
use Symfony\Component\HttpFoundation\Request;

/**
 * The visitor's choice on the server, with the fixture's services: "fixture-video" (marketing) and
 * "fixture-fonts" (preferences).
 */
final class ConsentTest extends PrivacyTestCase
{
    private const string MADE = '2026-11-01 12:00:00';

    public function testTheVisitorsChoiceByService(): void
    {
        $request = $this->request(['fixture-video' => true, 'fixture-fonts' => false]);

        self::assertTrue($this->consent()->allows($request, 'fixture-video', $this->at('2026-11-12')));
        self::assertFalse($this->consent()->allows($request, 'fixture-fonts', $this->at('2026-11-12')));
        self::assertFalse($this->consent()->allows($request, 'unknown', $this->at('2026-11-12')), 'An unknown service never runs.');
        self::assertFalse($this->consent()->allows(new Request(), 'fixture-video'), 'Nothing without a choice.');
    }

    public function testAChoiceHoldsForItsMonths(): void
    {
        $accepted = $this->request(['fixture-video' => true]);
        $rejected = $this->request(['fixture-video' => false]);

        self::assertNotNull($this->consent(choice: 6, again: 2)->choice($accepted, $this->at('2027-04-30')));
        self::assertNull($this->consent(choice: 6, again: 2)->choice($accepted, $this->at('2027-05-02')), 'Asked again after 6 months.');
        self::assertNull($this->consent(choice: 6, again: 2)->choice($rejected, $this->at('2027-01-02')), 'A refusal is asked again sooner, as set.');
    }

    public function testBrowsersThatAskNotToBeTrackedRejectWhenTheSiteHonoursIt(): void
    {
        $request = $this->request(['fixture-video' => true]);
        $request->headers->set('Sec-GPC', '1');

        self::assertFalse($this->consent()->allows($request, 'fixture-video', $this->at('2026-11-12')));
        self::assertTrue($this->consent(signals: false)->allows($request, 'fixture-video', $this->at('2026-11-12')));
    }

    public function testACookieThatIsntAChoiceIsNone(): void
    {
        foreach (['garbage', base64_encode('{"v":2}'), base64_encode('[]'), str_repeat('a', 5000)] as $value) {
            self::assertNull($this->consent()->choice(new Request(cookies: [Consent::COOKIE => $value])));
        }
    }

    /**
     * @param array<string, bool> $services
     */
    private function request(array $services): Request
    {
        $value = rtrim(strtr(base64_encode(json_encode(['v' => 1, 'id' => '0123abcd', 't' => new \DateTimeImmutable(self::MADE)->getTimestamp(), 'r' => 'abc', 's' => $services], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        return new Request(cookies: [Consent::COOKIE => $value]);
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time);
    }

    private function consent(int $choice = 6, int $again = 6, bool $signals = true): Consent
    {
        $this->setSetting(PrivacySettings::CHOICE_MONTHS, $choice);
        $this->setSetting(PrivacySettings::ASK_AGAIN_MONTHS, $again);
        $this->setSetting(PrivacySettings::HONOUR_SIGNALS, $signals);

        return $this->service(Consent::class);
    }
}
