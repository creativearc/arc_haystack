<?php

namespace CreativeArc\ArcHaystack\Tags;

use CreativeArc\ArcHaystack\Traits\DetectsPhpCode;
use ExpressionEngine\Service\Addon\Controllers\Tag\AbstractRoute;

class TemplateInfo extends AbstractRoute
{
    use DetectsPhpCode;

    /**
     * {exp:arc_haystack:template_info template="group/name"}
     * {exp:arc_haystack:template_info template_id="123"}
     *
     * Returns information about a specific template:
     * - {template_id}
     * - {template_name}
     * - {template_group}
     * - {template_path} (group/name)
     * - {template_type} (webpage, css, js, static, feed, xml, rss)
     * - {file_path} (system file location)
     * - {line_count}
     * - {allow_php} (y/n)
     * - {php_parse_location} (i=input, o=output)
     * - {has_php_code} (y/n)
     * - {revision_count}
     * - {access_roles} (comma-separated list of role names)
     * - {access_role_ids} (comma-separated list of role IDs)
     * - {cache_enabled} (y/n)
     * - {cache_refresh} (seconds)
     * - {hits}
     * - {last_edit_date}
     * - {protect_javascript} (y/n)
     */
    public function process()
    {
        $template = $this->getTemplate();

        if (!$template) {
            return ee()->TMPL->no_results();
        }

        $vars = $this->buildTemplateVars($template);
        $tagdata = ee()->TMPL->tagdata;

        // If no tag pair content, return simple formatted output
        if (empty(trim($tagdata))) {
            return $this->formatSimpleOutput($vars);
        }

        return ee()->TMPL->parse_variables_row($tagdata, $vars);
    }

    /**
     * Get the template based on parameters
     */
    protected function getTemplate()
    {
        $templateId = ee()->TMPL->fetch_param('template_id');
        $templatePath = ee()->TMPL->fetch_param('template');

        if ($templateId) {
            return ee('Model')->get('Template', $templateId)->first();
        }

        if ($templatePath) {
            $parts = explode('/', $templatePath);
            if (count($parts) !== 2) {
                return null;
            }

            $groupName = $parts[0];
            $templateName = $parts[1];

            // Find the template group first
            $group = ee('Model')->get('TemplateGroup')
                ->filter('group_name', $groupName)
                ->filter('site_id', ee()->config->item('site_id'))
                ->first();

            if (!$group) {
                return null;
            }

            return ee('Model')->get('Template')
                ->filter('group_id', $group->group_id)
                ->filter('template_name', $templateName)
                ->first();
        }

        return null;
    }

    /**
     * Build template variables array
     */
    protected function buildTemplateVars($template): array
    {
        $templateGroup = $template->getTemplateGroup();
        $groupName = $templateGroup ? $templateGroup->group_name : '';

        // Get file path
        $filePath = $template->getFilePath();

        // Get template content for analysis
        $templateData = $template->template_data ?? '';

        // Count lines
        $lineCount = $templateData ? substr_count($templateData, "\n") + 1 : 0;

        // Check for PHP code
        $hasPhpCode = $this->detectPhpCode($templateData);

        // Get revision count
        $revisionCount = $this->getRevisionCount($template->template_id);

        // Get access roles
        $roles = $template->Roles;
        $roleNames = [];
        $roleIds = [];

        if ($roles) {
            foreach ($roles as $role) {
                $roleNames[] = $role->name;
                $roleIds[] = $role->role_id;
            }
        }

        // Format last edit date
        $lastEditDate = '';
        if ($template->edit_date) {
            $lastEditDate = ee()->localize->human_time($template->edit_date);
        }

        return [
            'template_id'         => $template->template_id,
            'template_name'       => $template->template_name,
            'template_group'      => $groupName,
            'template_path'       => $groupName . '/' . $template->template_name,
            'template_type'       => $template->template_type,
            'file_path'           => $filePath ?: lang('database_only'),
            'line_count'          => $lineCount,
            'allow_php'           => $template->allow_php ?? 'n',
            'php_parse_location'  => $template->php_parse_location ?? '',
            'has_php_code'        => $hasPhpCode ? 'y' : 'n',
            'revision_count'      => $revisionCount,
            'access_roles'        => implode(', ', $roleNames),
            'access_role_ids'     => implode(', ', $roleIds),
            'cache_enabled'       => $template->cache ?? 'n',
            'cache_refresh'       => $template->refresh ?? 0,
            'hits'                => $template->hits ?? 0,
            'last_edit_date'      => $lastEditDate,
            'last_edit_timestamp' => $template->edit_date ?? 0,
            'protect_javascript'  => $template->protect_javascript ?? 'n',
            'http_auth_enabled'   => $template->enable_http_auth ?? 'n',
            'template_notes'      => $template->template_notes ?? '',
        ];
    }

    /**
     * Get revision count from the revision tracker
     */
    protected function getRevisionCount(int $templateId): int
    {
        return ee('Model')->get('RevisionTracker')
            ->filter('item_id', $templateId)
            ->filter('item_table', 'exp_templates')
            ->filter('item_field', 'template_data')
            ->count();
    }

    /**
     * Format output when used as a single tag
     */
    protected function formatSimpleOutput(array $vars): string
    {
        $format = ee()->TMPL->fetch_param('format', 'text');

        if ($format === 'json') {
            return json_encode($vars, JSON_PRETTY_PRINT);
        }

        $lines = [];
        $lines[] = lang('template_label') . ": {$vars['template_path']}";
        $lines[] = lang('id_label') . ": {$vars['template_id']}";
        $lines[] = lang('type_label') . ": {$vars['template_type']}";
        $lines[] = lang('file_label') . ": {$vars['file_path']}";
        $lines[] = lang('lines_label') . ": {$vars['line_count']}";
        $lines[] = lang('php_enabled_label') . ": {$vars['allow_php']}";
        $lines[] = lang('has_php_code_label') . ": {$vars['has_php_code']}";
        $lines[] = lang('revisions_label') . ": {$vars['revision_count']}";
        $lines[] = lang('access_label') . ": {$vars['access_roles']}";

        return implode("\n", $lines);
    }
}
