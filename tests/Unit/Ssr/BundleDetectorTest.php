<?php

declare(strict_types=1);

namespace Nytodev\InertiaBundle\Tests\Unit\Ssr;

use Nytodev\InertiaBundle\Ssr\BundleDetector;
use PHPUnit\Framework\TestCase;

final class BundleDetectorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/inertia-bundle-detector-'.uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir.\DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function createFile(string $relativePath): string
    {
        $absolute = $this->tempDir.\DIRECTORY_SEPARATOR.$relativePath;
        $dir = \dirname($absolute);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($absolute, '// fake SSR bundle');

        return $absolute;
    }

    // --- configured bundle path ---

    public function testDetectWithConfiguredPathThatExistsReturnsConfiguredPath(): void
    {
        $bundlePath = $this->createFile('custom/ssr.js');

        $detector = new BundleDetector($bundlePath, $this->tempDir);

        self::assertSame($bundlePath, $detector->detect());
    }

    public function testDetectWithConfiguredPathThatDoesNotExistReturnsNull(): void
    {
        $detector = new BundleDetector('/nonexistent/path/ssr.js', $this->tempDir);

        self::assertNull($detector->detect());
    }

    public function testDetectWithRelativeConfiguredPathResolvesAgainstProjectDir(): void
    {
        $bundlePath = $this->createFile('public/build/ssr/ssr.js');
        $cwd = (string) getcwd();
        // Under PHP-FPM the working directory is usually public/, not the project root.
        chdir(sys_get_temp_dir());

        try {
            $detector = new BundleDetector('public/build/ssr/ssr.js', $this->tempDir);

            self::assertSame($bundlePath, $detector->detect());
        } finally {
            chdir($cwd);
        }
    }

    public function testDetectWithRelativeConfiguredPathThatDoesNotExistReturnsNull(): void
    {
        $detector = new BundleDetector('public/build/ssr/missing.js', $this->tempDir);

        self::assertNull($detector->detect());
    }

    // --- auto-detection (no configured path) ---

    public function testDetectWithNullConfiguredPathDetectsBootstrapSsrMjs(): void
    {
        $this->createFile('bootstrap/ssr/ssr.mjs');

        $detector = new BundleDetector(null, $this->tempDir);

        self::assertSame(
            $this->tempDir.\DIRECTORY_SEPARATOR.'bootstrap/ssr/ssr.mjs',
            $detector->detect()
        );
    }

    public function testDetectWithNullConfiguredPathDetectsPublicBuildSsrMjs(): void
    {
        $this->createFile('public/build/ssr/ssr.mjs');

        $detector = new BundleDetector(null, $this->tempDir);

        self::assertSame(
            $this->tempDir.\DIRECTORY_SEPARATOR.'public/build/ssr/ssr.mjs',
            $detector->detect()
        );
    }

    public function testDetectWithNullConfiguredPathDetectsPublicBuildSsrJs(): void
    {
        $this->createFile('public/build/ssr/ssr.js');

        $detector = new BundleDetector(null, $this->tempDir);

        self::assertSame(
            $this->tempDir.\DIRECTORY_SEPARATOR.'public/build/ssr/ssr.js',
            $detector->detect()
        );
    }

    public function testDetectWithNullConfiguredPathAndNoCandidateExistsReturnsNull(): void
    {
        $detector = new BundleDetector(null, $this->tempDir);

        self::assertNull($detector->detect());
    }

    // --- priority: configured path before auto-detection ---

    public function testDetectConfiguredPathTakesPriorityOverAutoDetection(): void
    {
        $configuredPath = $this->createFile('custom/ssr.js');
        $this->createFile('bootstrap/ssr/ssr.mjs'); // also exists

        $detector = new BundleDetector($configuredPath, $this->tempDir);

        self::assertSame($configuredPath, $detector->detect());
    }

    // --- priority: first existing candidate wins ---

    public function testDetectFirstExistingCandidateWins(): void
    {
        // Create both, but bootstrap/ssr/ssr.mjs has higher priority
        $this->createFile('bootstrap/ssr/ssr.mjs');
        $this->createFile('public/build/ssr/ssr.mjs');

        $detector = new BundleDetector(null, $this->tempDir);

        self::assertSame(
            $this->tempDir.\DIRECTORY_SEPARATOR.'bootstrap/ssr/ssr.mjs',
            $detector->detect()
        );
    }

    // --- idempotency ---

    public function testDetectCalledTwiceReturnsSameResult(): void
    {
        $bundlePath = $this->createFile('bootstrap/ssr/ssr.mjs');

        $detector = new BundleDetector(null, $this->tempDir);

        $first = $detector->detect();
        $second = $detector->detect();

        self::assertSame($bundlePath, $first);
        self::assertSame($bundlePath, $second);
    }
}
