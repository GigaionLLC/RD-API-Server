<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The published viewer, as a browser sees it.
 *
 * Every other test in this repository exercises the viewer from `web-client/`, where `src`
 * and `vendor` are siblings and the relative imports between them resolve. The copy under
 * `public/` is a different layout, nothing rewrites those paths on the way — there is no
 * build step — and no test had ever loaded it.
 *
 * It was broken for two releases. `src/*` was published at the document root while
 * `vendor` stayed beside it, which moved every `../vendor/...` import up a directory and
 * out of the tree. The browser 404ed on the first one, the whole module graph failed to
 * evaluate, and the viewer rendered as bare HTML: its manual connection form, asking the
 * operator for an ID server and a base64 key that the server had already injected two
 * lines above. Nothing failed loudly, and the page looked like a half-finished feature.
 *
 * These tests resolve the graph the way a browser resolves it.
 */
class WebClientAssetsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = public_path('assets/webclient');

        if (! is_dir($this->root)) {
            $this->markTestSkipped('The viewer is not published in this checkout; run install-assets.mjs.');
        }
    }

    public function test_every_import_in_the_published_tree_resolves(): void
    {
        $unresolved = [];

        foreach ($this->modules() as $file) {
            foreach ($this->importsIn($file) as $specifier) {
                // Bare specifiers would need an import map; the client deliberately has
                // none, so anything not relative is already a defect.
                $this->assertStringStartsWith('.', $specifier,
                    basename($file).' imports the bare specifier "'.$specifier.'", which no browser can resolve here');

                $target = $this->resolve(dirname($file), $specifier);
                if ($target === null || ! is_file($target)) {
                    $unresolved[] = $this->relative($file).' -> '.$specifier;
                }
            }
        }

        $this->assertSame([], $unresolved,
            "These imports 404 in a browser, which stops the whole module graph:\n".implode("\n", $unresolved));
    }

    public function test_the_vendored_trees_are_where_the_modules_expect_them(): void
    {
        // The specific breakage, pinned: these two are reached from different depths, so a
        // layout that satisfies one by accident can still miss the other.
        $this->assertFileExists($this->root.'/vendor/fzstd/index.js');
        $this->assertFileExists($this->root.'/vendor/tweetnacl/nacl.js');

        $this->assertFileExists($this->resolve($this->root.'/src/crypto', '../../vendor/tweetnacl/nacl.js') ?? '');
        $this->assertFileExists($this->resolve($this->root.'/src/render', '../../vendor/fzstd/index.js') ?? '');
        $this->assertFileExists($this->resolve($this->root.'/src', '../vendor/fzstd/index.js') ?? '');
    }

    public function test_the_document_the_application_serves_is_the_published_one(): void
    {
        // The controller reads this exact path and injects configuration before its module
        // script. A layout change that moved the file would leave the route serving a 404
        // instead — or, worse, an older copy left behind at the previous path.
        $viewer = $this->root.'/src/ui/viewer.html';
        $this->assertFileExists($viewer);
        $this->assertStringContainsString('<script type="module"', (string) file_get_contents($viewer));

        $this->assertFileDoesNotExist($this->root.'/ui/viewer.html',
            'A copy at the old flattened path would be served by nothing and drift silently.');
    }

    public function test_the_published_copy_matches_the_source(): void
    {
        // Same guarantee as `install-assets.mjs --check`, enforced without Node so a PHP
        // test run alone still catches an edit made to one copy and not the other.
        $stale = [];
        foreach ($this->modules() as $file) {
            $source = base_path('web-client/'.$this->relative($file));
            if (! is_file($source)) {
                $stale[] = $this->relative($file).' (not in web-client/)';

                continue;
            }
            if (file_get_contents($source) !== file_get_contents($file)) {
                $stale[] = $this->relative($file).' (differs)';
            }
        }

        $this->assertSame([], $stale,
            "The published viewer is out of step with web-client/:\n".implode("\n", $stale));
    }

    /* ------------------------------------------------------------------ */

    /** @return array<int, string> Absolute paths of every published module. */
    private function modules(): array
    {
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /** @return array<int, string> Every static import and re-export specifier in one file. */
    private function importsIn(string $file): array
    {
        $source = (string) file_get_contents($file);
        preg_match_all('/(?:^|\s)(?:import|export)[^;\'"]*?from\s*[\'"]([^\'"]+)[\'"]/m', $source, $matches);
        $bare = [];
        preg_match_all('/(?:^|\s)import\s*[\'"]([^\'"]+)[\'"]/m', $source, $bare);

        return array_values(array_unique(array_merge($matches[1], $bare[1])));
    }

    /** Resolves a relative specifier the way a browser resolves a module URL. */
    private function resolve(string $from, string $specifier): ?string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $from.'/'.$specifier)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($parts === []) {
                    return null;
                }
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }

        $joined = implode('/', $parts);

        // Preserve a leading slash on POSIX and the drive letter on Windows.
        return str_starts_with(str_replace('\\', '/', $from), '/') ? '/'.$joined : $joined;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace([str_replace('\\', '/', $this->root), '\\'], ['', '/'], str_replace('\\', '/', $path)), '/');
    }
}
