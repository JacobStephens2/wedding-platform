<?php

declare(strict_types=1);

namespace Wedding\Tests;

use PHPUnit\Framework\TestCase;

final class GiftOverviewPageTest extends TestCase
{
    public function testActualSamplePagePreservesCardsFiltersAndEditingDestinationsWithoutDatabaseOrConfiguration(): void
    {
        $process = proc_open([PHP_BINARY, __DIR__ . '/support/render-gifts.php'], [
            1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);
        $html = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), (string) $stderr);
        $this->assertSame('', $stderr);
        $this->assertIsString($html);
        $document = new \DOMDocument();
        $this->assertTrue($document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new \DOMXPath($document);

        $cards = $xpath->query('//div[@class="stat-card"]');
        $this->assertNotFalse($cards);
        $actual = [];
        foreach ($cards as $card) {
            $this->assertInstanceOf(\DOMElement::class, $card);
            $label = $this->element($xpath, './/span[@class="stat-label"]', $card);
            $value = $this->element($xpath, './/span[@class="stat-value"]', $card);
            $actual[trim($label->textContent)] = trim($value->textContent);
        }
        $this->assertSame([
            'Total gifts' => '12', 'Awaiting delivery' => '1', 'Thank-you completed' => '3',
            'Written' => '6', 'Sent' => '3', 'Outstanding' => '9', 'Total gift value' => '$1,456.00',
            'Registry value' => '$311.00', 'Off-registry value' => '$0.00',
            'Honeymoon fund value' => '$495.00', 'Cash gifts' => '$200.00',
        ], $actual);
        $rows = $xpath->query('//tr[contains(@class,"gift-row")]');
        $this->assertNotFalse($rows);
        $this->assertCount(12, $rows);

        $toggleLinks = $xpath->query('//a[contains(@href,"toggle_")]');
        $this->assertNotFalse($toggleLinks);
        $this->assertCount(0, $toggleLinks);

        $cash = $this->element($xpath, '//tr[contains(@class,"source-cash")]');
        $this->assertSame('cash gift sample grandparents tucked into a handwritten card on the wedding day. cash off-registry off registry', $cash->getAttribute('data-search'));
        $this->assertSame('yes', $cash->getAttribute('data-received'));
        $this->assertSame('b', $cash->getAttribute('data-sort-source'));
        $this->assertSame('3', $cash->getAttribute('data-sort-status'));
        $this->assertSame('200', $cash->getAttribute('data-sort-price'));
        $this->assertSame('Cash', trim($this->element($xpath, './/span[contains(@class,"badge-source")]', $cash)->textContent));
        $value = $this->element($xpath, './/input[@data-gift-id="802"]', $cash);
        $this->assertSame('200.00', $value->getAttribute('value'));
        $this->assertTrue($value->hasAttribute('readonly'));
        foreach (['written', 'sent'] as $stage) {
            $button = $this->assertTogglePostForm($xpath, $cash, 'offregistry', 802, $stage, '/admin-gifts?sample=1#gifts-table');
            $this->assertSame('submit', $button->getAttribute('type'));
        }
        $this->element($xpath, './/a[@href="/admin-gifts?edit=802#add-gift"][@class="btn-small btn-edit"]', $cash);
        $this->element($xpath, './/a[@href="/admin-gifts?delete=802#gifts-table"]', $cash);
        $cashReceived = $xpath->query('.//*[@data-field="received"]', $cash);
        $this->assertNotFalse($cashReceived);
        $this->assertCount(0, $cashReceived);

        $registry = $this->element($xpath, '//tr[.//input[@data-item-id="303"]]');
        $name = $this->element($xpath, './/input[@data-item-id="303"]', $registry);
        $this->assertSame('A Sample Guest', $name->getAttribute('value'));
        $this->assertTrue($name->hasAttribute('readonly'));
        $note = $this->element($xpath, './/button[contains(@class,"gift-title-link")]', $registry);
        $this->assertSame('Mention their own honeymoon story when writing the thank-you.', $note->getAttribute('data-item-note'));
        $this->assertStringNotContainsString('honeymoon story', $registry->getAttribute('data-search'));
        $received = $this->assertTogglePostForm($xpath, $registry, 'registry', 303, 'received', '/admin-gifts?sample=1#gifts-table');
        $this->assertSame('Received Feb 8, 2026', $received->getAttribute('title'));
        $this->element($xpath, './/button[contains(@class,"js-unmark-purchased")][@data-id="303"]', $registry);

        foreach (['housefund' => '/admin-house-fund', 'honeymoonfund' => '/admin-honeymoon-fund'] as $source => $destination) {
            $fundRows = $xpath->query('//tr[contains(@class,"source-' . $source . '")]');
            $this->assertNotFalse($fundRows);
            $this->assertCount(3, $fundRows);
            foreach ($fundRows as $row) {
                $this->assertInstanceOf(\DOMElement::class, $row);
                $links = $xpath->query('.//a[@href="' . $destination . '"]', $row);
                $this->assertNotFalse($links);
                $this->assertCount(3, $links);
                $fundReceived = $xpath->query('.//*[@data-field="received"]', $row);
                $this->assertNotFalse($fundReceived);
                $this->assertCount(0, $fundReceived);
                $written = $this->element($xpath, './/button[@data-field="written"]', $row);
                $id = (int) $written->getAttribute('data-id');
                $this->assertGreaterThan(0, $id);
                $this->assertTogglePostForm($xpath, $row, $source, $id, 'written', '/admin-gifts?sample=1#gifts-table');
                $this->assertTogglePostForm($xpath, $row, $source, $id, 'sent', '/admin-gifts?sample=1#gifts-table');
            }
        }
        $this->element($xpath, '//div[@id="registry-note-modal"]');
        $this->element($xpath, '//input[@id="gift-filter"]');
        $this->element($xpath, '//select[@id="filter-received"]');
        $this->element($xpath, '//select[@id="filter-written"]');
        $this->element($xpath, '//select[@id="filter-sent"]');
    }

    private function assertTogglePostForm(
        \DOMXPath $xpath,
        \DOMNode $row,
        string $source,
        int $id,
        string $field,
        string $action,
    ): \DOMElement {
        $button = $this->element($xpath, './/button[@data-field="' . $field . '"]', $row);
        $this->assertSame($source, $button->getAttribute('data-source'));
        $this->assertSame((string) $id, $button->getAttribute('data-id'));
        $form = $button->parentNode;
        $this->assertInstanceOf(\DOMElement::class, $form);
        $this->assertSame('form', strtolower($form->tagName));
        $this->assertSame('POST', strtoupper($form->getAttribute('method')));
        $this->assertSame($action, $form->getAttribute('action'));
        $this->assertSame('1', $this->element($xpath, './input[@name="toggle_gift_status"]', $form)->getAttribute('value'));
        $this->element($xpath, './input[@name="csrf_token"]', $form);
        $this->assertSame($source, $this->element($xpath, './input[@name="source"]', $form)->getAttribute('value'));
        $this->assertSame((string) $id, $this->element($xpath, './input[@name="id"]', $form)->getAttribute('value'));
        $this->assertSame($field, $this->element($xpath, './input[@name="field"]', $form)->getAttribute('value'));
        return $button;
    }

    private function element(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): \DOMElement
    {
        $nodes = $xpath->query($query, $context);
        $this->assertNotFalse($nodes, $query);
        $this->assertCount(1, $nodes, $query);
        $element = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $element, $query);
        return $element;
    }
}
