<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\Unit\View;

use Crustum\AiTui\View\ComposerInputView;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the composer history recall.
 */
final class ComposerInputViewTest extends TestCase
{
    public function testRecallStopsAtOldestAndRestoresTheDraft(): void
    {
        $input = new ComposerInputView();
        $input->onInputHistory(static fn(): array => ['First', 'Second']);
        $input->setText('Draft');
        foreach (['Second', 'First', 'First'] as $expected) {
            $input->handleInput("\x1b[A");
            self::assertSame($expected, $input->getText());
        }

        foreach (['Second', 'Draft', 'Draft'] as $expected) {
            $input->handleInput("\x1b[B");
            self::assertSame($expected, $input->getText());
        }
    }

    public function testEditingRecallCreatesANewDraftAndReloadsHistory(): void
    {
        $entries = ['First'];
        $input = new ComposerInputView();
        $input->onInputHistory(static function () use (&$entries): array {
            return $entries;
        });
        $input->handleInput("\x1b[A");
        $input->handleInput(' edited');
        self::assertSame('First edited', $input->getText());
        $entries[] = 'New submission';
        $input->handleInput("\x1b[A");
        self::assertSame('New submission', $input->getText());
        $input->handleInput("\x1b[B");
        self::assertSame('First edited', $input->getText());
        $input->setText('');
        $input->handleInput("\x1b[B");
        self::assertSame('', $input->getText());
    }

    public function testMultilineArrowsEditAndControlKeysRecallAndRestore(): void
    {
        $input = new ComposerInputView();
        $input->onInputHistory(static fn(): array => ["Older\ninput", 'Newest']);
        $input->setText("My\ndraft");
        $input->handleInput("\x1b[B");
        $input->handleInput("\x1b[F");
        $input->handleInput(' edited');
        self::assertSame("My\ndraft edited", $input->getText());
        $input->handleInput("\x10");
        self::assertSame('Newest', $input->getText());
        $input->handleInput("\x10");
        self::assertSame("Older\ninput", $input->getText());
        $input->handleInput("\x1b[A");
        self::assertSame("Older\ninput", $input->getText());
        $input->handleInput("\x0e");
        $input->handleInput("\x0e");
        self::assertSame("My\ndraft edited", $input->getText());
    }

    public function testEmptyAndUnconfiguredHistoryPreserveInput(): void
    {
        $input = new ComposerInputView();
        $input->setText('Draft');
        $input->handleInput("\x1b[A");
        $input->handleInput('Prefix ');
        self::assertSame('Prefix Draft', $input->getText());
        $input->onInputHistory(static fn(): array => []);
        $input->handleInput("\x1b[A");
        $input->handleInput("\x1b[B");
        self::assertSame('Prefix Draft', $input->getText());
    }

    public function testUnavailableHistoryKeepsNormalArrowEditing(): void
    {
        $input = new ComposerInputView();
        $input->onInputHistory(static fn(): ?array => null);
        $input->setText('Draft');
        $input->handleInput("\x1b[B");
        $input->handleInput(' suffix');
        self::assertSame('Draft suffix', $input->getText());
        $input->handleInput("\x1b[A");
        $input->handleInput('Prefix ');
        self::assertSame('Prefix Draft suffix', $input->getText());
    }
}
