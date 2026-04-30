<?php

namespace CreativeArc\ArcHaystack\ControlPanel\Routes;

use CreativeArc\ArcHaystack\Settings;
use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

class Logs extends AbstractRoute
{
    protected $route_path    = 'logs';
    protected $cp_page_title = 'template_usage_logs_title';
    protected $layoutCountCache = [];

    public function process($id = false)
    {
        if (ee('Request')->isPost() && ee('Request')->post('save_settings') === 'y') {
            $this->saveSettings();
        }

        $settings = Settings::get();

        $perPage = 50;
        $page    = (int) ee('Request')->get('page', 1);
        $offset  = ($page - 1) * $perPage;

        $logSortAllowed = ['page_url', 'logged_at'];
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
                'layouts_count'   => $this->getLayoutCount(
                    ! empty($log['main_template']) ? $log['main_template'] : ($log['template_path'] ?? null),
                    $log['layout_template'] ?? null
                ),
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

    protected function getLayoutCount($mainTemplatePath, $layoutTemplate = null): int
    {
        $cacheKey = (string) $mainTemplatePath . '|' . (string) $layoutTemplate;
        if (isset($this->layoutCountCache[$cacheKey])) {
            return $this->layoutCountCache[$cacheKey];
        }

        $count = 0;
        $visited = [];
        $current = $this->normalizeTemplatePath($mainTemplatePath);

        while ($current && ! isset($visited[$current])) {
            $visited[$current] = true;
            $layoutPath = $this->findLayoutInTemplate($current);
            if (! $layoutPath || isset($visited[$layoutPath])) {
                break;
            }

            $count++;
            $current = $layoutPath;
        }

        if ($count === 0 && is_string($layoutTemplate) && trim($layoutTemplate) !== '') {
            $count = 1;
        }

        $this->layoutCountCache[$cacheKey] = $count;
        return $count;
    }

    protected function normalizeTemplatePath($path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);
        if ($path === '' || strpos($path, '/') === false) {
            return null;
        }

        [$group, $name] = explode('/', $path, 2);
        $group = trim($group);
        $name = trim($name);

        if ($group === '' || $name === '') {
            return null;
        }

        return $group . '/' . $name;
    }

    protected function findLayoutInTemplate(string $templatePath): ?string
    {
        [$groupName, $templateName] = explode('/', $templatePath, 2);

        $template = ee('Model')->get('Template')
            ->with('TemplateGroup')
            ->filter('template_name', $templateName)
            ->filter('TemplateGroup.group_name', $groupName)
            ->filter('TemplateGroup.site_id', ee()->config->item('site_id'))
            ->first();

        if (! $template) {
            return null;
        }

        $content = $template->template_data ?? '';
        if (is_string($content) && preg_match('/\{layout=["\']([^"\']+)["\']/i', $content, $matches)) {
            return $this->normalizeTemplatePath($matches[1]);
        }

        $filePath = $template->getFilePath();
        if ($filePath && file_exists($filePath)) {
            $fileContent = file_get_contents($filePath);
            if (is_string($fileContent) && preg_match('/\{layout=["\']([^"\']+)["\']/i', $fileContent, $matches)) {
                return $this->normalizeTemplatePath($matches[1]);
            }
        }

        return null;
    }
}
