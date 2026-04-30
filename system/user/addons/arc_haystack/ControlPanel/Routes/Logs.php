<?php

namespace CreativeArc\ArcHaystack\ControlPanel\Routes;

use CreativeArc\ArcHaystack\Settings;
use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

class Logs extends AbstractRoute
{
    protected $route_path    = 'logs';
    protected $cp_page_title = 'template_usage_logs_title';

    public function process($id = false)
    {
        if (ee('Request')->isPost() && ee('Request')->post('save_settings') === 'y') {
            $this->saveSettings();
        }

        $settings = Settings::get();

        $perPage = 50;
        $page    = (int) ee('Request')->get('page', 1);
        $offset  = ($page - 1) * $perPage;

        $logSortAllowed = ['main_template', 'page_url', 'logged_at'];
        $logSortCol = in_array(ee('Request')->get('log_sort'), $logSortAllowed)
            ? ee('Request')->get('log_sort') : 'logged_at';
        $logSortDir = ee('Request')->get('log_dir') === 'asc' ? 'asc' : 'desc';

        $totalLogs = ee()->db->count_all('arc_haystack_logs');

        $logs = ee()->db
            ->select('*')
            ->from('arc_haystack_logs')
            ->order_by($logSortCol, strtoupper($logSortDir))
            ->limit($perPage, $offset)
            ->get()
            ->result_array();

        $formattedLogs = [];
        foreach ($logs as $log) {
            $embedsUsed    = ! empty($log['embeds_used'])    ? json_decode($log['embeds_used'],    true) : [];
            $partialsUsed  = ! empty($log['partials_used'])  ? json_decode($log['partials_used'],  true) : [];
            $variablesUsed = ! empty($log['variables_used']) ? json_decode($log['variables_used'], true) : [];

            $formattedLogs[] = array_merge($log, [
                'logged_at'       => ee()->localize->human_time($log['logged_at']),
                'embeds_count'    => is_array($embedsUsed)    ? count($embedsUsed)    : 0,
                'partials_count'  => is_array($partialsUsed)  ? count($partialsUsed)  : 0,
                'variables_count' => is_array($variablesUsed) ? count($variablesUsed) : 0,
                'view_url'        => ee('CP/URL')->make('addons/settings/arc_haystack/view/' . $log['id']),
            ]);
        }

        $logPaginationUrl = ee('CP/URL')->make('addons/settings/arc_haystack/logs');
        $logPaginationUrl->setQueryStringVariable('log_sort', $logSortCol);
        $logPaginationUrl->setQueryStringVariable('log_dir', $logSortDir);

        $pagination = ee('CP/Pagination', $totalLogs)
            ->perPage($perPage)
            ->currentPage($page)
            ->render($logPaginationUrl);

        $vars = [
            'logs'              => $formattedLogs,
            'pagination'        => $pagination,
            'total_logs'        => $totalLogs,
            'log_sort'          => $logSortCol,
            'log_dir'           => $logSortDir,
            'log_sort_base_url' => ee('CP/URL')->make('addons/settings/arc_haystack/logs')->compile(),
            'clear_url'         => ee('CP/URL')->make('addons/settings/arc_haystack/clear'),
            'export_url'        => ee('CP/URL')->make('addons/settings/arc_haystack/export'),
            'settings_url'      => ee('CP/URL')->make('addons/settings/arc_haystack/logs')->compile(),
            'logging_enabled'   => $settings['logging_enabled'] ?? 'y',
        ];

        $this->setHeading(lang($this->cp_page_title));
        $this->addBreadcrumb('index', 'arc_haystack_module_name');
        $this->setBody('logs', $vars);

        return $this;
    }

    protected function saveSettings(): void
    {
        $loggingEnabled = ee('Request')->post('logging_enabled') === 'y' ? 'y' : 'n';
        Settings::save(['logging_enabled' => $loggingEnabled]);

        ee('CP/Alert')->makeInline('shared-form')
            ->asSuccess()
            ->withTitle(lang('settings_saved'))
            ->defer();

        ee()->functions->redirect(
            ee('CP/URL')->make('addons/settings/arc_haystack/logs')->compile()
        );
    }
}
