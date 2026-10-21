<?php

namespace davidhellmann\primer\tests\unit;

use Codeception\Test\Unit;

/**
 * The package as the Plugin Store and Composer see it.
 */
class PackageTest extends Unit
{
    /**
     * @var array<string, mixed>
     */
    private array $composer;

    protected function _before(): void
    {
        $this->composer = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
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

    public function testItRequiresAtLeastCraft5115AndPhp82(): void
    {
        $this->assertSame('^5.11.5', $this->composer['require']['craftcms/cms']);
        $this->assertSame('>=8.2', $this->composer['require']['php']);
    }
}
