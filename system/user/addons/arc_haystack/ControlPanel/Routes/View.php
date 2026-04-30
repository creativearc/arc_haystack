<?php

namespace CreativeArc\ArcHaystack\ControlPanel\Routes;

use CreativeArc\ArcHaystack\Traits\DetectsPhpCode;
use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

class View extends AbstractRoute
{
    use DetectsPhpCode;

    protected $route_path = 'view';
    protected $cp_page_title = 'log_details_title';

    public function process($id = false)
    {
        if (!$id) {
            ee('CP/Alert')->makeInline('shared-form')
                ->asWarning()
                ->withTitle(lang('invalid_log'))
                ->addToBody(lang('no_log_id_specified'))
                ->defer();

            ee()->functions->redirect(
                ee('CP/URL')->make('addons/settings/arc_haystack/logs')->compile()
            );
        }

        $log = ee()->db
            ->select('*')
            ->where('id', $id)
            ->get('arc_haystack_logs')
            ->row_array();

        if (!$log) {
            ee('CP/Alert')->makeInline('shared-form')
                ->asWarning()
                ->withTitle(lang('log_not_found'))
                ->addToBody(lang('log_not_found_message'))
                ->defer();

            ee()->functions->redirect(
                ee('CP/URL')->make('addons/settings/arc_haystack/logs')->compile()
            );
        }

        // Parse JSON fields
        $embedsUsed = !empty($log['embeds_used']) ? json_decode($log['embeds_used'], true) : [];
        $partialsUsed = !empty($log['partials_used']) ? json_decode($log['partials_used'], true) : [];
        $variablesUsed = !empty($log['variables_used']) ? json_decode($log['variables_used'], true) : [];

        // Get template paths - use raw database values
        $mainTemplatePath = !empty($log['main_template']) ? $log['main_template'] : $log['template_path'];
        $layoutTemplatePath = !empty($log['layout_template']) ? $log['layout_template'] : null;
        $calledFromPath = !empty($log['called_from']) ? $log['called_from'] : null;

        // Get template info for the main template
        $mainTemplateInfo = $this->getTemplateInfo($mainTemplatePath);

        // Get layout template info (if available)
        $layoutTemplateInfo = null;
        if ($layoutTemplatePath) {
            $layoutTemplateInfo = $this->getTemplateInfo($layoutTemplatePath);
        }

        // Get called_from template info (if available)
        $calledFromInfo = null;
        if ($calledFromPath) {
            $calledFromInfo = $this->getTemplateInfo($calledFromPath);
        }

        // Get template info for embeds
        $embedsInfo = [];
        if (is_array($embedsUsed)) {
            foreach ($embedsUsed as $embed) {
                $info = $this->getTemplateInfo($embed);
                $info['display_path'] = $embed;
                $embedsInfo[] = $info;
            }
        }

        // Get partial info
        $partialsInfo = [];
        if (is_array($partialsUsed)) {
            foreach ($partialsUsed as $partial) {
                $partialsInfo[] = $this->getPartialInfo($partial);
            }
        }

        // Get variable info
        $variablesInfo = [];
        if (is_array($variablesUsed)) {
            foreach ($variablesUsed as $variable) {
                $variablesInfo[] = $this->getVariableInfo($variable);
            }
        }

        // Pass the raw database row merged with formatted timestamp
        $logData = array_merge($log, [
            'logged_at' => ee()->localize->human_time($log['logged_at']),
        ]);

        $vars = [
            'log'              => $logData,
            'main_template'    => $mainTemplateInfo,
            'layout_template'  => $layoutTemplateInfo,
            'called_from'      => $calledFromInfo,
            'embeds'           => $embedsInfo,
            'partials'         => $partialsInfo,
            'variables'        => $variablesInfo,
            'back_url'         => ee('CP/URL')->make('addons/settings/arc_haystack/logs'),
        ];

        $this->setHeading(lang('log_details_title') . ': ' . $log['template_path']);
        $this->addBreadcrumb('index', 'arc_haystack_module_name');
        $this->setBody('view', $vars);

        return $this;
    }

