<?php

namespace bymayo\vouch\web\assets\vouch;

use craft\web\AssetBundle;

/**
 * Compiled Tailwind utilities (prefixed `vouch-`) for the plugin's
 * control-panel views. The CSS is built from `src/vouch.css` via the repo's
 * `npm run build` and committed under `dist/` so it ships without a build step.
 */
class VouchAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->css = ['vouch.css'];

        parent::init();
    }
}
