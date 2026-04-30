<?php

namespace CreativeArc\ArcHaystack\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

class Clear extends AbstractRoute
{
    protected $route_path = 'clear';
    protected $cp_page_title = 'clear_logs_title';

    public function process($id = false)
    {
        ee()->db->truncate('arc_haystack_logs');

        ee('CP/Alert')->makeInline('shared-form')
            ->asSuccess()
            ->withTitle(lang('logs_cleared'))
            ->addToBody(lang('logs_cleared_message'))
            ->defer();

        ee()->functions->redirect(
            ee('CP/URL')->make('addons/settings/arc_haystack/logs')->compile()
        );
    }
}
