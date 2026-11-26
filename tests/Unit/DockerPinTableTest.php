<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `docker/README.md`'s "Current pins" table against the files that actually carry the pins.
 *
 * The table is the only place an operator can see which third-party images this project builds
 * and tests with, and every one of them is pinned to a multi-architecture digest. A digest bump
 * is a one-line edit in a Dockerfile or a compose file, so the table drifts silently: it keeps
 * describing an image nothing uses, or it states a tag the pinned digest no longer matches.
 *
 * This reads the build, compose, workflow and CI-script files and the table, without network or
 * Docker access, and reports the exact file and line against the exact README row when the two
 * disagree. Fix the README (the pins themselves come from the upstream registry), never the pins.
 */
class DockerPinTableTest extends TestCase
{
    /**
     * Our own published images are pinned by release tag in the performance harness, not by a
     * third-party digest, and the table deliberately does not cover them.
     */
    private const SELF_PUBLISHED_PREFIX = 'ghcr.io/gigaionllc/';

    private const README = 'docker/README.md';

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function test_the_readme_pin_table_exists(): void
    {
        $this->assertNotSame([], $this->rows(), self::README.' must have a "## Current pins" table.');
    }

    public function test_every_pinned_digest_is_listed_in_the_readme_table_with_its_tag(): void
    {
        $rows = $this->rows();
        $stated = [];
        foreach ($rows as $row) {
            $stated[$row['digest']] = $row;
        }

        $problems = [];
        foreach ($this->pins() as $pin) {
            $row = $stated[$pin['digest']] ?? null;

            if ($row === null) {
                $problems[] = $this->describe(
                    $pin,
                    self::README.' has no row with digest '.$pin['digest']
                        .$this->closestRow($rows, $pin['repository'])
                );

                continue;
            }

            $version = $this->versionIn($pin['tag']);
            if ($version !== null && ! str_contains($row['version'], $version)) {
                $problems[] = $this->describe(
                    $pin,
                    self::README.':'.$row['line'].' lists "'.$row['version'].'", which is not the tag '
                        .$pin['tag'].' that digest is pinned under'
                );
            }
        }

        $this->assertSame([], $problems, "The pin table in docker/README.md disagrees with the files:\n".implode("\n", $problems));
    }

    public function test_the_readme_table_lists_no_pin_that_nothing_uses(): void
    {
        $pinned = [];
        foreach ($this->pins() as $pin) {
            $pinned[$pin['digest']] = true;
        }

        $stale = [];
        foreach ($this->rows() as $row) {
            if (! isset($pinned[$row['digest']])) {
                $stale[] = self::README.':'.$row['line'].' lists '.$row['name'].' '.$row['version']
                    .' ('.$row['digest'].'), which no build, compose, workflow or CI-script file uses.';
            }
        }

        $this->assertSame([], $stale, "docker/README.md lists pins that nothing uses:\n".implode("\n", $stale));
    }

    /**
     * Every `name:tag@sha256:<64 hex>` reference in the files that carry pins.
     *
     * @return list<array{file: string, line: int, repository: string, tag: string, digest: string}>
     */
    private function pins(): array
    {
        $pins = [];

        foreach ($this->files() as $file) {
            $contents = (string) file_get_contents($this->root.'/'.$file);
            foreach (explode("\n", $contents) as $index => $line) {
                if (preg_match('/([a-z0-9][a-z0-9._\/-]*):([A-Za-z0-9._-]+)@(sha256:[0-9a-f]{64})/', $line, $match) !== 1) {
                    continue;
                }

                if (str_starts_with($match[1], self::SELF_PUBLISHED_PREFIX)) {
                    continue;
                }

                $pins[] = [
                    'file' => $file,
                    'line' => $index + 1,
                    'repository' => $match[1],
                    'tag' => $match[2],
                    'digest' => $match[3],
                ];
            }
        }

        $this->assertNotSame([], $pins, 'No digest-pinned image reference was found at all; the scanner is broken.');

        return $pins;
    }

    /**
     * The rows of the "## Current pins" table.
     *
     * @return list<array{line: int, name: string, version: string, digest: string}>
     */
    private function rows(): array
    {
        $lines = explode("\n", (string) file_get_contents($this->root.'/'.self::README));
        $rows = [];
        $inSection = false;

        foreach ($lines as $index => $line) {
            $line = trim($line);

            if (str_starts_with($line, '## ')) {
                $inSection = $line === '## Current pins';

                continue;
            }

            if (! $inSection || ! str_starts_with($line, '|')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));
            if (count($cells) < 4 || preg_match('/(sha256:[0-9a-f]{64})/', $cells[2], $digest) !== 1) {
                continue;
            }

            $rows[] = [
                'line' => $index + 1,
                'name' => $cells[0],
                'version' => $cells[1],
                'digest' => $digest[1],
            ];
        }

        return $rows;
    }

    /**
     * The files that carry the pins the table documents.
     *
     * @return list<string> Paths relative to the repository root.
     */
    private function files(): array
    {
        $patterns = [
            'docker/Dockerfile*',
            '*compose*.yml',
            'docker/*compose*.yml',
            'examples/*compose*.yml',
            '.github/workflows/*.yml',
            'scripts/*.sh',
        ];

        $files = [];
        foreach ($patterns as $pattern) {
            foreach (glob($this->root.'/'.$pattern) ?: [] as $file) {
                $files[substr($file, strlen($this->root) + 1)] = true;
            }
        }

        ksort($files);

        return array_keys($files);
    }

    /**
     * @param  array{file: string, line: int, repository: string, tag: string, digest: string}  $pin
     */
    private function describe(array $pin, string $problem): string
    {
        return $pin['file'].':'.$pin['line'].' pins '.$pin['repository'].':'.$pin['tag']
            .'@'.$pin['digest'].' — '.$problem.'.';
    }

    /**
     * The README row that describes another tag of the same repository, to point at.
     *
     * @param  list<array{line: int, name: string, version: string, digest: string}>  $rows
     */
    private function closestRow(array $rows, string $repository): string
    {
        $name = strtolower((string) preg_replace('/^.*\//', '', $repository));

        foreach ($rows as $row) {
            if (str_contains(strtolower($row['name']), $name)) {
                return ' ('.self::README.':'.$row['line'].' lists '.$row['name'].' '.$row['version']
                    .', a different digest: '.$row['digest'].')';
            }
        }

        return '';
    }

    /**
     * The version number a tag carries, e.g. `24.22.3` from `24.22.3-bookworm-slim`. Null when the
     * tag has no numeric version to compare.
     */
    private function versionIn(string $tag): ?string
    {
        return preg_match('/\d+(?:\.\d+)+/', $tag, $match) === 1 ? $match[0] : null;
    }
}
