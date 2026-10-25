<?php
declare(strict_types=1);

namespace Crustum\AiTui\View;

/**
 * Free-text question answered out of band instead of as a turn.
 */
final readonly class TextQuestion
{
    /**
     * Creates a text question request.
     *
     * A single-line default renders in the label and is accepted on empty
     * submit; a multiline default is prefilled into the editor for in-place
     * editing.
     *
     * @param string $prompt Question label shown above the composer.
     * @param string $default Default answer.
     * @param bool $multiline Whether the answer may span lines.
     */
    public function __construct(
        public string $prompt,
        public string $default = '',
        public bool $multiline = false,
    ) {
    }
}
