<?php

namespace davidhellmann\primer;

use craft\base\Plugin as BasePlugin;

/**
 * Primer plugin
 *
 * @method static Plugin getInstance()
 * @author David Hellmann <david@hellmann.io>
 * @copyright David Hellmann
 * @license https://craftcms.github.io/license/ Craft License
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
}
