<?php

namespace CreativeArc\ArcHaystack\Tags;

use CreativeArc\ArcHaystack\Settings;
use ExpressionEngine\Service\Addon\Controllers\Tag\AbstractRoute;

class Log extends AbstractRoute
{
    // Signals to Arc_haystack_ext that this request was already logged by the tag
    public static $didLog = false;

    /**
     * {exp:arc_haystack:log}
     *
     * Logs the current template path, page URL, embeds, partials, variables,
     * and timestamp to the database.
     * This tag outputs nothing and is intended for tracking template usage.
     *
     * Place this tag at the end of your layout or main template for best results.
     */
    public function process()
    {
        // Check if logging is enabled in addon settings
        $settings = Settings::get();
        if (($settings['logging_enabled'] ?? 'y') === 'n') {
            return '';
        }

        $templatePath = $this->getCurrentTemplatePath();
        $pageUrl = $this->getCurrentUrl();
        $timestamp = ee()->localize->now;

        // Detect main template, layout, and where the tag was called from
        $mainTemplate = $this->detectMainTemplate();
        $layoutTemplate = $this->detectLayoutTemplate();
        $calledFrom = $this->detectCalledFromTemplate();

        // Gather embeds, partials, and variables
        $embeds = $this->getUsedEmbeds();
        $partials = $this->getUsedPartials();
        $variables = $this->getUsedVariables();

        // Check if the new columns exist (added in v1.3.0)
        $fields = ee()->db->list_fields('arc_haystack_logs');
        $hasNewColumns = in_array('main_template', $fields);

        $data = [
            'template_path'   => $templatePath,
            'page_url'        => $pageUrl,
            'embeds_used'     => json_encode($embeds),
            'partials_used'   => json_encode($partials),
            'variables_used'  => json_encode($variables),
            'logged_at'       => $timestamp,
        ];

        // Add new columns if they exist
        if ($hasNewColumns) {
            $data['main_template'] = $mainTemplate;
            $data['layout_template'] = $layoutTemplate;
            $data['called_from'] = $calledFrom;
        }

        ee()->db->insert('arc_haystack_logs', $data);

        self::$didLog = true;
        if (class_exists('Arc_haystack_ext')) {
            \Arc_haystack_ext::$tagDidLog = true;
        }

        return '';
    }

    protected function getCurrentTemplatePath(): string
    {
        $group = ee()->TMPL->group_name ?? '';
        $template = ee()->TMPL->template_name ?? '';

        if ($group && $template) {
            return $group . '/' . $template;
        }

        return 'unknown';
    }

    protected function getCurrentUrl(): string
    {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);

