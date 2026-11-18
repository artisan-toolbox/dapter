<?php

declare(strict_types=1);

// Unicode 16.0.0 category M is pinned so engine upgrades cannot silently replace the range set.
if (! extension_loaded('intl') || IntlChar::getUnicodeVersion() !== [16, 0, 0, 0]) {
    throw new RuntimeException('Generation requires ICU Unicode 16.0.0.');
}
$ranges = [];
$start = null;
$last = null;
for ($point = 0; $point <= 0x10FFFF; $point++) {
    $mark = in_array(IntlChar::charType($point), [IntlChar::CHAR_CATEGORY_NON_SPACING_MARK, IntlChar::CHAR_CATEGORY_COMBINING_SPACING_MARK, IntlChar::CHAR_CATEGORY_ENCLOSING_MARK], true);

    if ($mark) {
        $start ??= $point;
        $last = $point;
    } elseif ($start !== null) {
        $ranges[] = IntlChar::chr($start).($start === $last ? '' : '-'.IntlChar::chr($last));
        $start = null;
    }
}
$pattern = '['.implode('', $ranges).']+';
$source = <<<'SOURCE'
<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers\Comparisons;

/**
 * Unicode 16.0.0 general category M, generated with ICU 77.1.
 * Regenerate with scripts/generate-unicode-marks.php. Do not edit the range table.
 *
 * @internal
 */
final class UnicodeMarks
{
    public const string PATTERN = __PATTERN__;
}

SOURCE;
file_put_contents(__DIR__.'/../src/Drivers/Comparisons/UnicodeMarks.php', str_replace('__PATTERN__', var_export($pattern, true), $source));
