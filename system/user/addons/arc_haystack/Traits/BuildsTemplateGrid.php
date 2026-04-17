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

        // Latest log timestamp per template path (for templates/embeds)
        $latestLogs = ee()->db->query("
            SELECT l.main_template, l.logged_at
            FROM {$dbp}arc_haystack_logs l
            INNER JOIN (
                SELECT main_template, MAX(logged_at) AS max_logged
                FROM {$dbp}arc_haystack_logs
                WHERE main_template IS NOT NULL AND main_template != ''
                GROUP BY main_template
            ) lm ON l.main_template = lm.main_template AND l.logged_at = lm.max_logged
        ")->result_array();

        $logsByPath = [];
        foreach ($latestLogs as $row) {
            $logsByPath[$row['main_template']] = (int) $row['logged_at'];
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
}
