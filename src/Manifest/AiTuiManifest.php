<?php
declare(strict_types=1);

namespace Crustum\AiTui\Manifest;

use Crustum\AiTui\AiTuiPlugin;
use Crustum\PluginManifest\Manifest\ManifestOps;

/**
 * Standalone manifest provider for the AiTui plugin.
 *
 * Plain class without interface or trait dependencies, so the plugin loads
 * and runs without `crustum/plugin-manifest` installed. Discovered by the
 * installer through the provider convention when the plugin class itself
 * provides no manifest. All package references stay inside method bodies,
 * which only execute when the installer (and therefore the package) exists.
 */
final class AiTuiManifest
{
    /**
     * Plugin install assets via crustum/plugin-manifest.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function manifest(): array
    {
        $plugin = ManifestOps::pluginName(AiTuiPlugin::class);

        return ManifestOps::manifestStarRepo($plugin, 'Crustum/AiTui');
    }
}
