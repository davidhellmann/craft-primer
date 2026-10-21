<?php

namespace davidhellmann\primer\tests\Support\Helper;

use Codeception\Lib\InnerBrowser;
use Codeception\Module;

/**
 * A plain form POST, as a CP script sends with `Craft.submitForm()` on a page form it creates: not ajax, so Craft
 * redirects and the redirect is followed like a browser does. Enable this module after `\craft\test\Craft`.
 */
class FormPost extends Module
{
    /**
     * @param array<string, mixed> $params
     */
    public function sendFormPost(string $uri, array $params = []): void
    {
        /** @var InnerBrowser $browser */
        $browser = $this->getModule('\craft\test\Craft');
        $browser->_loadPage('POST', $uri, $params);
    }
}
