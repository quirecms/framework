<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use Quire\Privacy\ConsentLog;
use Quire\Privacy\Internal\Job\PurgeConsentLog;
use Quire\Privacy\PrivacySettings;
use Quire\Privacy\Services;
use Quire\Settings\Exception\InvalidSettingValueException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Proof of consent: each choice, by its random ID, with what its visitor was told; only answers about
 * the services as they are now; kept for the owner's months, then removed.
 */
final class ConsentLogTest extends PrivacyTestCase
{
    private const string ID = '0123456789abcdef0123456789abcdef';

    public function testAChoiceIsKeptWithWhatItsVisitorWasTold(): void
    {
        $this->log()->record(self::ID, ['fixture-video' => $this->revision('fixture-video'), 'fixture-fonts' => false]);

        $choices = $this->log()->of(self::ID);

        self::assertCount(1, $choices);
        self::assertEquals(['fixture-video' => $this->revision('fixture-video'), 'fixture-fonts' => false], $choices[0]['answers']);
        self::assertSame(['fixture-video', 'fixture-fonts'], array_column($choices[0]['services'], 'id'), 'What visitors are asked about; not necessary services.');
        self::assertSame('Fixture Video Ltd', $choices[0]['services'][0]['vendor']);
        self::assertEquals([['name' => 'fixture_video_id', 'lifetime' => '1 year', 'purpose' => 'Recognises you across sites.'], ['name' => 'fixture_video_pref_*', 'lifetime' => 'The session', 'purpose' => 'Remembers the player\'s volume.']], $choices[0]['services'][0]['cookies']);
        self::assertSame([], $this->log()->of('fedcba9876543210fedcba9876543210'));
    }

    public function testWhatVisitorsWereToldIsKeptOncePerRevision(): void
    {
        $this->log()->record(self::ID, ['fixture-video' => false]);
        $this->log()->record(self::ID, ['fixture-video' => $this->revision('fixture-video')]);

        self::assertSame(1, $this->connection()->fetchOne('SELECT count(*) FROM quire.quire_privacy_declaration'));
        self::assertSame([$this->revision('fixture-video'), false], array_map(static fn (array $choice): string|false => $choice['answers']['fixture-video'], $this->log()->of(self::ID)), 'Newest first.');
    }

    /**
     * @return iterable<string, array{string, array<string, string|false>}>
     */
    public static function notAChoice(): iterable
    {
        yield 'an ID that isn\'t one' => ['visitor@example.com', ['fixture-video' => false]];
        yield 'an upper-case ID' => [strtoupper(self::ID), ['fixture-video' => false]];
        yield 'no answers' => [self::ID, []];
        yield 'an unknown service' => [self::ID, ['acme-tracker' => false]];
        yield 'a necessary service' => [self::ID, ['fixture-spam-check' => false]];
        yield 'a "yes" to another revision' => [self::ID, ['fixture-video' => 'abcdefabcdef']];
    }

