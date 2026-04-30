<?php

namespace CreativeArc\ArcHaystack\Traits;

trait BuildsTemplateGrid
{
    /**
     * Returns the full (unpaginated) grid dataset.
     * logged_at is a raw unix timestamp (or null); callers format as needed.
     */
    protected function fetchGridData(string $typeFilter = 'all', string $activeFilter = 'all'): array
    {
        $dbp    = ee()->db->dbprefix;
        $siteId = (int) ee()->config->item('site_id');

        // Build UNION parts based on type filter
        $parts = [];

        if ($typeFilter === 'all' || $typeFilter === 'template') {
            $parts[] = "SELECT 'template' AS row_type, tg.group_name, t.template_name AS name
                        FROM {$dbp}templates t
                        INNER JOIN {$dbp}template_groups tg ON t.group_id = tg.group_id
                        WHERE tg.site_id = {$siteId} AND t.template_type != 'embed'";
        }

        if ($typeFilter === 'all' || $typeFilter === 'embed') {
            $parts[] = "SELECT 'embed' AS row_type, tg.group_name, t.template_name AS name
                        FROM {$dbp}templates t
                        INNER JOIN {$dbp}template_groups tg ON t.group_id = tg.group_id
                        WHERE tg.site_id = {$siteId} AND t.template_type = 'embed'";
        }

        if ($typeFilter === 'all' || $typeFilter === 'partial') {
            $parts[] = "SELECT 'partial' AS row_type, 'Partial' AS group_name, snippet_name AS name
                        FROM {$dbp}snippets WHERE site_id IN ({$siteId}, 0)";
        }

        if ($typeFilter === 'all' || $typeFilter === 'variable') {
            $parts[] = "SELECT 'variable' AS row_type, 'Variable' AS group_name, variable_name AS name
                        FROM {$dbp}global_variables WHERE site_id IN ({$siteId}, 0)";
        }

        if (empty($parts)) {
            return [];
        }

        $union = implode(' UNION ALL ', $parts);
        $rows  = ee()->db->query("
            SELECT row_type, group_name, name FROM ({$union}) combined
            ORDER BY FIELD(row_type, 'template', 'embed', 'partial', 'variable'), group_name ASC, name ASC
        ")->result_array();

        // Latest log timestamp per template path — seeds from main_template
        $mainLogs = ee()->db->query("
            SELECT main_template, MAX(logged_at) AS logged_at
            FROM {$dbp}arc_haystack_logs
            WHERE main_template IS NOT NULL AND main_template != ''
            GROUP BY main_template
        ")->result_array();

        $logsByPath = [];
        foreach ($mainLogs as $row) {
            $logsByPath[$row['main_template']] = (int) $row['logged_at'];
        }

        // Layout templates tracked by the Log tag
        $layoutLogs = ee()->db->query("
            SELECT layout_template, MAX(logged_at) AS logged_at
            FROM {$dbp}arc_haystack_logs
            WHERE layout_template IS NOT NULL AND layout_template != ''
            GROUP BY layout_template
        ")->result_array();

        foreach ($layoutLogs as $row) {
            $path = $row['layout_template'];
            $ts   = (int) $row['logged_at'];
            if (!isset($logsByPath[$path]) || $logsByPath[$path] < $ts) {
                $logsByPath[$path] = $ts;
            }
        }

        // Expand each logged item into its full nested layout chain so parent
        // layouts are marked active in Site Template Status as well.
        $layoutChainLogs = ee()->db->query("
            SELECT main_template, layout_template, logged_at
            FROM {$dbp}arc_haystack_logs
            WHERE (main_template IS NOT NULL AND main_template != '')
               OR (layout_template IS NOT NULL AND layout_template != '')
        ")->result_array();

        foreach ($layoutChainLogs as $row) {
            $ts = (int) $row['logged_at'];
            $chain = $this->getLayoutTemplateChainForGrid(
                $row['main_template'] ?? null,
                $row['layout_template'] ?? null
            );

            foreach ($chain as $layoutPath) {
                if (!isset($logsByPath[$layoutPath]) || $logsByPath[$layoutPath] < $ts) {
                    $logsByPath[$layoutPath] = $ts;
                }
            }
        }

        // Build a lookup of real embed template paths so we do not treat
        // non-embed templates (like layouts) as embeds when parsing JSON logs.
        $embedTemplateRows = ee()->db->query("
            SELECT tg.group_name, t.template_name
            FROM {$dbp}templates t
            INNER JOIN {$dbp}template_groups tg ON t.group_id = tg.group_id
            WHERE tg.site_id = {$siteId} AND t.template_type = 'embed'
        ")->result_array();

        $embedTemplatePaths = [];
        foreach ($embedTemplateRows as $row) {
            $embedTemplatePaths[$row['group_name'] . '/' . $row['template_name']] = true;
        }

        // Layout lookup for paths that may appear in embeds_used
        $layoutTemplateRows = ee()->db->query("
            SELECT tg.group_name, t.template_name, t.template_data
            FROM {$dbp}templates t
            INNER JOIN {$dbp}template_groups tg ON t.group_id = tg.group_id
            WHERE tg.site_id = {$siteId} AND t.template_type != 'embed'
        ")->result_array();

        $layoutTemplatePaths = [];
        foreach ($layoutTemplateRows as $row) {
            $groupName = (string) $row['group_name'];
            $templateName = (string) $row['template_name'];
            $templateData = (string) ($row['template_data'] ?? '');

            $isLayout = strpos($templateData, '{layout:contents}') !== false
                || stripos($groupName, 'layout') !== false
                || stripos($groupName, '_layouts') !== false
                || stripos($groupName, '_layout') !== false
                || stripos($templateName, 'layout') !== false
                || stripos($templateName, '_layout') !== false;

            if ($isLayout) {
                $layoutTemplatePaths[$groupName . '/' . $templateName] = true;
            }
        }

        // Templates that appeared as embeds or layouts via extension-based logging
        $embedLogs = ee()->db->query("
            SELECT embeds_used, logged_at
            FROM {$dbp}arc_haystack_logs
            WHERE embeds_used IS NOT NULL AND embeds_used != ''
            ORDER BY logged_at DESC
        ")->result_array();

        foreach ($embedLogs as $logRow) {
            $ts    = (int) $logRow['logged_at'];
            $paths = json_decode($logRow['embeds_used'], true) ?? [];
            foreach ($paths as $path) {
                if (!isset($embedTemplatePaths[$path]) && !isset($layoutTemplatePaths[$path])) {
                    continue;
                }

                if (!isset($logsByPath[$path])) {
                    $logsByPath[$path] = $ts;
                }
            }
        }

        // Most recent log timestamp per partial/variable (scan JSON columns DESC)
        $jsonLogs = ee()->db->query("
            SELECT partials_used, variables_used, logged_at
            FROM {$dbp}arc_haystack_logs
            WHERE (partials_used IS NOT NULL AND partials_used != '')
               OR (variables_used IS NOT NULL AND variables_used != '')
            ORDER BY logged_at DESC
        ")->result_array();

        $partialLastSeen  = [];
        $variableLastSeen = [];

        foreach ($jsonLogs as $logRow) {
            if (! empty($logRow['partials_used'])) {
                foreach (json_decode($logRow['partials_used'], true) ?? [] as $name) {
                    if (! isset($partialLastSeen[$name])) {
                        $partialLastSeen[$name] = (int) $logRow['logged_at'];
                    }
                }
            }
            if (! empty($logRow['variables_used'])) {
                foreach (json_decode($logRow['variables_used'], true) ?? [] as $name) {
                    if (! isset($variableLastSeen[$name])) {
                        $variableLastSeen[$name] = (int) $logRow['logged_at'];
                    }
                }
            }
        }

        $typeLabels = [
            'template' => 'Template',
            'embed'    => 'Embed',
            'partial'  => 'Partial',
            'variable' => 'Variable',
        ];

        $grid = [];
        foreach ($rows as $row) {
            $active   = false;
            $loggedAt = null;

            switch ($row['row_type']) {
                case 'template':
                case 'embed':
                    $path = $row['group_name'] . '/' . $row['name'];
                    if (isset($logsByPath[$path])) {
                        $active   = true;
                        $loggedAt = $logsByPath[$path];
                    }
                    break;
                case 'partial':
                    if (isset($partialLastSeen[$row['name']])) {
                        $active   = true;
                        $loggedAt = $partialLastSeen[$row['name']];
                    }
                    break;
                case 'variable':
                    if (isset($variableLastSeen[$row['name']])) {
                        $active   = true;
                        $loggedAt = $variableLastSeen[$row['name']];
                    }
                    break;
            }

            $grid[] = [
                'group_name' => $row['group_name'],
                'name'       => $row['name'],
                'type'       => $typeLabels[$row['row_type']] ?? $row['row_type'],
                'row_type'   => $row['row_type'],
                'active'     => $active,
                'logged_at'  => $loggedAt,
            ];
        }

        // Apply active filter in PHP (post-join, works for all types)
        if ($activeFilter === 'yes') {
            $grid = array_values(array_filter($grid, fn($r) => $r['active']));
        } elseif ($activeFilter === 'empty') {
            $grid = array_values(array_filter($grid, fn($r) => ! $r['active']));
        }

        return $grid;
    }

    protected function getLayoutTemplateChainForGrid($mainTemplatePath, $fallbackLayoutPath = null): array
    {
        $chain = [];
        $visited = [];
        $current = $this->normalizeTemplatePathForGrid($mainTemplatePath);

        while ($current && ! isset($visited[$current])) {
            $visited[$current] = true;
            $next = $this->findLayoutInTemplateForGrid($current);

            if (! $next || isset($visited[$next])) {
                break;
            }

            $chain[] = $next;
            $current = $next;
        }

        if (empty($chain)) {
            $fallback = $this->normalizeTemplatePathForGrid($fallbackLayoutPath);
            if ($fallback) {
                $chain[] = $fallback;
            }
        }

        return $chain;
    }

    protected function findLayoutInTemplateForGrid(string $templatePath): ?string
    {
        static $cache = [];
        $siteId = (int) ee()->config->item('site_id');
        $cacheKey = $siteId . '|' . $templatePath;

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        [$groupName, $templateName] = explode('/', $templatePath, 2);

        $template = ee('Model')->get('Template')
            ->with('TemplateGroup')
            ->filter('template_name', $templateName)
            ->filter('TemplateGroup.group_name', $groupName)
            ->filter('TemplateGroup.site_id', $siteId)
            ->first();

        if (! $template) {
            $cache[$cacheKey] = null;
            return null;
        }

        $content = $template->template_data ?? '';
        if (is_string($content) && preg_match('/\{layout=["\']([^"\']+)["\']/i', $content, $matches)) {
            $cache[$cacheKey] = $this->normalizeTemplatePathForGrid($matches[1]);
            return $cache[$cacheKey];
        }

        $filePath = $template->getFilePath();
        if ($filePath && file_exists($filePath)) {
            $fileContent = file_get_contents($filePath);
            if (is_string($fileContent) && preg_match('/\{layout=["\']([^"\']+)["\']/i', $fileContent, $matches)) {
                $cache[$cacheKey] = $this->normalizeTemplatePathForGrid($matches[1]);
                return $cache[$cacheKey];
            }
        }

        $cache[$cacheKey] = null;
        return null;
    }

    protected function normalizeTemplatePathForGrid($path): ?string
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
}
