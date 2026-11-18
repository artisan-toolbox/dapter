<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Support\SourceVersion;
use Illuminate\Support\Facades\File;

/** @param Closure(DataSource,string,string):void $test */
function withBrowserVersionSource(Closure $test): void
{
    $namespace = 'BrowserVersionFixture\\Case'.bin2hex(random_bytes(8));
    $bytes = str_replace('FIXTURE_NAMESPACE', $namespace, <<<'PHP'
<?php
namespace FIXTURE_NAMESPACE;
trait VersionLeaf { private const string LEAF = 'leaf-initial'; }
trait VersionOuter { use VersionLeaf; private const string OUTER = 'outer-initial'; }
#[\AllowDynamicProperties]
class BrowserVersionParent extends \ArtisanToolbox\Dapter\CollectionDataSource
{
    public function closeTag(string $tag): string { return "$tag}"; }
    public function source(): array { return [['marker' => 'parent-initial']]; }
}
final class BrowserVersionSource extends BrowserVersionParent { use VersionOuter; public const string MARKER = 'source-initial'; } class BrowserVersionSibling { public const string VALUE = 'sibling-initial'; }
PHP);
    $file = tempnam(sys_get_temp_dir(), 'dapter-browser-version-');
    file_put_contents($file, $bytes);
    require $file;
    $class = $namespace.'\\BrowserVersionSource';
    /** @var DataSource $source */
    $source = new $class;

    try {
        $test($source, $file, $bytes);
    } finally {
        File::delete($file);
    }
}

it('invalidates source versions for source parent and recursive trait declaration edits', function (string $needle): void {
    withBrowserVersionSource(function (DataSource $source, string $file, string $bytes) use ($needle): void {
        $versions = new SourceVersion;
        $baseline = $versions->forSource($source);
        expect($baseline)->toMatch('/\A[a-f0-9]{64}\z/');
        file_put_contents($file, str_replace($needle, $needle.'-changed', $bytes));
        expect($versions->forSource($source))->not->toBe($baseline);
        file_put_contents($file, $bytes);
        expect($versions->forSource($source))->toBe($baseline);
    });
})->with(['source-initial', 'parent-initial', 'outer-initial', 'leaf-initial']);

it('ignores sibling declarations outside the source hierarchy even when they share a line', function (): void {
    withBrowserVersionSource(function (DataSource $source, string $file, string $bytes): void {
        $versions = new SourceVersion;
        $baseline = $versions->forSource($source);
        file_put_contents($file, str_replace('sibling-initial', 'sibling-changed', $bytes));
        expect($versions->forSource($source))->toBe($baseline);
        file_put_contents($file, "\n".str_replace("<?php\n", "<?php\n\n// Unrelated file comment.\n", $bytes));
        expect($versions->forSource($source))->toBe($baseline);
        touch($file, time() - 3600);
        expect($versions->forSource($source))->toBe($baseline);
    });
});

it('includes attributes in class structure without including surrounding declarations', function (): void {
    withBrowserVersionSource(function (DataSource $source, string $file, string $bytes): void {
        $versions = new SourceVersion;
        $baseline = $versions->forSource($source);
        file_put_contents($file, str_replace('#[\\AllowDynamicProperties]', '#[\\AllowDynamicProperties, \\Deprecated]', $bytes));
        expect($versions->forSource($source))->not->toBe($baseline);
    });
});
