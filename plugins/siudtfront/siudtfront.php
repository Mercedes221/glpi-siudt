<?php

function plugin_version_siudtfront() {
   return [
      'name' => 'SIUDT Front',
      'version' => '1.0.0',
      'author' => 'SIUDT',
      'license' => 'GPLv2+'
   ];
}

function plugin_init_siudtfront() {
   global $PLUGIN_HOOKS;
   $PLUGIN_HOOKS['add_css']['siudtfront'] = 'css/custom_theme.css';
   $PLUGIN_HOOKS['add_css_anonymous_page']['siudtfront'] = 'css/custom_theme.css';
}

function plugin_siudtfront_install(array $params = []): bool {
   return true;
}

function plugin_siudtfront_uninstall(): bool {
   return true;
}
