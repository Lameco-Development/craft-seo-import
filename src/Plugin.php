<?php

namespace lameco\seoimport;

use Craft;
use craft\base\Plugin as BasePlugin;

/**
 * seo-import plugin
 *
 * @method static Plugin getInstance()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();

        Craft::$app->onInit(function () {
        });
    }

    private function attachEventHandlers(): void
    {

    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'SEO Import';
        $item['icon'] = '@lameco/seoimport/navitem.svg';
        return $item;
    }
}
