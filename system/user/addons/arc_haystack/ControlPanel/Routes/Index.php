<?php

namespace CreativeArc\ArcHaystack\ControlPanel\Routes;

use CreativeArc\ArcHaystack\Traits\BuildsTemplateGrid;
use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

class Index extends AbstractRoute
{
    use BuildsTemplateGrid;

    protected $route_path    = 'index';
    protected $cp_page_title = 'site_template_status_title';

    public function process($id = false)
    {
        $gridPerPage      = 25;
        $gridPage         = max(1, (int) ee('Request')->get('grid_page', 1));
        $gridTypeFilter   = ee('Request')->get('grid_type', 'all');
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

        $gridPagination = ee('CP/Pagination', $gridResult['total'])
            ->perPage($gridPerPage)
            ->currentPage($gridPage)
            ->queryStringVariable('grid_page')
            ->render($gridBaseUrl);

        $gridExportUrl = ee('CP/URL')->make('addons/settings/arc_haystack/grid_export');
        $gridExportUrl->setQueryStringVariable('grid_type', $gridTypeFilter);
        $gridExportUrl->setQueryStringVariable('grid_active', $gridActiveFilter);

        $vars = [
            'template_grid'      => $gridResult['grid'],
            'grid_pagination'    => $gridPagination,
            'grid_type_filter'   => $gridTypeFilter,
            'grid_active_filter' => $gridActiveFilter,
            'grid_sort'          => $gridSortCol,
            'grid_dir'           => $gridSortDir,
            'grid_sort_base_url' => $gridSortBaseUrl->compile(),
            'grid_filter_url'    => ee('CP/URL')->make('addons/settings/arc_haystack')->compile(),
            'grid_export_url'    => $gridExportUrl->compile(),
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
}
