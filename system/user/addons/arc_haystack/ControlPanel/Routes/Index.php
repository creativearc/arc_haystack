<?php

namespace CreativeArc\ArcHaystack\ControlPanel\Routes;

use CreativeArc\ArcHaystack\Settings;
use CreativeArc\ArcHaystack\Traits\BuildsTemplateGrid;
use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

class Index extends AbstractRoute
{
    use BuildsTemplateGrid;

    protected $route_path = 'index';
    protected $cp_page_title = 'arc_haystack_logs_title';

    public function process($id = false)
    {
        // Handle settings save
        if (ee('Request')->isPost() && ee('Request')->post('save_settings') === 'y') {
            $this->saveSettings();
        }

        $settings = $this->getSettings();

        $perPage = 50;
        $page = ee('Request')->get('page', 1);
        $offset = ($page - 1) * $perPage;

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
            $embedsUsed = !empty($log['embeds_used']) ? json_decode($log['embeds_used'], true) : [];
            $partialsUsed = !empty($log['partials_used']) ? json_decode($log['partials_used'], true) : [];
            $variablesUsed = !empty($log['variables_used']) ? json_decode($log['variables_used'], true) : [];

            $formattedLogs[] = array_merge($log, [
                'logged_at'       => ee()->localize->human_time($log['logged_at']),
                'embeds_count'    => is_array($embedsUsed) ? count($embedsUsed) : 0,
                'partials_count'  => is_array($partialsUsed) ? count($partialsUsed) : 0,
                'variables_count' => is_array($variablesUsed) ? count($variablesUsed) : 0,
                'view_url'        => ee('CP/URL')->make('addons/settings/arc_haystack/view/' . $log['id']),
            ]);
        }

        $logPaginationUrl = ee('CP/URL')->make('addons/settings/arc_haystack');
        $logPaginationUrl->setQueryStringVariable('log_sort', $logSortCol);
        $logPaginationUrl->setQueryStringVariable('log_dir', $logSortDir);

        $pagination = ee('CP/Pagination', $totalLogs)
            ->perPage($perPage)
            ->currentPage($page)
            ->render($logPaginationUrl);

        $gridPerPage    = 25;
        $gridPage       = max(1, (int) ee('Request')->get('grid_page', 1));
        $gridTypeFilter = ee('Request')->get('grid_type', 'all');
        $gridActiveFilter = ee('Request')->get('grid_active', 'all');

        $gridSortAllowed = ['group_name', 'name', 'type', 'active', 'logged_at'];
        $gridSortCol = in_array(ee('Request')->get('grid_sort'), $gridSortAllowed)
            ? ee('Request')->get('grid_sort') : '';
        $gridSortDir = ee('Request')->get('grid_dir') === 'desc' ? 'desc' : 'asc';

        $gridResult = $this->buildTemplateGrid($gridPage, $gridPerPage, $gridTypeFilter, $gridActiveFilter, $gridSortCol, $gridSortDir);

        $gridBaseUrl = ee('CP/URL')->make('addons/settings/arc_haystack');
        $gridBaseUrl->setQueryStringVariable('grid_type', $gridTypeFilter);
        $gridBaseUrl->setQueryStringVariable('grid_active', $gridActiveFilter);
        if ($gridSortCol !== '') {
            $gridBaseUrl->setQueryStringVariable('grid_sort', $gridSortCol);
            $gridBaseUrl->setQueryStringVariable('grid_dir', $gridSortDir);
        }

        $gridSortBaseUrl = ee('CP/URL')->make('addons/settings/arc_haystack');
        $gridSortBaseUrl->setQueryStringVariable('grid_type', $gridTypeFilter);
        $gridSortBaseUrl->setQueryStringVariable('grid_active', $gridActiveFilter);

        $logSortBaseUrl = ee('CP/URL')->make('addons/settings/arc_haystack');

        $gridPagination = ee('CP/Pagination', $gridResult['total'])
            ->perPage($gridPerPage)
            ->currentPage($gridPage)
            ->queryStringVariable('grid_page')
            ->render($gridBaseUrl);

        $gridExportUrl = ee('CP/URL')->make('addons/settings/arc_haystack/grid_export');
        $gridExportUrl->setQueryStringVariable('grid_type', $gridTypeFilter);
        $gridExportUrl->setQueryStringVariable('grid_active', $gridActiveFilter);

        $vars = [
            'logs'               => $formattedLogs,
            'pagination'         => $pagination,
            'total_logs'         => $totalLogs,
            'template_grid'      => $gridResult['grid'],
            'grid_pagination'    => $gridPagination,
            'grid_type_filter'   => $gridTypeFilter,
            'grid_active_filter' => $gridActiveFilter,
            'grid_sort'          => $gridSortCol,
            'grid_dir'           => $gridSortDir,
            'grid_sort_base_url' => $gridSortBaseUrl->compile(),
            'log_sort'           => $logSortCol,
            'log_dir'            => $logSortDir,
            'log_sort_base_url'  => $logSortBaseUrl->compile(),
            'grid_filter_url'    => ee('CP/URL')->make('addons/settings/arc_haystack')->compile(),
            'grid_export_url'    => $gridExportUrl->compile(),
            'clear_url'       => ee('CP/URL')->make('addons/settings/arc_haystack/clear'),
            'export_url'      => ee('CP/URL')->make('addons/settings/arc_haystack/export'),
            'settings_url'    => ee('CP/URL')->make('addons/settings/arc_haystack'),
            'logging_enabled' => $settings['logging_enabled'] ?? 'y',
        ];

        $this->setHeading(lang($this->cp_page_title));
        $this->addBreadcrumb('index', 'arc_haystack_module_name');
        $this->setBody('index', $vars);

        return $this;
    }

    protected function buildTemplateGrid(int $page, int $perPage, string $typeFilter = 'all', string $activeFilter = 'all', string $sortCol = '', string $sortDir = 'asc'): array
    {
        $allRows = $this->fetchGridData($typeFilter, $activeFilter);

        if ($sortCol !== '') {
            usort($allRows, function ($a, $b) use ($sortCol, $sortDir) {
                $valA = $a[$sortCol] ?? '';
                $valB = $b[$sortCol] ?? '';
                if ($sortCol === 'logged_at') {
                    $cmp = ((int) $valA) <=> ((int) $valB);
                } elseif ($sortCol === 'active') {
                    $cmp = (int) $valA <=> (int) $valB;
                } else {
                    $cmp = strcasecmp((string) $valA, (string) $valB);
                }
                return $sortDir === 'desc' ? -$cmp : $cmp;
            });
        }

        $total = count($allRows);
        $paged = array_slice($allRows, ($page - 1) * $perPage, $perPage);

        foreach ($paged as &$row) {
            $row['logged_at'] = $row['logged_at'] ? ee()->localize->human_time($row['logged_at']) : null;
        }

        return ['grid' => $paged, 'total' => $total];
    }

    protected function getSettings(): array
    {
        return Settings::get();
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
            ee('CP/URL')->make('addons/settings/arc_haystack')->compile()
        );
    }
}
