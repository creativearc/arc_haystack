<?php

namespace CreativeArc\ArcHaystack\ControlPanel;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractSidebar;

class Sidebar extends AbstractSidebar
{
    protected $automatic = false;

    public function process()
    {
        $base = 'addons/settings/' . $this->addon;

        $item = $this->sidebar->addItem(lang('nav_site_template_status'), ee('CP/URL')->make($base));
        $this->routes['index'] = $item;

        $item = $this->sidebar->addItem(lang('nav_template_usage_logs'), ee('CP/URL')->make($base . '/logs'));
        $this->routes['logs'] = $item;
    }
}