    /**
     * @param array<string, string|false> $answers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notAChoice')]
    public function testOnlyAChoiceAboutTheServicesAsTheyAreIsKept(string $id, array $answers): void
    {
        try {
            $this->log()->record($id, $answers);
            self::fail('Recorded.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM quire.quire_privacy_consent'));
        }
    }

    public function testTheTotalsCountAnswersSinceATime(): void
    {
        $yes = $this->revision('fixture-video');
        $this->log()->record(self::ID, ['fixture-video' => $yes, 'fixture-fonts' => false]);
        $this->log()->record('11111111111111111111111111111111', ['fixture-video' => $yes]);
        $this->log()->record('22222222222222222222222222222222', ['fixture-video' => false]);
        $this->age('22222222222222222222222222222222', '-40 days');

        self::assertSame(['fixture-fonts' => ['allowed' => 0, 'refused' => 1], 'fixture-video' => ['allowed' => 2, 'refused' => 0]], $this->sorted($this->log()->totals(new \DateTimeImmutable('-30 days'))));
    }

    public function testTheDailyJobRemovesChoicesOlderThanTheOwnersMonths(): void
    {
        $this->setSetting(PrivacySettings::LOG_MONTHS, 6);
        $this->log()->record(self::ID, ['fixture-video' => false]);
        $this->log()->record('11111111111111111111111111111111', ['fixture-video' => false]);
        $this->age('11111111111111111111111111111111', '-7 months');
        // What visitors were told only by a removed choice goes with it; what they're told now stays.
        $this->connection()->executeStatement("INSERT INTO quire.quire_privacy_declaration (revision, services) VALUES ('old', '[]')");
        $this->connection()->executeStatement("UPDATE quire.quire_privacy_consent SET revision = 'old' WHERE consent_id = '11111111111111111111111111111111'");

        $this->service(MessageBusInterface::class)->dispatch(new PurgeConsentLog());
        $this->runJobs();

        self::assertSame([self::ID], $this->connection()->fetchFirstColumn('SELECT consent_id FROM quire.quire_privacy_consent'));
        self::assertSame([$this->service(Services::class)->revision()], $this->connection()->fetchFirstColumn('SELECT revision FROM quire.quire_privacy_declaration'));
    }

    /**
     * @return iterable<string, array{int, int, int, int}>
     */
    public static function proofOutlivesTheChoice(): iterable
    {
        // log_months, choice_months, ask_again_months, the longest a choice's cookie lasts (days)
        yield 'the defaults' => [12, 6, 6, 186];
        yield 'choices kept a year' => [12, 12, 6, 372];
        yield 'a shorter log than choices' => [1, 12, 6, 372];
        yield 'refusals kept longest' => [1, 3, 9, 279];
    }

    /**
     * A choice holds while its cookie lasts (its months of 31 days): its proof is never removed before.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('proofOutlivesTheChoice')]
    public function testTheProofIsKeptWhileTheChoiceCanHold(int $log, int $choice, int $again, int $days): void
    {
        $this->setSetting(PrivacySettings::LOG_MONTHS, $log);
        $this->setSetting(PrivacySettings::CHOICE_MONTHS, $choice);
        $this->setSetting(PrivacySettings::ASK_AGAIN_MONTHS, $again);
        $this->log()->record(self::ID, ['fixture-video' => false]);
        $this->log()->record('11111111111111111111111111111111', ['fixture-video' => false]);
        $this->age(self::ID, \sprintf('-%d days +1 hour', $days));
        $this->age('11111111111111111111111111111111', \sprintf('-%d days', max($days, $log * 31) + 1));

        $this->service(MessageBusInterface::class)->dispatch(new PurgeConsentLog());
        $this->runJobs();

        self::assertSame([self::ID], $this->connection()->fetchFirstColumn('SELECT consent_id FROM quire.quire_privacy_consent'), 'Kept while it can hold; removed after both the owner\'s months and the choice.');
    }

    public function testThePurgeKeepsWhatVisitorsAreToldNowEvenWithNoChoiceLeft(): void
    {
        $this->log()->record(self::ID, ['fixture-video' => false]);

        self::assertSame(1, $this->log()->purge(new \DateTimeImmutable('+1 minute')));
        self::assertSame(1, $this->connection()->fetchOne('SELECT count(*) FROM quire.quire_privacy_declaration'));
    }

    public function testTheOwnersMonthsStayWithinFiveYears(): void
    {
        $this->expectException(InvalidSettingValueException::class);

        $this->setSetting(PrivacySettings::LOG_MONTHS, PrivacySettings::MAX_LOG_MONTHS + 1);
    }

    private function log(): ConsentLog
    {
        return $this->service(ConsentLog::class);
    }

    private function revision(string $service): string
    {
        return $this->service(Services::class)->get($service)?->revision() ?? self::fail('No '.$service);
    }

    private function age(string $consentId, string $by): void
    {
        $this->connection()->executeStatement('UPDATE quire.quire_privacy_consent SET created_at = ? WHERE consent_id = ?', [new \DateTimeImmutable($by)->format(\DATE_ATOM), $consentId]);
    }

    /**
     * @param array<string, array{allowed: int, refused: int}> $totals
     *
     * @return array<string, array{allowed: int, refused: int}>
     */
    private function sorted(array $totals): array
    {
        ksort($totals);

        return $totals;
    }
}
