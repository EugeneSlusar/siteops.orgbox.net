<?php

global $USER;
if (!is_object($USER) || !$USER->IsAdmin()) {
    return false;
}

return [
    'parent_menu' => 'global_menu_settings',
    'section' => 'orgbox_siteops',
    'sort' => 1900,
    'text' => 'orgBox: SiteOps',
    'title' => 'MCP-сервер и API для ИИ-ассистентов',
    'url' => 'orgbox_siteops_settings.php',
    'icon' => 'sys_menu_icon',
    'page_icon' => 'sys_page_icon',
    'items_id' => 'menu_orgbox_siteops',
];
