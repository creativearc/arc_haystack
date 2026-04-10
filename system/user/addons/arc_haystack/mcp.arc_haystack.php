<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

use ExpressionEngine\Service\Addon\Mcp;

/**
 * ARC Haystack MCP Controller
 *
 * This addon uses modern MVC routing. Routes are registered in addon.setup.php
 * and handled by classes in ControlPanel/Routes/
 *
 * @see ControlPanel/Routes/Index.php
 * @see ControlPanel/Routes/View.php
 * @see ControlPanel/Routes/Export.php
 * @see ControlPanel/Routes/Clear.php
 */
class Arc_haystack_mcp extends Mcp
{
    protected $addon_name = 'arc_haystack';
}
