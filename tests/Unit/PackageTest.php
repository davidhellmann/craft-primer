<?php

namespace davidhellmann\primer\tests\Unit;

use CraftCms\Cms\Support\Json;
use PHPUnit\Framework\TestCase;

/**
 * The package as the Plugin Store and Composer see it.
 */
class PackageTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $composer;

    protected function setUp(): void
    {
        $this->composer = Json::decode((string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'));
    }

    public function testItIsTheCraftPluginPrimer(): void
    {
        $this->assertSame('davidhellmann/craft-primer', $this->composer['name']);
        $this->assertSame('craft-plugin', $this->composer['type']);
        $this->assertSame('primer', $this->composer['extra']['handle']);
        $this->assertSame('Primer', $this->composer['extra']['name']);
        $this->assertSame(['davidhellmann\\primer\\' => 'src/'], $this->composer['autoload']['psr-4']);
    }

    public function testItIsSoldUnderTheCraftLicense(): void
    {
        $this->assertSame('proprietary', $this->composer['license']);
        $this->assertStringStartsWith('The Craft License', (string)file_get_contents(dirname(__DIR__, 2) . '/LICENSE.md'));
    }

    public function testItRequiresCraft6AndPhp85(): void
    {
        $this->assertSame('^6.0.0-beta.1', $this->composer['require']['craftcms/cms']);
        $this->assertSame('^8.5', $this->composer['require']['php']);
        $this->assertSame('davidhellmann\\primer\\Plugin', $this->composer['extra']['class']);
    }

    /**
     * Craft finds what it loads here: the icons, the routes and the install migration. They have to be in the package.
     */
    public function testWhatCraftLoadsIsInThePackage(): void
    {
        $root = dirname(__DIR__, 2);
        $attributes = (string)file_get_contents("$root/.gitattributes");

        foreach (['resources/icon.svg', 'resources/icon-mask.svg', 'routes/cp.php', 'database/migrations/Install.php'] as $path) {
            $this->assertFileExists("$root/$path");
            $this->assertStringNotContainsString(explode('/', $path)[0] . '/ export-ignore', $attributes, "$path is left out of the package.");
        }
    }
}