    /**
     * Get detailed template information
     */
    protected function getTemplateInfo(string $templatePath): array
    {
        $parts = explode('/', $templatePath);
        if (count($parts) !== 2) {
            return [
                'path'       => $templatePath,
                'found'      => false,
                'error'      => lang('invalid_template_path_format'),
            ];
        }

        $groupName = $parts[0];
        $templateName = $parts[1];

        // Find the template group
        $group = ee('Model')->get('TemplateGroup')
            ->filter('group_name', $groupName)
            ->filter('site_id', ee()->config->item('site_id'))
            ->first();

        if (!$group) {
            return [
                'path'       => $templatePath,
                'found'      => false,
                'error'      => lang('template_group_not_found'),
            ];
        }

        // Find the template
        $template = ee('Model')->get('Template')
            ->filter('group_id', $group->group_id)
            ->filter('template_name', $templateName)
            ->first();

        if (!$template) {
            return [
                'path'       => $templatePath,
                'found'      => false,
                'error'      => lang('template_not_found'),
            ];
        }

        // Get file path
        $filePath = $template->getFilePath();

        // Get template content for analysis
        $templateData = $template->template_data ?? '';
        $lineCount = $templateData ? substr_count($templateData, "\n") + 1 : 0;

        // Check for PHP code
        $hasPhpCode = $this->detectPhpCode($templateData);

        // Get revision count
        $revisionCount = ee('Model')->get('RevisionTracker')
            ->filter('item_id', $template->template_id)
            ->filter('item_table', 'exp_templates')
            ->filter('item_field', 'template_data')
            ->count();

        // Get access roles
        $roles = $template->Roles;
        $roleNames = [];
        if ($roles) {
            foreach ($roles as $role) {
                $roleNames[] = $role->name;
            }
        }

        return [
            'path'              => $templatePath,
            'found'             => true,
            'template_id'       => $template->template_id,
            'template_name'     => $template->template_name,
            'template_group'    => $groupName,
            'template_type'     => $template->template_type,
            'file_path'         => $filePath ?: lang('database_only'),
            'line_count'        => $lineCount,
            'allow_php'         => $template->allow_php ?? 'n',
            'php_parse_location'=> $template->php_parse_location ?? '',
            'has_php_code'      => $hasPhpCode ? lang('yes') : lang('no'),
            'revision_count'    => $revisionCount,
            'access_roles'      => implode(', ', $roleNames) ?: lang('all_roles'),
            'cache_enabled'     => ($template->cache ?? 'n') === 'y' ? lang('yes') : lang('no'),
            'hits'              => $template->hits ?? 0,
            'edit_url'          => ee('CP/URL')->make('design/template/edit/' . $template->template_id),
        ];
    }

    /**
     * Get partial (snippet) information
     */
    protected function getPartialInfo(string $partialName): array
    {
        $partial = ee('Model')->get('Snippet')
            ->filter('snippet_name', $partialName)
            ->filter('site_id', 'IN', [ee()->config->item('site_id'), 0])
            ->first();

        if (!$partial) {
            return [
                'name'  => $partialName,
                'found' => false,
                'error' => lang('partial_not_found'),
            ];
        }

        $content = $partial->snippet_contents ?? '';
        $lineCount = $content ? substr_count($content, "\n") + 1 : 0;
        $filePath = $partial->getFilePath();

        return [
            'name'       => $partialName,
            'found'      => true,
            'snippet_id' => $partial->snippet_id,
            'file_path'  => $filePath ?: lang('database_only'),
            'line_count' => $lineCount,
            'site_id'    => $partial->site_id,
            'is_global'  => $partial->site_id == 0,
            'edit_url'   => ee('CP/URL')->make('design/snippets/edit/' . $partial->snippet_id),
        ];
    }

    /**
     * Get global variable information
     */
    protected function getVariableInfo(string $variableName): array
    {
        $variable = ee('Model')->get('GlobalVariable')
            ->filter('variable_name', $variableName)
            ->filter('site_id', 'IN', [ee()->config->item('site_id'), 0])
            ->first();

        if (!$variable) {
            return [
                'name'  => $variableName,
                'found' => false,
                'error' => lang('variable_not_found'),
            ];
        }

        $content = $variable->variable_data ?? '';
        $lineCount = $content ? substr_count($content, "\n") + 1 : 0;
        $filePath = $variable->getFilePath();

        return [
            'name'        => $variableName,
            'found'       => true,
            'variable_id' => $variable->variable_id,
            'file_path'   => $filePath ?: lang('database_only'),
            'line_count'  => $lineCount,
            'site_id'     => $variable->site_id,
            'is_global'   => $variable->site_id == 0,
            'edit_url'    => ee('CP/URL')->make('design/variables/edit/' . $variable->variable_id),
        ];
    }

}
