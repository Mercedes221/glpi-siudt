<?php

require_once __DIR__ . '/inc/siudt_front.class.php';
require_once __DIR__ . '/inc/importer.class.php';

function plugin_version_siudt_front()
{
    return [
        'name'         => 'SIUDT Front',
        'version'      => '1.0.0',
        'author'       => 'SIUDT',
        'license'      => 'GPLv2+',
        'minGlpiVersion' => '10.0.0',
    ];
}

function plugin_init_siudt_front()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['siudt_front'] = true;
    $PLUGIN_HOOKS['menu_toadd']['siudt_front'] = [
        'tools' => ['PluginSiudtFront'],
    ];
}
