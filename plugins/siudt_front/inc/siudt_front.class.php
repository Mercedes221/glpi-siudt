<?php

class PluginSiudtFront extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('SIUDT');
    }

    public static function getMenuName()
    {
        return __('Importar activos SIUDT');
    }

    public static function getIcon()
    {
        return 'ti ti-file-import';
    }

    public static function canView()
    {
        foreach (PluginSiudtFrontImporter::getAssetTypes() as $asset_type) {
            if ($asset_type::canCreate()) {
                return true;
            }
        }

        return false;
    }

    public static function getMenuContent()
    {
        if (!self::canView()) {
            return false;
        }

        return [
            'title' => self::getMenuName(),
            'page'  => Plugin::getWebDir('siudt_front', false) . '/front/siudt_dashboard.php',
            'icon'  => self::getIcon(),
        ];
    }
}