        $protocol = $isSecure ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        return $protocol . $host . $uri;
    }

    /**
     * Get embeds used in the current page render
     */
    protected function getUsedEmbeds(): array
    {
        $embeds = [];
        $seen = [];

        $currentGroup = ee()->TMPL->group_name ?? '';
        $currentName = ee()->TMPL->template_name ?? '';

        // Parse template log for embed references
        $this->gatherEmbedsFromLog($embeds, $seen);

        // Check ee()->TMPL->embed_vars for embed tracking
        $this->gatherEmbedsFromEmbedVars($embeds, $seen);

        // Check templates_sofar property
        $this->gatherFromTemplatesSofar($embeds, $seen);

        // Scan raw template content for embed tags
        $this->gatherEmbedsFromRawContent($embeds, $seen);

        // Build a list of all templates that need to be scanned for embeds
        $templatesToScan = [];

        // 1. Always include the current template
        if ($currentGroup && $currentName) {
            $templatesToScan[$currentGroup . '/' . $currentName] = [
                'group' => $currentGroup,
                'name' => $currentName,
            ];
        }

        // 2. Include the main template (if detected and different)
        $mainTemplate = $this->detectMainTemplate();
        if ($mainTemplate && !isset($templatesToScan[$mainTemplate])) {
            $parts = explode('/', $mainTemplate);
            if (count($parts) >= 2) {
                $templatesToScan[$mainTemplate] = [
                    'group' => $parts[0],
                    'name' => $parts[1],
                ];
            }
        }

        // 3. Include the layout template (if detected)
        $layoutTemplate = $this->detectLayoutTemplate();
        if ($layoutTemplate && !isset($templatesToScan[$layoutTemplate])) {
            $parts = explode('/', $layoutTemplate);
            if (count($parts) >= 2) {
                $templatesToScan[$layoutTemplate] = [
                    'group' => $parts[0],
                    'name' => $parts[1],
                ];
            }
        }

        // Scan all collected templates for embeds
        foreach ($templatesToScan as $templateInfo) {
            $this->gatherEmbedsFromTemplateFile($embeds, $seen, $templateInfo['group'], $templateInfo['name']);
        }

        return array_values(array_filter($embeds, function ($name) {
            return !$this->containsTag($name);
        }));
    }

    /**
     * Gather embeds from the template log
     */
    protected function gatherEmbedsFromLog(array &$embeds, array &$seen): void
    {
        if (empty(ee()->TMPL->log) || !is_array(ee()->TMPL->log)) {
            return;
        }

        foreach (ee()->TMPL->log as $logEntry) {
            if (!is_string($logEntry)) {
                continue;
            }

            // Match various embed reference patterns
            $patterns = [
                '/Embed:\s*([^\/\s]+)\/([^\s\)\(]+)/i',
                '/Processing Embed[:\s]+([^\/\s]+)\/([^\s\)\(]+)/i',
                '/Embedding[:\s]+([^\/\s]+)\/([^\s\)\(]+)/i',
                '/\{embed=["\']?([^\/\s"\']+)\/([^\s"\'\}]+)/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $logEntry, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $embedGroup = trim($match[1], '"\'');
                        $embedName = trim($match[2], '"\'');
                        $embedPath = $embedGroup . '/' . $embedName;

                        if (!isset($seen[$embedPath])) {
                            $embeds[] = $embedPath;
                            $seen[$embedPath] = true;
                        }
                    }
                }
            }
        }
    }

    /**
     * Gather embeds from embed_vars property
     */
    protected function gatherEmbedsFromEmbedVars(array &$embeds, array &$seen): void
    {
        // Check ee()->TMPL->embed_vars for embed tracking
        if (!empty(ee()->TMPL->embed_vars) && is_array(ee()->TMPL->embed_vars)) {
            foreach (ee()->TMPL->embed_vars as $embedPath => $vars) {
                if (is_string($embedPath) && strpos($embedPath, '/') !== false) {
                    if (!isset($seen[$embedPath])) {
                        $embeds[] = $embedPath;
                        $seen[$embedPath] = true;
                    }
                }
            }
        }

        // Check for embeds stored in layout processing
        if (!empty(ee()->TMPL->layout_conditionals) && is_array(ee()->TMPL->layout_conditionals)) {
            foreach (ee()->TMPL->layout_conditionals as $key => $value) {
                if (preg_match('/embed:([^\/]+)\/(.+)/i', $key, $matches)) {
                    $embedPath = $matches[1] . '/' . $matches[2];
                    if (!isset($seen[$embedPath])) {
                        $embeds[] = $embedPath;
                        $seen[$embedPath] = true;
                    }
                }
            }
        }
    }

    /**
     * Gather templates from templates_sofar property
     */
    protected function gatherFromTemplatesSofar(array &$embeds, array &$seen): void
    {
        if (empty(ee()->TMPL->templates_sofar) || !is_array(ee()->TMPL->templates_sofar)) {
            return;
        }

        $isFirst = true;
        foreach (ee()->TMPL->templates_sofar as $templatePath) {
            if (!is_string($templatePath) || strpos($templatePath, '/') === false) {
                continue;
            }

            $parts = explode('/', $templatePath);
            $group = $parts[0];
            $name = $parts[1] ?? '';

            if (!$group || !$name) {
                continue;
            }

            // Skip layouts and the first template (main)
            if ($this->isLayoutTemplate($group, $name)) {
                continue;
            }

            // Skip the first non-layout template (that's the main template, not an embed)
            if ($isFirst) {
                $isFirst = false;
                continue;
            }

            if (!isset($seen[$templatePath])) {
                $embeds[] = $templatePath;
                $seen[$templatePath] = true;
            }
        }
    }

    /**
     * Scan raw template content for embed tags
     */
    protected function gatherEmbedsFromRawContent(array &$embeds, array &$seen): void
    {
        $contentSources = [];

        // Source 1: Current template being processed
        if (!empty(ee()->TMPL->template)) {
            $contentSources[] = ee()->TMPL->template;
        }

        // Source 2: Layout contents (the content from the original template)
        if (!empty(ee()->TMPL->layout_contents)) {
            $contentSources[] = ee()->TMPL->layout_contents;
        }

        // Source 3: Check for layout_data property
        if (!empty(ee()->TMPL->layout_data)) {
            $contentSources[] = ee()->TMPL->layout_data;
        }

        // Source 4: Check for final_template property
        if (!empty(ee()->TMPL->final_template)) {
            $contentSources[] = ee()->TMPL->final_template;
        }

        // Source 5: Check for the original template content stored in layout processing
        if (!empty(ee()->TMPL->layout) && is_array(ee()->TMPL->layout)) {
            if (!empty(ee()->TMPL->layout['contents'])) {
                $contentSources[] = ee()->TMPL->layout['contents'];
            }
            if (!empty(ee()->TMPL->layout['template'])) {
                $contentSources[] = ee()->TMPL->layout['template'];
            }
        }

        // Scan all content sources for embed tags
        foreach ($contentSources as $content) {
            if (!empty($content) && is_string($content)) {
                $this->extractEmbedsFromContent($content, $embeds, $seen);
            }
        }
    }

    /**
     * Scan a specific template file for embed tags
     */
    protected function gatherEmbedsFromTemplateFile(array &$embeds, array &$seen, string $group, string $name): void
    {
        // First find the template group
        $templateGroup = ee('Model')->get('TemplateGroup')
            ->filter('group_name', $group)
            ->filter('site_id', ee()->config->item('site_id'))
            ->first();

        if (!$templateGroup) {
            return;
        }

        // Then find the template in that group
        $template = ee('Model')->get('Template')
            ->filter('template_name', $name)
            ->filter('group_id', $templateGroup->group_id)
            ->first();

        if (!$template) {
            return;
        }

        $content = '';

        // Try to get file path first (for file-based templates)
        $filePath = $template->getFilePath();
        if ($filePath && file_exists($filePath)) {
            $content = file_get_contents($filePath);
        }

        // Fall back to database content
        if (empty($content) && !empty($template->template_data)) {
            $content = $template->template_data;
        }

        // Also try constructing the file path manually if getFilePath() didn't work
        if (empty($content)) {
            $basePath = ee()->config->item('tmpl_file_basepath');
            $siteName = ee()->config->item('site_short_name');
            if ($basePath && $siteName) {
                $manualPath = rtrim($basePath, '/') . '/' . $siteName . '/' . $group . '.group/' . $name . '.html';
                if (file_exists($manualPath)) {
                    $content = file_get_contents($manualPath);
                }
            }
        }

        if (!empty($content)) {
            $this->extractEmbedsFromContent($content, $embeds, $seen);
        }
    }

    /**
     * Extract embed references from template content
     */
    protected function extractEmbedsFromContent(string $content, array &$embeds, array &$seen): void
    {
        if (empty($content)) {
            return;
        }

        // Multiple patterns to catch various embed syntaxes
        $patterns = [
            // Standard: {embed="group/template"} or {embed='group/template'} with optional params
            '/\{embed=["\']([^"\']+)["\'][^\}]*\}/i',
            // Just the path portion: {embed="group/template"
            '/\{embed=["\']([^"\']+)["\']/i',
            // Without quotes: {embed=group/template}
            '/\{embed=([^\s\}"\']+)\s*\}/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[1] as $embedPath) {
                    $embedPath = trim($embedPath, '"\'');
                    if (strpos($embedPath, '/') !== false) {
                        $parts = explode('/', $embedPath);
                        $embedGroup = $parts[0];
                        $embedName = $parts[1] ?? '';

                        if ($embedGroup && $embedName && !isset($seen[$embedPath])) {
                            $embeds[] = $embedPath;
                            $seen[$embedPath] = true;

                            // Recursively scan the embed template for more embeds
                            $this->gatherEmbedsFromTemplateFile($embeds, $seen, $embedGroup, $embedName);
                        }
                    }
                }
            }
        }
    }

    /**
     * Get partials (snippets) used in the current page render
     */
    protected function getUsedPartials(): array
    {
        $usedPartials = [];

        // Get all partials from the database
        $partials = ee('Model')->get('Snippet')
            ->filter('site_id', 'IN', [ee()->config->item('site_id'), 0])
            ->all();

        if (!$partials) {
            return $usedPartials;
        }

        // Get global vars where partials are stored
        $globalVars = ee()->config->_global_vars ?? [];

        // Check template log for partial usage
        $logPartials = $this->getPartialsFromLog();

        foreach ($partials as $partial) {
            $name = $partial->snippet_name;

            // Check if this partial was actually used in the current page render
            // Note: We can't rely on isset($globalVars[$name]) because EE preloads ALL partials
            // into _global_vars at startup, regardless of whether they're used on this page
            $wasUsed = isset($logPartials[$name]) ||
                       $this->wasPartialUsedInLog($name) ||
                       $this->wasPartialUsedInTemplates($name);

            if ($wasUsed && !in_array($name, $usedPartials) && !$this->containsTag($name)) {
                $usedPartials[] = $name;
            }
        }

        return $usedPartials;
    }

    /**
     * Get global variables used in the current page render
     */
    protected function getUsedVariables(): array
    {
        $usedVariables = [];

        // Get global vars where variables are stored
        $globalVars = ee()->config->_global_vars ?? [];

        // Check template log for variable usage
        $logVars = $this->getVariablesFromLog();

        // Get all global variables from the database
        $variables = ee('Model')->get('GlobalVariable')
            ->filter('site_id', 'IN', [ee()->config->item('site_id'), 0])
            ->all();

        if ($variables) {
            foreach ($variables as $variable) {
                $name = $variable->variable_name;

                // Check if this variable was actually used in the current page render
                // Note: We can't rely on isset($globalVars[$name]) because EE preloads ALL variables
                // into _global_vars at startup, regardless of whether they're used on this page
                $wasUsed = isset($logVars[$name]) ||
                           $this->wasVariableUsedInLog($name) ||
                           $this->wasVariableUsedInTemplates($name);

                if ($wasUsed && !in_array($name, $usedVariables) && !$this->containsTag($name)) {
                    $usedVariables[] = $name;
                }
            }
        }

        // Also check for file-based template variables in _variables folder
        $this->gatherFileBasedVariables($usedVariables);

        return $usedVariables;
    }

    /**
     * Gather file-based template variables from _variables folder
     */
    protected function gatherFileBasedVariables(array &$usedVariables): void
    {
        $basePath = $this->getTemplateBasePath();
        $siteName = ee()->config->item('site_short_name');

        if (!$basePath || !$siteName) {
            return;
        }

        // Normalize path separators for cross-platform compatibility
        $basePath = str_replace('\\', '/', rtrim($basePath, '/\\'));
        $variablesPath = $basePath . '/' . $siteName . '/_variables';

        if (!is_dir($variablesPath)) {
            return;
        }

        // Use scandir as fallback if glob fails (Windows compatibility)
        $files = glob($variablesPath . '/*.html');
        if (!$files) {
            $allFiles = @scandir($variablesPath);
            if ($allFiles) {
                $files = [];
                foreach ($allFiles as $f) {
                    if (substr($f, -5) === '.html') {
                        $files[] = $variablesPath . '/' . $f;
                    }
                }
            }
        }

        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);

            // Skip if already found
            if (in_array($name, $usedVariables)) {
                continue;
            }

            // Only include file-based variables that were actually used in the current page render
            if ($this->wasVariableUsedInTemplates($name) && !$this->containsTag($name)) {
                $usedVariables[] = $name;
            }
        }
    }

    /**
     * Get the template base path with fallbacks
     */
    protected function getTemplateBasePath(): ?string
    {
        // Try PATH_TMPL constant
        if (defined('PATH_TMPL') && is_dir(PATH_TMPL)) {
            return PATH_TMPL;
        }

        // Try constructing from SYSPATH
        if (defined('SYSPATH')) {
            $path = SYSPATH . 'user/templates';
            if (is_dir($path)) {
                return $path;
            }
        }

        // Try relative to document root
        if (isset($_SERVER['DOCUMENT_ROOT'])) {
            $path = $_SERVER['DOCUMENT_ROOT'] . '/../system/user/templates';
            if (is_dir($path)) {
                return realpath($path);
            }
        }

        return null;
    }

    /**
     * Parse template log for partials
     */
    protected function getPartialsFromLog(): array
    {
        $used = [];

        if (!empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            foreach (ee()->TMPL->log as $logEntry) {
                if (is_string($logEntry)) {
                    // Match "Template Partial" references
                    if (preg_match('/Template Partial[:\s]+"?([^"]+)"?/i', $logEntry, $matches)) {
                        $used[$matches[1]] = true;
                    }
                    // Match snippet references
                    if (preg_match('/Snippet[:\s]+([^\s\)]+)/i', $logEntry, $matches)) {
                        $used[$matches[1]] = true;
                    }
                }
            }
        }

        return $used;
    }

    /**
     * Parse template log for variables
     */
    protected function getVariablesFromLog(): array
    {
        $used = [];

        if (!empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            foreach (ee()->TMPL->log as $logEntry) {
                if (is_string($logEntry)) {
                    // Match variable references in log
                    if (preg_match('/Variable[:\s]+([^\s\)]+)/i', $logEntry, $matches)) {
                        $used[$matches[1]] = true;
                    }
                }
            }
        }

        return $used;
    }

    /**
     * Check if a partial name appears in the template log
     */
    protected function wasPartialUsedInLog(string $name): bool
    {
        if (!empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            $logText = implode(' ', array_filter(ee()->TMPL->log, 'is_string'));
            if (stripos($logText, $name) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a variable name appears in the template log
     */
    protected function wasVariableUsedInLog(string $name): bool
    {
        if (!empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            $logText = implode(' ', array_filter(ee()->TMPL->log, 'is_string'));
            if (stripos($logText, $name) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a partial was actually used in the rendered templates
     * by scanning template content for the partial tag
     */
    protected function wasPartialUsedInTemplates(string $name): bool
    {
        $contentSources = $this->getTemplateContentSources();
        foreach ($contentSources as $content) {
            // Look for the partial tag pattern: {partial_name} or {partial_name ...}
            if (preg_match('/\{' . preg_quote($name, '/') . '(?:\s|\})/i', $content)) {
                return true;
            }
        }

        // Also scan raw template files from disk — by the time {exp:arc_haystack:log}
        // runs, EE has already replaced partial tags with their content, so runtime
        // TMPL properties no longer contain the original {partial_name} tags.
        return $this->wasPartialUsedInRawTemplateFiles($name);
    }

    /**
     * Check if a partial tag appears in the raw (unprocessed) template files on disk.
     * This catches partials used in layout templates, which are fully processed before
     * the log tag runs.
     */
    protected function wasPartialUsedInRawTemplateFiles(string $name): bool
    {
        $pattern = '/\{' . preg_quote($name, '/') . '(?:\s|\})/i';

        $basePath = $this->getTemplateBasePath();
        $siteName = ee()->config->item('site_short_name');

        if (!$basePath || !$siteName) {
            return false;
        }

        $basePath = str_replace('\\', '/', rtrim($basePath, '/\\'));

        // Build list of template group/name pairs to scan
        $templatesToScan = [];

        $currentGroup = ee()->TMPL->group_name ?? '';
        $currentName  = ee()->TMPL->template_name ?? '';
        if ($currentGroup && $currentName) {
            $templatesToScan[] = [$currentGroup, $currentName];
        }

        $mainTemplate = $this->detectMainTemplate();
        if ($mainTemplate && strpos($mainTemplate, '/') !== false) {
            [$mg, $mn] = explode('/', $mainTemplate, 2);
            if ($mg && $mn) {
                $templatesToScan[] = [$mg, $mn];
            }
        }

        $layoutTemplate = $this->detectLayoutTemplate();
        if ($layoutTemplate && strpos($layoutTemplate, '/') !== false) {
            [$lg, $ln] = explode('/', $layoutTemplate, 2);
            if ($lg && $ln) {
                $templatesToScan[] = [$lg, $ln];
            }
        }

        foreach ($templatesToScan as [$group, $tname]) {
            $filePath = $basePath . '/' . $siteName . '/' . $group . '.group/' . $tname . '.html';
            if (file_exists($filePath)) {
                $content = file_get_contents($filePath);
                if ($content !== false && preg_match($pattern, $content)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a variable was actually used in the rendered templates
     * by scanning template content for the variable tag
     */
    protected function wasVariableUsedInTemplates(string $name): bool
    {
        $contentSources = $this->getTemplateContentSources();
        foreach ($contentSources as $content) {
            // Look for the variable tag pattern: {variable_name}
            if (preg_match('/\{' . preg_quote($name, '/') . '\}/i', $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all template content sources for scanning
     */
    protected function getTemplateContentSources(): array
    {
        $contentSources = [];

        // Source 1: Current template being processed
        if (!empty(ee()->TMPL->template)) {
            $contentSources[] = ee()->TMPL->template;
        }

        // Source 2: Layout contents
        if (!empty(ee()->TMPL->layout_contents)) {
            $contentSources[] = ee()->TMPL->layout_contents;
        }

        // Source 3: Layout data
        if (!empty(ee()->TMPL->layout_data)) {
            $contentSources[] = ee()->TMPL->layout_data;
        }

        // Source 4: Final template
        if (!empty(ee()->TMPL->final_template)) {
            $contentSources[] = ee()->TMPL->final_template;
        }

        // Source 5: Layout array contents
        if (!empty(ee()->TMPL->layout) && is_array(ee()->TMPL->layout)) {
            if (!empty(ee()->TMPL->layout['contents'])) {
                $contentSources[] = ee()->TMPL->layout['contents'];
            }
            if (!empty(ee()->TMPL->layout['template'])) {
                $contentSources[] = ee()->TMPL->layout['template'];
            }
        }

        return array_filter($contentSources, 'is_string');
    }

    /**
     * Detect layout template from multiple EE sources
     */
    protected function detectLayoutTemplate(): ?string
    {
        $currentGroup = ee()->TMPL->group_name ?? '';
        $currentName = ee()->TMPL->template_name ?? '';

        // Method 1: Scan current template content for {layout=""} tag
        // This works when the tag is called from within a content template
        $templateContent = ee()->TMPL->template ?? '';
        if (!empty($templateContent)) {
            if (preg_match('/\{layout=["\']([^"\']+)["\']/i', $templateContent, $matches)) {
                return $matches[1];
            }
        }

        // Method 2: Scan the current template FILE for {layout=""} tag
        // This is needed when template content has already been partially processed
        if ($currentGroup && $currentName && !$this->isLayoutTemplateByContent($currentGroup, $currentName)) {
            $layoutPath = $this->findLayoutInTemplateFile($currentGroup, $currentName);
            if ($layoutPath) {
                return $layoutPath;
            }
        }

        // Method 3: Check if the CURRENT template is itself a layout
        // This is the case when the tag is placed inside a layout template
        if ($currentGroup && $currentName) {
            if ($this->isLayoutTemplateByContent($currentGroup, $currentName)) {
                return $currentGroup . '/' . $currentName;
            }
        }

        // Method 4: Check ee()->TMPL->layout_name (standard EE property)
        if (!empty(ee()->TMPL->layout_name)) {
            return ee()->TMPL->layout_name;
        }

        // Method 5: Check ee()->TMPL->layout property (EE7+ may use this)
        if (!empty(ee()->TMPL->layout)) {
            if (is_string(ee()->TMPL->layout)) {
                return ee()->TMPL->layout;
            }
            if (is_array(ee()->TMPL->layout) && !empty(ee()->TMPL->layout['template'])) {
                return ee()->TMPL->layout['template'];
            }
        }

        // Method 6: Check ee()->TMPL->layout_vars for layout info
        if (!empty(ee()->TMPL->layout_vars) && is_array(ee()->TMPL->layout_vars)) {
            if (!empty(ee()->TMPL->layout_vars['layout:template'])) {
                return ee()->TMPL->layout_vars['layout:template'];
            }
        }

        // Method 7: Parse template log for layout references
        if (!empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            foreach (ee()->TMPL->log as $logEntry) {
                if (is_string($logEntry)) {
                    // Match "Layout Template: group/template" or similar
                    if (preg_match('/Layout(?:\s+Template)?[:\s]+([^\/\s]+)\/([^\s\)]+)/i', $logEntry, $matches)) {
                        return $matches[1] . '/' . $matches[2];
                    }
                    // Match "Processing Layout: group/template"
                    if (preg_match('/Processing Layout[:\s]+([^\/\s]+)\/([^\s\)]+)/i', $logEntry, $matches)) {
                        return $matches[1] . '/' . $matches[2];
                    }
                    // Match "{layout="group/template"}" pattern in log
                    if (preg_match('/\{layout=["\']?([^\/\s"\']+)\/([^\s"\'\}]+)/i', $logEntry, $matches)) {
                        return $matches[1] . '/' . $matches[2];
                    }
                }
            }
        }

        // Method 8: Scan the main template file for {layout=""} tag (from Pages module)
        $mainTemplate = $this->findTemplateFromPagesModule();
        if ($mainTemplate) {
            $parts = explode('/', $mainTemplate);
            if (count($parts) >= 2) {
                $layoutPath = $this->findLayoutInTemplateFile($parts[0], $parts[1]);
                if ($layoutPath) {
                    return $layoutPath;
                }
            }
        }

        // Method 9: Check templates_sofar for content templates and scan for layout tags
        if (!empty(ee()->TMPL->templates_sofar) && is_array(ee()->TMPL->templates_sofar)) {
            foreach (ee()->TMPL->templates_sofar as $templatePath) {
                if (is_string($templatePath) && strpos($templatePath, '/') !== false) {
                    $parts = explode('/', $templatePath);
                    if (count($parts) >= 2 && !$this->isLayoutTemplate($parts[0], $parts[1])) {
                        $layoutPath = $this->findLayoutInTemplateFile($parts[0], $parts[1]);
                        if ($layoutPath) {
                            return $layoutPath;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check if a template is a layout by examining its content for {layout:contents}
     */
    protected function isLayoutTemplateByContent(string $group, string $name): bool
    {
        if (!$group || !$name) {
            return false;
        }

        // Check by group/name pattern first (fast check)
        $layoutPatterns = ['layout', 'layouts', '_layout', '_layouts'];
        foreach ($layoutPatterns as $pattern) {
            if (stripos($group, $pattern) !== false) {
                return true;
            }
        }

        // Check template content for {layout:contents} tag
        $template = ee('Model')->get('Template')
            ->with('TemplateGroup')
            ->filter('template_name', $name)
            ->filter('TemplateGroup.group_name', $group)
            ->filter('TemplateGroup.site_id', ee()->config->item('site_id'))
            ->first();

        if ($template) {
            $content = $template->template_data ?? '';
            if (strpos($content, '{layout:contents}') !== false) {
                return true;
            }

            // Check the file if templates are saved as files
            $filePath = $template->getFilePath();
            if ($filePath && file_exists($filePath)) {
                $fileContent = file_get_contents($filePath);
                if (strpos($fileContent, '{layout:contents}') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find the layout tag in a template file
     */
    protected function findLayoutInTemplateFile(string $group, string $name): ?string
    {
        $template = ee('Model')->get('Template')
            ->with('TemplateGroup')
            ->filter('template_name', $name)
            ->filter('TemplateGroup.group_name', $group)
            ->filter('TemplateGroup.site_id', ee()->config->item('site_id'))
            ->first();

        if (!$template) {
            return null;
        }

        $content = '';

        // Try file first
        $filePath = $template->getFilePath();
        if ($filePath && file_exists($filePath)) {
            $content = file_get_contents($filePath);
        }

        // Fall back to database
        if (empty($content)) {
            $content = $template->template_data ?? '';
        }

        // Search for {layout=""} tag
        if (!empty($content) && preg_match('/\{layout=["\']([^"\']+)["\']/i', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Detect the main (content) template that initiated the page request
     */
    protected function detectMainTemplate(): ?string
    {
        $currentGroup = ee()->TMPL->group_name ?? '';
        $currentName = ee()->TMPL->template_name ?? '';
        $isCurrentLayout = $this->isLayoutTemplate($currentGroup, $currentName);
        $siteId = ee()->config->item('site_id');

        // Method 1: Check Pages/Structure module data first (most reliable for page-based sites)
        $result = $this->findTemplateFromPagesModule();
        if ($result) {
            return $result;
        }

        // Method 2: Check ee()->TMPL->templates_sofar for the first non-layout template
        if (!empty(ee()->TMPL->templates_sofar) && is_array(ee()->TMPL->templates_sofar)) {
            foreach (ee()->TMPL->templates_sofar as $templatePath) {
                if (is_string($templatePath) && strpos($templatePath, '/') !== false) {
                    $parts = explode('/', $templatePath);
                    if (count($parts) >= 2 && !$this->isLayoutTemplate($parts[0], $parts[1])) {
                        return $templatePath;
                    }
                }
            }
        }

        // Method 3: Check the template log for the first non-layout template loaded
        if (!empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            foreach (ee()->TMPL->log as $logEntry) {
                if (is_string($logEntry)) {
                    // Match "Parsing Template: group/template"
                    if (preg_match('/Parsing Template[:\s]+([^\/\s]+)\/([^\s\(\)]+)/i', $logEntry, $matches)) {
                        if (!$this->isLayoutTemplate($matches[1], $matches[2])) {
                            return $matches[1] . '/' . $matches[2];
                        }
                    }
                    // Match "Template: group/template"
                    if (preg_match('/^Template[:\s]+([^\/\s]+)\/([^\s\(\)]+)/i', $logEntry, $matches)) {
                        if (!$this->isLayoutTemplate($matches[1], $matches[2])) {
                            return $matches[1] . '/' . $matches[2];
                        }
                    }
                }
            }
        }

        // Method 4: Try to find from TMPL properties when in a layout
        if ($isCurrentLayout) {
            $result = $this->findContentTemplateFromTmpl();
            if ($result) {
                return $result;
            }
        }

        // Method 5: Use URI segments to determine the requested template
        $seg1 = ee()->uri->segment(1);
        $seg2 = ee()->uri->segment(2);

        if ($seg1) {
            $group = ee('Model')->get('TemplateGroup')
                ->filter('group_name', $seg1)
                ->filter('site_id', $siteId)
                ->first();

            if ($group && !$this->isLayoutTemplate($group->group_name, '')) {
                $templateName = $seg2 ?: 'index';
                $template = ee('Model')->get('Template')
                    ->filter('group_id', $group->group_id)
                    ->filter('template_name', $templateName)
                    ->first();

                if ($template) {
                    return $seg1 . '/' . $templateName;
                }

                // Template name not found, try index
                $indexTemplate = ee('Model')->get('Template')
                    ->filter('group_id', $group->group_id)
                    ->filter('template_name', 'index')
                    ->first();

                if ($indexTemplate) {
                    return $seg1 . '/index';
                }
            }
        }

        // Method 6: For Pages/Structure URLs that don't match template groups,
        // fall back to default group with 'pages' template
        if ($isCurrentLayout) {
            $defaultGroup = ee('Model')->get('TemplateGroup')
                ->filter('site_id', $siteId)
                ->filter('is_site_default', 'y')
                ->first();

            if ($defaultGroup) {
                // Try 'pages' template first (common for Pages/Structure setups)
                $pagesTemplate = ee('Model')->get('Template')
                    ->filter('group_id', $defaultGroup->group_id)
                    ->filter('template_name', 'pages')
                    ->first();

                if ($pagesTemplate) {
                    return $defaultGroup->group_name . '/pages';
                }

                // Fall back to index
                return $defaultGroup->group_name . '/index';
            }
        }

        // Method 7: Get the default template group for homepage
        if (!$seg1) {
            $defaultTemplateGroup = ee('Model')->get('TemplateGroup')
                ->filter('site_id', $siteId)
                ->filter('is_site_default', 'y')
                ->first();

            if ($defaultTemplateGroup) {
                return $defaultTemplateGroup->group_name . '/index';
            }
        }

        // Method 8: If current template is not a layout, use it as main
        if ($currentGroup && $currentName && !$isCurrentLayout) {
            return $currentGroup . '/' . $currentName;
        }

        return null;
    }

    /**
     * Find template from Pages/Structure module data
     */
    protected function findTemplateFromPagesModule(): ?string
    {
        $sitePages = ee()->config->item('site_pages');
        $siteId = ee()->config->item('site_id');

        if (empty($sitePages[$siteId]['uris']) || empty($sitePages[$siteId]['templates'])) {
            return null;
        }

        // Get the current URI in multiple formats to improve matching
        $uriString = ee()->uri->uri_string();
        $currentUri = '/' . trim($uriString, '/');

        // Also try from REQUEST_URI for better accuracy
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $requestUri = strtok($requestUri, '?'); // Remove query string
        $requestUri = preg_replace('#/index\.php/?#', '/', $requestUri);
        $requestUri = '/' . trim($requestUri, '/');

        // Normalize URIs for comparison
        $urisToCheck = array_unique([
            $currentUri,
            $requestUri,
            rtrim($currentUri, '/'),
            rtrim($requestUri, '/'),
            $currentUri . '/',
            $requestUri . '/',
        ]);

        foreach ($sitePages[$siteId]['uris'] as $entryId => $pageUri) {
            $normalizedPageUri = '/' . trim($pageUri, '/');

            foreach ($urisToCheck as $checkUri) {
                if ($checkUri === '/' && $normalizedPageUri === '/') {
                    // Homepage match
                    $templateId = $sitePages[$siteId]['templates'][$entryId] ?? null;
                    if ($templateId) {
                        return $this->getTemplatePathById($templateId);
                    }
                }

                if ($normalizedPageUri === $checkUri ||
                    $normalizedPageUri === rtrim($checkUri, '/') ||
                    rtrim($normalizedPageUri, '/') === $checkUri) {
                    $templateId = $sitePages[$siteId]['templates'][$entryId] ?? null;
                    if ($templateId) {
                        return $this->getTemplatePathById($templateId);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Get template path by ID (only if not a layout)
     */
    protected function getTemplatePathById(int $templateId): ?string
    {
        $template = ee('Model')->get('Template', $templateId)
            ->with('TemplateGroup')
            ->first();

        if ($template && $template->TemplateGroup) {
            $groupName = $template->TemplateGroup->group_name;
            $templateName = $template->template_name;

            if (!$this->isLayoutTemplate($groupName, $templateName)) {
                return $groupName . '/' . $templateName;
            }
        }

        return null;
    }

    /**
     * Try to find the content template from ee()->TMPL properties
     */
    protected function findContentTemplateFromTmpl(): ?string
    {
        $siteId = ee()->config->item('site_id');

        // Method 1: Check template_id and look it up (skip if it's a layout)
        if (!empty(ee()->TMPL->template_id)) {
            $template = ee('Model')->get('Template', ee()->TMPL->template_id)
                ->with('TemplateGroup')
                ->first();

            if ($template && $template->TemplateGroup) {
                $groupName = $template->TemplateGroup->group_name;
                if (!$this->isLayoutTemplate($groupName, $template->template_name)) {
                    return $groupName . '/' . $template->template_name;
                }
            }
        }

        // Method 2: Check if there's a primary template stored
        if (!empty(ee()->TMPL->primary_template)) {
            if (strpos(ee()->TMPL->primary_template, '/') !== false) {
                $parts = explode('/', ee()->TMPL->primary_template);
                if (!$this->isLayoutTemplate($parts[0], $parts[1] ?? '')) {
                    return ee()->TMPL->primary_template;
                }
            }
        }

        // Method 3: Parse REQUEST_URI to determine the template
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (!empty($requestUri)) {
            $requestUri = strtok($requestUri, '?');
            $requestUri = preg_replace('#/index\.php/?#', '/', $requestUri);
            $requestUri = trim($requestUri, '/');

            if (empty($requestUri)) {
                // Homepage - use default group
                $defaultGroup = ee('Model')->get('TemplateGroup')
                    ->filter('site_id', $siteId)
                    ->filter('is_site_default', 'y')
                    ->first();

                if ($defaultGroup) {
                    return $defaultGroup->group_name . '/index';
                }
            } else {
                // Parse URI parts
                $uriParts = explode('/', $requestUri);
                $potentialGroup = $uriParts[0] ?? '';
                $potentialTemplate = $uriParts[1] ?? 'index';

                // Check if it's a valid template group
                $group = ee('Model')->get('TemplateGroup')
                    ->filter('group_name', $potentialGroup)
                    ->filter('site_id', $siteId)
                    ->first();

                if ($group && !$this->isLayoutTemplate($group->group_name, '')) {
                    $template = ee('Model')->get('Template')
                        ->filter('group_id', $group->group_id)
                        ->filter('template_name', $potentialTemplate)
                        ->first();

                    if ($template) {
                        return $potentialGroup . '/' . $potentialTemplate;
                    }

                    // Try index template
                    $indexTemplate = ee('Model')->get('Template')
                        ->filter('group_id', $group->group_id)
                        ->filter('template_name', 'index')
                        ->first();

                    if ($indexTemplate) {
                        return $potentialGroup . '/index';
                    }
                }

                // URI doesn't match a template group - it's likely a Pages/Structure URL
                // The default group's 'pages' template handles these
                $defaultGroup = ee('Model')->get('TemplateGroup')
                    ->filter('site_id', $siteId)
                    ->filter('is_site_default', 'y')
                    ->first();

                if ($defaultGroup) {
                    $pagesTemplate = ee('Model')->get('Template')
                        ->filter('group_id', $defaultGroup->group_id)
                        ->filter('template_name', 'pages')
                        ->first();

                    if ($pagesTemplate) {
                        return $defaultGroup->group_name . '/pages';
                    }

                    return $defaultGroup->group_name . '/index';
                }
            }
        }

        return null;
    }

    /**
     * Detect which template the {exp:arc_haystack:log} tag was called from
     */
    protected function detectCalledFromTemplate(): ?string
    {
        // Method 1: Check current TMPL properties (most reliable)
        $currentGroup = ee()->TMPL->group_name ?? '';
        $currentName = ee()->TMPL->template_name ?? '';

        if ($currentGroup && $currentName) {
            return $currentGroup . '/' . $currentName;
        }

        // Method 2: Check template_id to get the template info
        if (!empty(ee()->TMPL->template_id)) {
            $template = ee('Model')->get('Template', ee()->TMPL->template_id)
                ->with('TemplateGroup')
                ->first();

            if ($template && $template->TemplateGroup) {
                return $template->TemplateGroup->group_name . '/' . $template->template_name;
            }
        }

        // Method 3: Check templates_sofar for the FIRST template (if called early)
        // or the LAST template (if called late in processing)
        if (!empty(ee()->TMPL->templates_sofar) && is_array(ee()->TMPL->templates_sofar)) {
            $templatesSofar = ee()->TMPL->templates_sofar;

            // Try the first template first (might be the main template)
            $firstTemplate = reset($templatesSofar);
            if (is_string($firstTemplate) && strpos($firstTemplate, '/') !== false) {
                return $firstTemplate;
            }

            // Fall back to the last template
            $lastTemplate = end($templatesSofar);
            if (is_string($lastTemplate) && strpos($lastTemplate, '/') !== false) {
                return $lastTemplate;
            }
        }

        // Method 4: Parse template log for the FIRST template reference (main template)
        if (!empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            foreach (ee()->TMPL->log as $logEntry) {
                if (is_string($logEntry)) {
                    if (preg_match('/Parsing Template[:\s]+([^\/\s]+)\/([^\s\(\)]+)/i', $logEntry, $matches)) {
                        return $matches[1] . '/' . $matches[2];
                    }
                }
            }
        }

        // Method 5: Try to find from Pages module (content template)
        $mainTemplate = $this->findTemplateFromPagesModule();
        if ($mainTemplate) {
            return $mainTemplate;
        }

        // Method 6: Try to determine from URI segments
        $seg1 = ee()->uri->segment(1);
        $seg2 = ee()->uri->segment(2);
        $siteId = ee()->config->item('site_id');

        if ($seg1) {
            $group = ee('Model')->get('TemplateGroup')
                ->filter('group_name', $seg1)
                ->filter('site_id', $siteId)
                ->first();

            if ($group) {
                $templateName = $seg2 ?: 'index';
                $template = ee('Model')->get('Template')
                    ->filter('group_id', $group->group_id)
                    ->filter('template_name', $templateName)
                    ->first();

                if ($template) {
                    return $seg1 . '/' . $templateName;
                }
            }
        }

        // Method 7: Fall back to default template group
        if (!$seg1) {
            $defaultGroup = ee('Model')->get('TemplateGroup')
                ->filter('site_id', $siteId)
                ->filter('is_site_default', 'y')
                ->first();

            if ($defaultGroup) {
                return $defaultGroup->group_name . '/index';
            }
        }

        // Method 8: If we detected a layout and nothing else worked, it means
        // the tag is in the layout template
        $layoutTemplate = $this->detectLayoutTemplate();
        if ($layoutTemplate) {
            return $layoutTemplate;
        }

        return null;
    }

    /**
     * Check if a name contains an EE template tag (e.g. {exp:...}, {if ...}, etc.)
     */
    protected function containsTag(string $name): bool
    {
        return (bool) preg_match('/\{[a-zA-Z]/', $name);
    }

    /**
     * Check if a template appears to be a layout template
     */
    protected function isLayoutTemplate(string $group, string $name): bool
    {
        // Check if the group name suggests it's a layout
        $layoutPatterns = ['layout', 'layouts', '_layout', '_layouts'];

        foreach ($layoutPatterns as $pattern) {
            if (stripos($group, $pattern) !== false) {
                return true;
            }
            if ($name && stripos($name, $pattern) !== false) {
                return true;
            }
        }

        // Check if the template contains {layout:contents} tag (it's a layout template)
        if ($group && $name) {
            $template = ee('Model')->get('Template')
                ->with('TemplateGroup')
                ->filter('template_name', $name)
                ->filter('TemplateGroup.group_name', $group)
                ->filter('TemplateGroup.site_id', ee()->config->item('site_id'))
                ->first();

            if ($template) {
                $content = $template->template_data ?? '';
                if (strpos($content, '{layout:contents}') !== false) {
                    return true;
                }

                // Also check the file if templates are saved as files
                $filePath = $template->getFilePath();
                if ($filePath && file_exists($filePath)) {
                    $fileContent = file_get_contents($filePath);
                    if (strpos($fileContent, '{layout:contents}') !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
