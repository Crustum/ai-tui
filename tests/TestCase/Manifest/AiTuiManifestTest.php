<?php
declare(strict_types=1);

namespace Crustum\AiTui\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\AiTui\AiTuiPlugin;
use Crustum\AiTui\Manifest\AiTuiManifest;
use Crustum\PluginManifest\Manifest\ManifestResolver;

/**
 * Covers the standalone manifest provider: identical operations to the
 * former plugin-class manifest, discoverable without the interface.
 */
class AiTuiManifestTest extends TestCase
{
    /**
     * The provider emits a star operation.
     *
     * @return void
     */
    public function testManifestOperations(): void
    {
        $ops = AiTuiManifest::manifest();
        $tags = array_column($ops, 'tag');

        $this->assertContains('star-repo', $tags);
    }

    /**
     * The resolver finds the provider for the plugin class.
     *
     * @return void
     */
    public function testResolverFindsProvider(): void
    {
        $this->assertSame(
            AiTuiManifest::class,
            ManifestResolver::providerClass(AiTuiPlugin::class),
        );
        $this->assertSame(AiTuiManifest::manifest(), ManifestResolver::manifestFor(AiTuiPlugin::class));
    }

    /**
     * The plugin class itself carries no manifest contract.
     *
     * @return void
     */
    public function testPluginClassHasNoManifestContract(): void
    {
        $this->assertFalse(method_exists(AiTuiPlugin::class, 'manifest'));
    }
}
