<?php

namespace CreativeArc\ArcHaystack\Tags;

use CreativeArc\ArcHaystack\Extensions\RequestState;
use CreativeArc\ArcHaystack\Settings;
use ExpressionEngine\Service\Addon\Controllers\Tag\AbstractRoute;

class Log extends AbstractRoute
{
    public function process()
    {
        $settings = Settings::get();
        if (($settings['logging_enabled'] ?? 'y') === 'n') {
            return '';
        }

        $layoutTemplates = $this->detectLayoutTemplates();
        $primaryLayout = $layoutTemplates[0] ?? null;

        $embeds    = $this->getUsedEmbeds();
        $partials  = $this->getUsedPartials();
        $variables = $this->getUsedVariables();

        ee()->db->insert('arc_haystack_logs', [
            'template_path'   => $this->getCurrentTemplatePath(),
            'main_template'   => $this->detectMainTemplate(),
            'layout_template' => $primaryLayout,
            'called_from'     => $this->detectCalledFromTemplate(),
            'page_url'        => $this->getCurrentUrl(),
            'embeds_used'     => ! empty($embeds)    ? json_encode($embeds)    : null,
            'partials_used'   => ! empty($partials)  ? json_encode($partials)  : null,
            'variables_used'  => ! empty($variables) ? json_encode($variables) : null,
            'logged_at'       => ee()->localize->now,
        ]);

        RequestState::$tagDidLog = true;

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

    protected function getUsedEmbeds(): array
    {
        $embeds = [];
        $seen   = [];

        $currentGroup = ee()->TMPL->group_name ?? '';
        $currentName  = ee()->TMPL->template_name ?? '';

        $this->gatherEmbedsFromLog($embeds, $seen);
        $this->gatherEmbedsFromEmbedVars($embeds, $seen);
        $this->gatherFromTemplatesSofar($embeds, $seen);
        $this->gatherEmbedsFromRawContent($embeds, $seen);

        $templatesToScan = [];

        if ($currentGroup && $currentName) {
            $templatesToScan[$currentGroup . '/' . $currentName] = [
                'group' => $currentGroup,
                'name'  => $currentName,
            ];
        }

        $mainTemplate = $this->detectMainTemplate();
        if ($mainTemplate && ! isset($templatesToScan[$mainTemplate])) {
            $parts = explode('/', $mainTemplate);
            if (count($parts) >= 2) {
                $templatesToScan[$mainTemplate] = ['group' => $parts[0], 'name' => $parts[1]];
            }
        }

        $layoutTemplates = $this->detectLayoutTemplates();
        foreach ($layoutTemplates as $layoutTemplate) {
            if (isset($templatesToScan[$layoutTemplate])) {
                continue;
            }

            $parts = explode('/', $layoutTemplate, 2);
            if (count($parts) >= 2) {
                $templatesToScan[$layoutTemplate] = ['group' => $parts[0], 'name' => $parts[1]];
            }
        }

        foreach ($templatesToScan as $templateInfo) {
            $this->gatherEmbedsFromTemplateFile($embeds, $seen, $templateInfo['group'], $templateInfo['name']);
        }

        return array_values(array_filter($embeds, function ($name) {
            return ! $this->containsTag($name);
        }));
    }

    protected function gatherEmbedsFromLog(array &$embeds, array &$seen): void
    {
        if (empty(ee()->TMPL->log) || ! is_array(ee()->TMPL->log)) {
            return;
        }

        foreach (ee()->TMPL->log as $logEntry) {
            if (! is_string($logEntry)) {
                continue;
            }

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

    protected function gatherEmbedsFromEmbedVars(array &$embeds, array &$seen): void
    {
        if (! empty(ee()->TMPL->embed_vars) && is_array(ee()->TMPL->embed_vars)) {
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

    // Scans raw files because layout templates are fully parsed before the log tag runs,
    // so {partial_name} tags no longer appear in TMPL properties at that point.
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

        foreach ($this->detectLayoutTemplates() as $layoutTemplate) {
            if (strpos($layoutTemplate, '/') === false) {
                continue;
            }

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

    protected function detectLayoutTemplate(): ?string
    {
        $layouts = $this->detectLayoutTemplates();
        return $layouts[0] ?? null;
    }

    protected function detectLayoutTemplates(): array
    {
        $layouts = [];

        $mainTemplate = $this->detectMainTemplate();
        if ($mainTemplate) {
            $layouts = $this->getNestedLayoutTemplates($mainTemplate);
        }

        if (empty($layouts)) {
            $runtimeLayout = $this->detectSingleLayoutFromRuntime();
            if ($runtimeLayout) {
                $layouts = $this->getNestedLayoutTemplates($runtimeLayout, true);
                array_unshift($layouts, $runtimeLayout);
            }
        }

        return array_values(array_unique(array_filter($layouts, function ($path) {
            return is_string($path) && strpos($path, '/') !== false;
        })));
    }

    protected function getNestedLayoutTemplates(string $templatePath, bool $followFromSelf = false): array
    {
        $templatePath = $this->normalizeTemplatePath($templatePath);
        if (! $templatePath) {
            return [];
        }

        $chain = [];
        $visited = [];
        $current = $templatePath;

        while ($current && ! isset($visited[$current])) {
            $visited[$current] = true;
            $parts = explode('/', $current, 2);
            if (count($parts) !== 2) {
                break;
            }

            $next = $this->findLayoutInTemplateFile($parts[0], $parts[1]);
            $next = $next ? $this->normalizeTemplatePath($next) : null;

            if (! $next || isset($visited[$next])) {
                break;
            }

            $chain[] = $next;
            $current = $next;
        }

        if ($followFromSelf && ! empty($chain) && $chain[0] === $templatePath) {
            array_shift($chain);
        }

        return $chain;
    }

    protected function normalizeTemplatePath(?string $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (strpos($path, '/') === false) {
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

    protected function detectSingleLayoutFromRuntime(): ?string
    {
        $currentGroup = ee()->TMPL->group_name ?? '';
        $currentName = ee()->TMPL->template_name ?? '';

        $templateContent = ee()->TMPL->template ?? '';
        if (! empty($templateContent) && preg_match('/\{layout=["\']([^"\']+)["\']/i', $templateContent, $matches)) {
            return $this->normalizeTemplatePath($matches[1]);
        }

        if ($currentGroup && $currentName && ! $this->isLayoutTemplateByContent($currentGroup, $currentName)) {
            $layoutPath = $this->findLayoutInTemplateFile($currentGroup, $currentName);
            if ($layoutPath) {
                return $this->normalizeTemplatePath($layoutPath);
            }
        }

        if ($currentGroup && $currentName && $this->isLayoutTemplateByContent($currentGroup, $currentName)) {
            return $this->normalizeTemplatePath($currentGroup . '/' . $currentName);
        }

        if (! empty(ee()->TMPL->layout_name)) {
            return $this->normalizeTemplatePath(ee()->TMPL->layout_name);
        }

        if (! empty(ee()->TMPL->layout)) {
            if (is_string(ee()->TMPL->layout)) {
                return $this->normalizeTemplatePath(ee()->TMPL->layout);
            }
            if (is_array(ee()->TMPL->layout) && ! empty(ee()->TMPL->layout['template'])) {
                return $this->normalizeTemplatePath(ee()->TMPL->layout['template']);
            }
        }

        if (! empty(ee()->TMPL->layout_vars) && is_array(ee()->TMPL->layout_vars) && ! empty(ee()->TMPL->layout_vars['layout:template'])) {
            return $this->normalizeTemplatePath(ee()->TMPL->layout_vars['layout:template']);
        }

        if (! empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            foreach (ee()->TMPL->log as $logEntry) {
                if (! is_string($logEntry)) {
                    continue;
                }

                if (preg_match('/Layout(?:\s+Template)?[:\s]+([^\/\s]+)\/([^\s\)]+)/i', $logEntry, $matches)) {
                    return $this->normalizeTemplatePath($matches[1] . '/' . $matches[2]);
                }
                if (preg_match('/Processing Layout[:\s]+([^\/\s]+)\/([^\s\)]+)/i', $logEntry, $matches)) {
                    return $this->normalizeTemplatePath($matches[1] . '/' . $matches[2]);
                }
                if (preg_match('/\{layout=["\']?([^\/\s"\']+)\/([^\s"\'\}]+)/i', $logEntry, $matches)) {
                    return $this->normalizeTemplatePath($matches[1] . '/' . $matches[2]);
                }
            }
        }

        $mainTemplate = $this->findTemplateFromPagesModule();
        if ($mainTemplate) {
            $parts = explode('/', $mainTemplate, 2);
            if (count($parts) === 2) {
                $layoutPath = $this->findLayoutInTemplateFile($parts[0], $parts[1]);
                if ($layoutPath) {
                    return $this->normalizeTemplatePath($layoutPath);
                }
            }
        }

        if (! empty(ee()->TMPL->templates_sofar) && is_array(ee()->TMPL->templates_sofar)) {
            foreach (ee()->TMPL->templates_sofar as $templatePath) {
                if (! is_string($templatePath) || strpos($templatePath, '/') === false) {
                    continue;
                }

                [$group, $name] = explode('/', $templatePath, 2);
                if ($this->isLayoutTemplate($group, $name)) {
                    continue;
                }

                $layoutPath = $this->findLayoutInTemplateFile($group, $name);
                if ($layoutPath) {
                    return $this->normalizeTemplatePath($layoutPath);
                }
            }
        }

        return null;
    }

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
        $layoutTemplate = $this->detectSingleLayoutFromRuntime();
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
