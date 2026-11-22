<?php

declare(strict_types=1);

namespace Quire\Privacy\Tests;

use Quire\Admin\Audit\AuditEntries;
use Quire\Audit\AuditLog;
use Quire\Testing\AdminTestCase;

/**
 * An extension describes its own actions in the activity log with its own texts: "audit.<action>" in its
 * domain (its ID), never in the admin's, which only the admin's package writes. An action named after an
 * extension that isn't installed is described from the admin's texts, as core's are.
 */
final class AuditTextsTest extends AdminTestCase
{
    #[\Override]
    protected static array $extensions = ['quire-admin', 'quire-privacy'];

    public function testAnExtensionsActionsAreDescribedInItsOwnTexts(): void
    {
        $translator = self::getContainer()->get('translator');
        $texts = $translator->getCatalogue('en');
        $texts->set('audit.quire-privacy.policy_checked', 'Checked the policy of {subject}', 'quire-privacy+intl-icu');
        $texts->set('audit.quire-privacy.policy_checked', 'From the admin’s texts', 'quire-admin+intl-icu');
        $texts->set('audit.acme-shop.order_refunded', 'Refunded {subject}', 'quire-admin+intl-icu');

        $log = $this->service(AuditLog::class);
        $entries = $this->service(AuditEntries::class);
        $own = $log->record('quire-privacy.policy_checked', 'page', 'privacy', 'Privacy');
        $other = $log->record('acme-shop.order_refunded', 'acme-shop.order', '7', 'Order 7');
        self::assertNotNull($own);
        self::assertNotNull($other);

        self::assertSame('Checked the policy of Privacy', $entries->summary($own));
        self::assertSame('Refunded Order 7', $entries->summary($other), 'acme-shop isn\'t installed: the admin\'s texts.');
    }
}
