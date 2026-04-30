<?php

namespace CreativeArc\ArcHaystack\Tags;

use ExpressionEngine\Service\Addon\Controllers\Tag\AbstractRoute;

class Templates extends AbstractRoute
{
    /**
     * {exp:arc_haystack:templates}
     *     {template_group}/{template_name} ({template_type})<br>
     * {/exp:arc_haystack:templates}
     *
     * Single tag usage:
     * {exp:arc_haystack:templates format="list"}
     *
     * Parameters:
     * - format: list|json|comma (for single tag output)
     * - include: templates|partials|variables|all (default: all)
     *
     * Available variables in tag pair:
     * - {template_group}
     * - {template_name}
     * - {template_type} (main, embed, layout, partial, variable, called_from)
     * - {template_path} (group/name)
     * - {called_from} (the template where this tag was placed)
     * - {count}
     * - {total_results}
     */
    public function process()
    {
        $include = ee()->TMPL->fetch_param('include', 'all');
        $templates = $this->getUsedTemplates($include);
        $format = ee()->TMPL->fetch_param('format', '');
        $tagdata = ee()->TMPL->tagdata;

        // Detect which template the tag was called from
        $calledFrom = $this->detectCalledFromTemplate();

        // If no tag pair content, return simple list
        if (empty(trim($tagdata))) {
            return $this->formatSimpleOutput($templates, $format, $calledFrom);
        }

        // Process as tag pair
        $output = '';
        $count = 0;
        $total = count($templates);

        foreach ($templates as $template) {
            $count++;
            $vars = [
                'template_group' => $template['group'],
                'template_name'  => $template['name'],
                'template_type'  => $template['type'],
                'template_path'  => $template['group'] . '/' . $template['name'],
                'called_from'    => $calledFrom ?? '',
                'count'          => $count,
                'total_results'  => $total,
            ];

            $output .= ee()->TMPL->parse_variables_row($tagdata, $vars);
        }

        return $output;
    }

    /**
     * Detect which template the tag was called from
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

        // Method 3: Check templates_sofar for the last template (likely the current one)
        if (!empty(ee()->TMPL->templates_sofar) && is_array(ee()->TMPL->templates_sofar)) {
            $templatesSofar = ee()->TMPL->templates_sofar;
            $lastTemplate = end($templatesSofar);
            if (is_string($lastTemplate) && strpos($lastTemplate, '/') !== false) {
                return $lastTemplate;
            }
        }

        // Method 4: Parse template log for the last template reference
        if (!empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            $lastTemplateFound = null;
            foreach (ee()->TMPL->log as $logEntry) {
                if (is_string($logEntry)) {
                    if (preg_match('/Parsing Template[:\s]+([^\/\s]+)\/([^\s\(\)]+)/i', $logEntry, $matches)) {
                        $lastTemplateFound = $matches[1] . '/' . $matches[2];
                    }
                }
            }
            if ($lastTemplateFound) {
                return $lastTemplateFound;
            }
        }

        return null;
    }

    /**
     * Gather all templates used in the current page render
     */
    protected function getUsedTemplates(string $include = 'all'): array
    {
        $templates = [];
        $seen = [];

        $includeTemplates = ($include === 'all' || $include === 'templates');
        $includePartials = ($include === 'all' || $include === 'partials');
        $includeVariables = ($include === 'all' || $include === 'variables');

        if ($includeTemplates) {
            $this->gatherTemplates($templates, $seen);
        }

        if ($includePartials) {
            $this->gatherPartials($templates, $seen);
        }

        if ($includeVariables) {
            $this->gatherVariables($templates, $seen);
        }

        return $templates;
    }

    /**
     * Gather template usage (main, embed, layout)
     */
    protected function gatherTemplates(array &$templates, array &$seen): void
    {
        // Detect if we're inside a layout and get the original content template
        $originalTemplate = $this->detectOriginalTemplate();
        $layoutPaths = $this->detectLayoutTemplates($originalTemplate);

        // Check if the current template appears to be a layout
        $currentGroup = ee()->TMPL->group_name ?? '';
        $currentName = ee()->TMPL->template_name ?? '';
        $isInLayoutTemplate = $this->isLayoutTemplate($currentGroup, $currentName);

        // If we detected an original template, use that as main
        if ($originalTemplate) {
            $key = 'template:' . $originalTemplate['group'] . '/' . $originalTemplate['name'];
            if (!isset($seen[$key])) {
                $templates[] = [
                    'group' => $originalTemplate['group'],
                    'name'  => $originalTemplate['name'],
                    'type'  => 'main',
                ];
                $seen[$key] = true;
            }
        } elseif (!$isInLayoutTemplate && $currentGroup && $currentName) {
            // Only use current template as main if it's not a layout template
            $key = 'template:' . $currentGroup . '/' . $currentName;
            if (!isset($seen[$key])) {
                $templates[] = [
                    'group' => $currentGroup,
                    'name'  => $currentName,
                    'type'  => 'main',
                ];
                $seen[$key] = true;
            }
        }

        // Add detected layout templates (supports nested layouts)
        foreach ($layoutPaths as $layoutPath) {
            $layoutParts = explode('/', $layoutPath, 2);
            $layoutGroup = $layoutParts[0] ?? '';
            $layoutName = $layoutParts[1] ?? '';

            $key = 'template:' . $layoutGroup . '/' . $layoutName;
            if (!isset($seen[$key]) && $layoutGroup && $layoutName) {
                $templates[] = [
                    'group' => $layoutGroup,
                    'name'  => $layoutName,
                    'type'  => 'layout',
                ];
                $seen[$key] = true;
            }
        }

        if (empty($layoutPaths) && $isInLayoutTemplate && $currentGroup && $currentName) {
            // We're inside a layout template but didn't detect it via other methods
            // Add the current template as the layout
            $key = 'template:' . $currentGroup . '/' . $currentName;
            if (!isset($seen[$key])) {
                $templates[] = [
                    'group' => $currentGroup,
                    'name'  => $currentName,
                    'type'  => 'layout',
                ];
                $seen[$key] = true;
            }
        }

        // Parse the template log for embeds
        $this->gatherEmbedsFromLog($templates, $seen);

        // Also scan the layout_conditionals and embed_vars for additional embeds
        $this->gatherEmbedsFromEmbedVars($templates, $seen);

        // Check templates_sofar property for processed templates
        $this->gatherFromTemplatesSofar($templates, $seen);

        // Scan raw template content for embed tags (important when called early in processing)
        $this->gatherEmbedsFromRawContent($templates, $seen, $currentGroup, $currentName);

        // Build a list of all templates that need to be scanned for embeds
        $templatesToScan = [];

        // 1. Always include the current template
        if ($currentGroup && $currentName) {
            $templatesToScan[$currentGroup . '/' . $currentName] = [
                'group' => $currentGroup,
                'name' => $currentName,
            ];
        }

        // 2. Include the original content template (if detected)
        if ($originalTemplate) {
            $key = $originalTemplate['group'] . '/' . $originalTemplate['name'];
            $templatesToScan[$key] = $originalTemplate;
        }

        // 3. Include detected layout templates
        foreach ($layoutPaths as $layoutPath) {
            $layoutParts = explode('/', $layoutPath, 2);
            $layoutGroup = $layoutParts[0] ?? '';
            $layoutName = $layoutParts[1] ?? '';
            if ($layoutGroup && $layoutName) {
                $templatesToScan[$layoutGroup . '/' . $layoutName] = [
                    'group' => $layoutGroup,
                    'name' => $layoutName,
                ];
            }
        }

        // 4. If we're in a layout, aggressively try to find the content template
        if ($isInLayoutTemplate) {
            // Try multiple methods to find the content template
            $contentTemplate = $originalTemplate ?: $this->detectOriginalTemplate();

            // If still not found, try to find it from ee()->TMPL properties
            if (!$contentTemplate) {
                $contentTemplate = $this->findContentTemplateFromTmpl();
            }

            if ($contentTemplate) {
                $key = $contentTemplate['group'] . '/' . $contentTemplate['name'];
                if (!isset($templatesToScan[$key])) {
                    $templatesToScan[$key] = $contentTemplate;
                }
            }

            // Also scan ee()->TMPL->template for embeds directly
            // This contains the merged layout+content template at this point
            if (!empty(ee()->TMPL->template)) {
                $this->extractEmbedsFromContent(ee()->TMPL->template, $templates, $seen);
            }
        }

        // Scan all collected templates for embeds
        foreach ($templatesToScan as $templateInfo) {
            $this->gatherEmbedsFromTemplateFile($templates, $seen, $templateInfo['group'], $templateInfo['name']);
        }

        // Check debug mode for additional templates
        if (ee()->config->item('show_profiler') === 'y' || ee()->config->item('template_debugging') === 'y') {
            $this->parseDebugTemplates($templates, $seen);
        }
    }

    /**
     * Try to find the content template from ee()->TMPL properties
     */
    protected function findContentTemplateFromTmpl(): ?array
    {
        // Check various TMPL properties that might contain content template info

        // Method 1: Check template_id and look it up
        if (!empty(ee()->TMPL->template_id)) {
            $template = ee('Model')->get('Template', ee()->TMPL->template_id)
                ->with('TemplateGroup')
                ->first();

            if ($template && $template->TemplateGroup) {
                $groupName = $template->TemplateGroup->group_name;
                // Only return if it's not a layout template
                if (stripos($groupName, 'layout') === false) {
                    return [
                        'group' => $groupName,
                        'name' => $template->template_name,
                    ];
                }
            }
        }

        // Method 2: Check if there's a primary template stored
        if (!empty(ee()->TMPL->primary_template)) {
            if (strpos(ee()->TMPL->primary_template, '/') !== false) {
                $parts = explode('/', ee()->TMPL->primary_template);
                if (stripos($parts[0], 'layout') === false) {
                    return [
                        'group' => $parts[0],
                        'name' => $parts[1] ?? 'index',
                    ];
                }
            }
        }

        // Method 3: Scan the current template content for {layout=} tag
        // If found, the template BEFORE this was the content template
        if (!empty(ee()->TMPL->template)) {
            // Look for layout tag which tells us the original template used this layout
            if (preg_match('/\{layout=["\']([^"\']+)["\']/i', ee()->TMPL->template, $matches)) {
                // The current template content HAS a layout tag, meaning it's the content template
                // being processed - but we're in the layout, so this shouldn't happen
            }
        }

        // Method 4: Check the URL and try to resolve it
        $uri = trim(ee()->uri->uri_string(), '/');

        if (empty($uri)) {
            // Homepage - find default template group
            $defaultGroup = ee('Model')->get('TemplateGroup')
                ->filter('site_id', ee()->config->item('site_id'))
                ->filter('is_site_default', 'y')
                ->first();

            if ($defaultGroup) {
                return [
                    'group' => $defaultGroup->group_name,
                    'name' => 'index',
                ];
            }
        } else {
            // Parse URI to find template
            $segments = explode('/', $uri);
            $potentialGroup = $segments[0];

            $group = ee('Model')->get('TemplateGroup')
                ->filter('group_name', $potentialGroup)
                ->filter('site_id', ee()->config->item('site_id'))
                ->first();

            if ($group && stripos($group->group_name, 'layout') === false) {
                $templateName = $segments[1] ?? 'index';

                // First try exact template name
                $template = ee('Model')->get('Template')
                    ->filter('group_id', $group->group_id)
                    ->filter('template_name', $templateName)
                    ->first();

                if ($template) {
                    return [
                        'group' => $potentialGroup,
                        'name' => $templateName,
                    ];
                }

                // Fall back to index
                $indexTemplate = ee('Model')->get('Template')
                    ->filter('group_id', $group->group_id)
                    ->filter('template_name', 'index')
                    ->first();

                if ($indexTemplate) {
                    return [
                        'group' => $potentialGroup,
                        'name' => 'index',
                    ];
                }
            }

            // URI doesn't match a template group - check for Pages module
            $sitePages = ee()->config->item('site_pages');
            $siteId = ee()->config->item('site_id');

            if (!empty($sitePages[$siteId]['uris'])) {
                $currentUri = '/' . $uri;

                foreach ($sitePages[$siteId]['uris'] as $entryId => $pageUri) {
                    if ($pageUri === $currentUri || rtrim($pageUri, '/') === $currentUri) {
                        $templateId = $sitePages[$siteId]['templates'][$entryId] ?? null;
                        if ($templateId) {
                            $template = ee('Model')->get('Template', $templateId)
                                ->with('TemplateGroup')
                                ->first();

                            if ($template && $template->TemplateGroup) {
                                if (stripos($template->TemplateGroup->group_name, 'layout') === false) {
                                    return [
                                        'group' => $template->TemplateGroup->group_name,
                                        'name' => $template->template_name,
                                    ];
                                }
                            }
                        }
                        break;
                    }
                }
            }

            // Final fallback - use default group with 'pages' template if it exists
            $defaultGroup = ee('Model')->get('TemplateGroup')
                ->filter('site_id', ee()->config->item('site_id'))
                ->filter('is_site_default', 'y')
                ->first();

            if ($defaultGroup) {
                $pagesTemplate = ee('Model')->get('Template')
                    ->filter('group_id', $defaultGroup->group_id)
                    ->filter('template_name', 'pages')
                    ->first();

                if ($pagesTemplate) {
                    return [
                        'group' => $defaultGroup->group_name,
                        'name' => 'pages',
                    ];
                }

                return [
                    'group' => $defaultGroup->group_name,
                    'name' => 'index',
                ];
            }
        }

        return null;
    }

    /**
     * Scan raw template content for embed tags
     */
    protected function gatherEmbedsFromRawContent(array &$templates, array &$seen, string $currentGroup, string $currentName): void
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
                $this->extractEmbedsFromContent($content, $templates, $seen);
            }
        }
    }

    /**
     * Scan a specific template file for embed tags
     */
    protected function gatherEmbedsFromTemplateFile(array &$templates, array &$seen, string $group, string $name): void
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
            $this->extractEmbedsFromContent($content, $templates, $seen);
        }
    }

    /**
     * Extract embed references from template content
     */
    protected function extractEmbedsFromContent(string $content, array &$templates, array &$seen): void
    {
        if (empty($content)) {
            return;
        }

        // Multiple patterns to catch various embed syntaxes
        // Using more permissive character class [^\s"'\}]+ to match any valid template path
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

                        if ($embedGroup && $embedName) {
                            $key = 'template:' . $embedGroup . '/' . $embedName;
                            if (!isset($seen[$key])) {
                                $templates[] = [
                                    'group' => $embedGroup,
                                    'name'  => $embedName,
                                    'type'  => 'embed',
                                ];
                                $seen[$key] = true;

                                // Recursively scan the embed template for more embeds
                                $this->gatherEmbedsFromTemplateFile($templates, $seen, $embedGroup, $embedName);
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Detect the original content template when we're inside a layout
     */
    protected function detectOriginalTemplate(): ?array
    {
        // Method 1: Check ee()->TMPL->templates_sofar for the first non-layout template
        if (!empty(ee()->TMPL->templates_sofar) && is_array(ee()->TMPL->templates_sofar)) {
            foreach (ee()->TMPL->templates_sofar as $templatePath) {
                if (is_string($templatePath) && strpos($templatePath, '/') !== false) {
                    $parts = explode('/', $templatePath);
                    if (count($parts) >= 2) {
                        // Skip if this looks like a layout template
                        if (stripos($parts[0], 'layout') === false) {
                            return [
                                'group' => $parts[0],
                                'name'  => $parts[1],
                            ];
                        }
                    }
                }
            }
        }

        // Method 2: Check the template log for the first non-layout template loaded
        if (!empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            foreach (ee()->TMPL->log as $logEntry) {
                if (is_string($logEntry)) {
                    // Match "Parsing Template: group/template" - this is typically the first entry
                    if (preg_match('/Parsing Template[:\s]+([^\/\s]+)\/([^\s\(\)]+)/i', $logEntry, $matches)) {
                        // Skip layout templates
                        if (stripos($matches[1], 'layout') === false) {
                            return [
                                'group' => $matches[1],
                                'name'  => $matches[2],
                            ];
                        }
                    }
                    // Match "Template: group/template"
                    if (preg_match('/^Template[:\s]+([^\/\s]+)\/([^\s\(\)]+)/i', $logEntry, $matches)) {
                        if (stripos($matches[1], 'layout') === false) {
                            return [
                                'group' => $matches[1],
                                'name'  => $matches[2],
                            ];
                        }
                    }
                }
            }
        }

        // Method 3: Check for Structure/Pages module entry
        $pageUri = ee()->uri->uri_string();
        if (class_exists('Structure') || ee('Model')->get('Module')->filter('module_name', 'Structure')->count() > 0) {
            // Structure may store page->template mappings
            // Check if there's a pages entry for this URI
        }

        // Method 4: Check Pages module data
        $sitePages = ee()->config->item('site_pages');
        $siteId = ee()->config->item('site_id');
        if (!empty($sitePages[$siteId]['uris']) && !empty($sitePages[$siteId]['templates'])) {
            $currentUri = '/' . trim(ee()->uri->uri_string(), '/');
            if ($currentUri === '/') {
                $currentUri = '/';
            }

            // Find the current page in the pages array
            foreach ($sitePages[$siteId]['uris'] as $entryId => $uri) {
                if ($uri === $currentUri || $uri === $currentUri . '/') {
                    $templateId = $sitePages[$siteId]['templates'][$entryId] ?? null;
                    if ($templateId) {
                        $template = ee('Model')->get('Template', $templateId)->with('TemplateGroup')->first();
                        if ($template && $template->TemplateGroup) {
                            // Skip if this is a layout template
                            if (stripos($template->TemplateGroup->group_name, 'layout') === false) {
                                return [
                                    'group' => $template->TemplateGroup->group_name,
                                    'name'  => $template->template_name,
                                ];
                            }
                        }
                    }
                    break;
                }
            }
        }

        // Method 5: Use URI segments to determine the requested template
        $seg1 = ee()->uri->segment(1);
        $seg2 = ee()->uri->segment(2);

        if ($seg1) {
            // Check if this corresponds to a template group
            $group = ee('Model')->get('TemplateGroup')
                ->filter('group_name', $seg1)
                ->filter('site_id', ee()->config->item('site_id'))
                ->first();

            if ($group && stripos($group->group_name, 'layout') === false) {
                $templateName = $seg2 ?: 'index';
                $template = ee('Model')->get('Template')
                    ->filter('group_id', $group->group_id)
                    ->filter('template_name', $templateName)
                    ->first();

                if ($template) {
                    return [
                        'group' => $seg1,
                        'name'  => $templateName,
                    ];
                }

                // Template name not found, try index
                $indexTemplate = ee('Model')->get('Template')
                    ->filter('group_id', $group->group_id)
                    ->filter('template_name', 'index')
                    ->first();

                if ($indexTemplate) {
                    return [
                        'group' => $seg1,
                        'name'  => 'index',
                    ];
                }
            }
        }

        // Method 6: Get the default template group for homepage
        $defaultTemplateGroup = ee('Model')->get('TemplateGroup')
            ->filter('site_id', ee()->config->item('site_id'))
            ->filter('is_site_default', 'y')
            ->first();

        // If no URI segments or we couldn't find a template, use default group
        if (!$seg1 || (!isset($group) || !$group)) {
            if ($defaultTemplateGroup) {
                return [
                    'group' => $defaultTemplateGroup->group_name,
                    'name'  => 'index',
                ];
            }
        }

        // Method 7: Check for template_route info
        if (!empty(ee()->TMPL->template_route)) {
            if (is_array(ee()->TMPL->template_route)) {
                $group = ee()->TMPL->template_route['group'] ?? null;
                $name = ee()->TMPL->template_route['template'] ?? null;
                if ($group && $name && stripos($group, 'layout') === false) {
                    return ['group' => $group, 'name' => $name];
                }
            }
        }

        // Method 8: Parse $_SERVER['REQUEST_URI'] to determine the template
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (!empty($requestUri)) {
            // Remove query string and index.php
            $requestUri = strtok($requestUri, '?');
            $requestUri = preg_replace('#/index\.php/?#', '/', $requestUri);
            // Remove leading/trailing slashes
            $requestUri = trim($requestUri, '/');

            if (empty($requestUri)) {
                // Homepage - use default group
                if ($defaultTemplateGroup) {
                    return [
                        'group' => $defaultTemplateGroup->group_name,
                        'name'  => 'index',
                    ];
                }
            } else {
                // Parse URI parts
                $uriParts = explode('/', $requestUri);
                $potentialGroup = $uriParts[0] ?? '';
                $potentialTemplate = $uriParts[1] ?? 'index';

                // Check if it's a valid template group (skip layout groups)
                $group = ee('Model')->get('TemplateGroup')
                    ->filter('group_name', $potentialGroup)
                    ->filter('site_id', ee()->config->item('site_id'))
                    ->first();

                if ($group && stripos($group->group_name, 'layout') === false) {
                    $template = ee('Model')->get('Template')
                        ->filter('group_id', $group->group_id)
                        ->filter('template_name', $potentialTemplate)
                        ->first();

                    if ($template) {
                        return [
                            'group' => $potentialGroup,
                            'name'  => $potentialTemplate,
                        ];
                    }

                    // Try index template
                    $indexTemplate = ee('Model')->get('Template')
                        ->filter('group_id', $group->group_id)
                        ->filter('template_name', 'index')
                        ->first();

                    if ($indexTemplate) {
                        return [
                            'group' => $potentialGroup,
                            'name'  => 'index',
                        ];
                    }
                }

                // URI doesn't match a template group - might be a Pages/Structure URL
                // Fall back to default group with 'pages' template if it exists
                if ($defaultTemplateGroup) {
                    $pagesTemplate = ee('Model')->get('Template')
                        ->filter('group_id', $defaultTemplateGroup->group_id)
                        ->filter('template_name', 'pages')
                        ->first();

                    if ($pagesTemplate) {
                        return [
                            'group' => $defaultTemplateGroup->group_name,
                            'name'  => 'pages',
                        ];
                    }

                    // Fall back to index
                    return [
                        'group' => $defaultTemplateGroup->group_name,
                        'name'  => 'index',
                    ];
                }
            }
        }

        // Final fallback: return default template group index
        if ($defaultTemplateGroup) {
            return [
                'group' => $defaultTemplateGroup->group_name,
                'name'  => 'index',
            ];
        }

        return null;
    }

    /**
     * Gather embeds from the template log
     */
    protected function gatherEmbedsFromLog(array &$templates, array &$seen): void
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
                        $key = 'template:' . $embedGroup . '/' . $embedName;

                        if (!isset($seen[$key])) {
                            $templates[] = [
                                'group' => $embedGroup,
                                'name'  => $embedName,
                                'type'  => 'embed',
                            ];
                            $seen[$key] = true;
                        }
                    }
                }
            }
        }
    }

    /**
     * Gather embeds from embed_vars property
     */
    protected function gatherEmbedsFromEmbedVars(array &$templates, array &$seen): void
    {
        // Check ee()->TMPL->embed_vars for embed tracking
        if (!empty(ee()->TMPL->embed_vars) && is_array(ee()->TMPL->embed_vars)) {
            foreach (ee()->TMPL->embed_vars as $embedPath => $vars) {
                if (is_string($embedPath) && strpos($embedPath, '/') !== false) {
                    $parts = explode('/', $embedPath);
                    $embedGroup = $parts[0];
                    $embedName = $parts[1] ?? '';

                    if ($embedGroup && $embedName) {
                        $key = 'template:' . $embedGroup . '/' . $embedName;
                        if (!isset($seen[$key])) {
                            $templates[] = [
                                'group' => $embedGroup,
                                'name'  => $embedName,
                                'type'  => 'embed',
                            ];
                            $seen[$key] = true;
                        }
                    }
                }
            }
        }

        // Check for embeds stored in layout processing
        if (!empty(ee()->TMPL->layout_conditionals) && is_array(ee()->TMPL->layout_conditionals)) {
            foreach (ee()->TMPL->layout_conditionals as $key => $value) {
                if (preg_match('/embed:([^\/]+)\/(.+)/i', $key, $matches)) {
                    $embedGroup = $matches[1];
                    $embedName = $matches[2];
                    $key = 'template:' . $embedGroup . '/' . $embedName;

                    if (!isset($seen[$key])) {
                        $templates[] = [
                            'group' => $embedGroup,
                            'name'  => $embedName,
                            'type'  => 'embed',
                        ];
                        $seen[$key] = true;
                    }
                }
            }
        }
    }

    /**
     * Gather templates from templates_sofar property
     */
    protected function gatherFromTemplatesSofar(array &$templates, array &$seen): void
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

            $key = 'template:' . $group . '/' . $name;
            if (isset($seen[$key])) {
                $isFirst = false;
                continue;
            }

            // First template in the list is the main template (skip if we already have it)
            // Subsequent ones are embeds
            $type = $isFirst ? 'main' : 'embed';
            $isFirst = false;

            $templates[] = [
                'group' => $group,
                'name'  => $name,
                'type'  => $type,
            ];
            $seen[$key] = true;
        }
    }

    /**
     * Gather template partials (snippets) usage
     */
    protected function gatherPartials(array &$templates, array &$seen): void
    {
        // Get all partials from the database
        $partials = ee('Model')->get('Snippet')
            ->filter('site_id', 'IN', [ee()->config->item('site_id'), 0])
            ->all();

        if (!$partials) {
            return;
        }

        // Check template log for partial references
        $usedPartials = $this->getUsedPartialsFromLog();

        // Also check ee()->config->_global_vars for loaded partials
        $globalVars = ee()->config->_global_vars ?? [];

        foreach ($partials as $partial) {
            $name = $partial->snippet_name;
            $key = 'partial:' . $name;

            // Check if this partial was actually used in the current page render
            // Note: We can't rely on isset($globalVars[$name]) because EE preloads ALL partials
            // into _global_vars at startup, regardless of whether they're used on this page
            $wasUsed = isset($usedPartials[$name]) || $this->wasPartialUsedInTemplates($name);

            if ($wasUsed && !isset($seen[$key])) {
                $templates[] = [
                    'group' => '_partials',
                    'name'  => $name,
                    'type'  => 'partial',
                ];
                $seen[$key] = true;
            }
        }
    }

    /**
     * Gather template variables (global variables) usage
     */
    protected function gatherVariables(array &$templates, array &$seen): void
    {
        // Check which variables were actually used
        $usedVars = $this->getUsedVariablesFromLog();

        // Also check ee()->config->_global_vars
        $globalVars = ee()->config->_global_vars ?? [];

        // Get all global variables from the database
        $variables = ee('Model')->get('GlobalVariable')
            ->filter('site_id', 'IN', [ee()->config->item('site_id'), 0])
            ->all();

        if ($variables) {
            foreach ($variables as $variable) {
                $name = $variable->variable_name;
                $key = 'variable:' . $name;

                // Check if this variable was actually used in the current page render
                // Note: We can't rely on isset($globalVars[$name]) because EE preloads ALL variables
                // into _global_vars at startup, regardless of whether they're used on this page
                $wasUsed = isset($usedVars[$name]) || $this->wasVariableUsedInTemplates($name);

                if ($wasUsed && !isset($seen[$key])) {
                    $templates[] = [
                        'group' => '_variables',
                        'name'  => $name,
                        'type'  => 'variable',
                    ];
                    $seen[$key] = true;
                }
            }
        }

        // Also check for file-based template variables in _variables folder
        $this->gatherFileBasedVariables($templates, $seen);
    }

    /**
     * Gather file-based template variables from _variables folder
     */
    protected function gatherFileBasedVariables(array &$templates, array &$seen): void
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
            $key = 'variable:' . $name;

            // Skip if already seen (from database variables)
            if (isset($seen[$key])) {
                continue;
            }

            // Only include file-based variables that were actually used in the current page render
            if ($this->wasVariableUsedInTemplates($name)) {
                $templates[] = [
                    'group' => '_variables',
                    'name'  => $name,
                    'type'  => 'variable',
                ];
                $seen[$key] = true;
            }
        }
    }

    /**
     * Get the template base path with fallbacks
     */
    protected function getTemplateBasePath(): ?string
    {
        // Try config item first
        $basePath = ee()->config->item('tmpl_file_basepath');
        if ($basePath && is_dir($basePath)) {
            return $basePath;
        }

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
     * Parse template log for used partials
     */
    protected function getUsedPartialsFromLog(): array
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
                    // Match partial assignments
                    if (preg_match('/Partials/i', $logEntry)) {
                        // Mark that partials were processed
                    }
                }
            }
        }

        return $used;
    }

    /**
     * Parse template log for used variables
     */
    protected function getUsedVariablesFromLog(): array
    {
        $used = [];

        if (!empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            foreach (ee()->TMPL->log as $logEntry) {
                if (is_string($logEntry)) {
                    // Match variable references in log
                    if (preg_match('/Variable[:\s]+([^\s\)]+)/i', $logEntry, $matches)) {
                        $used[$matches[1]] = true;
                    }
                    // Match global variable assignments
                    if (preg_match('/Config Assignments/i', $logEntry)) {
                        // Mark that config/global vars were processed
                    }
                }
            }
        }

        return $used;
    }

    /**
     * Check if a partial was actually used in the rendered templates
     * by scanning template content for the partial tag
     */
    protected function wasPartialUsedInTemplates(string $name): bool
    {
        // Check if the partial name appears in the template log
        if (!empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            $logText = implode(' ', array_filter(ee()->TMPL->log, 'is_string'));
            if (stripos($logText, $name) !== false) {
                return true;
            }
        }

        // Check if the partial tag {partial_name} appears in any template content
        $contentSources = $this->getTemplateContentSources();
        foreach ($contentSources as $content) {
            // Look for the partial tag pattern: {partial_name} or {partial_name ...}
            if (preg_match('/\{' . preg_quote($name, '/') . '(?:\s|\})/i', $content)) {
                return true;
            }
        }

        // Also scan raw template files from disk — by the time {exp:arc_haystack:templates}
        // runs, EE has already replaced partial tags with their content, so runtime
        // TMPL properties no longer contain the original {partial_name} tags.
        return $this->wasPartialUsedInRawTemplateFiles($name);
    }

    /**
     * Check if a partial tag appears in the raw (unprocessed) template files on disk.
     * This catches partials used in layout templates, which are fully processed before
     * the tag runs.
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

        $templatesToScan = [];

        $currentGroup = ee()->TMPL->group_name ?? '';
        $currentName  = ee()->TMPL->template_name ?? '';
        if ($currentGroup && $currentName) {
            $templatesToScan[] = [$currentGroup, $currentName];
        }

        $originalTemplate = $this->detectOriginalTemplate();
        if ($originalTemplate && !empty($originalTemplate['group']) && !empty($originalTemplate['name'])) {
            $templatesToScan[] = [$originalTemplate['group'], $originalTemplate['name']];
        }

        foreach ($this->detectLayoutTemplates($originalTemplate) as $layoutTemplate) {
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

    /**
     * Check if a variable was actually used in the rendered templates
     * by scanning template content for the variable tag
     */
    protected function wasVariableUsedInTemplates(string $name): bool
    {
        // Check if the variable name appears in the template log
        if (!empty(ee()->TMPL->log) && is_array(ee()->TMPL->log)) {
            $logText = implode(' ', array_filter(ee()->TMPL->log, 'is_string'));
            if (stripos($logText, $name) !== false) {
                return true;
            }
        }

        // Check if the variable tag {variable_name} appears in any template content
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
     * Parse additional template info when debugging is enabled
     */
    protected function parseDebugTemplates(array &$templates, array &$seen): void
    {
        // Check if there's a templates_loaded tracker
        if (isset(ee()->TMPL->templates_loaded) && is_array(ee()->TMPL->templates_loaded)) {
            foreach (ee()->TMPL->templates_loaded as $loaded) {
                if (is_array($loaded) && isset($loaded['group'], $loaded['name'])) {
                    $key = 'template:' . $loaded['group'] . '/' . $loaded['name'];
                    if (!isset($seen[$key])) {
                        $templates[] = [
                            'group' => $loaded['group'],
                            'name'  => $loaded['name'],
                            'type'  => $loaded['type'] ?? 'unknown',
                        ];
                        $seen[$key] = true;
                    }
                }
            }
        }
    }

    /**
     * Check if a template appears to be a layout template
     */
    protected function isLayoutTemplate(string $group, string $name): bool
    {
        // Check if the group name suggests it's a layout
        $layoutGroupPatterns = [
            'layout',
            'layouts',
            '_layout',
            '_layouts',
        ];

        foreach ($layoutGroupPatterns as $pattern) {
            if (stripos($group, $pattern) !== false) {
                return true;
            }
        }

        // Check if the template name suggests it's a layout
        $layoutNamePatterns = [
            'layout',
            '_layout',
        ];

        foreach ($layoutNamePatterns as $pattern) {
            if (stripos($name, $pattern) !== false) {
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

    /**
     * Detect layout template from multiple EE sources
     */
    protected function detectLayoutTemplate(): ?string
    {
        $layouts = $this->detectLayoutTemplates();
        return $layouts[0] ?? null;
    }

    protected function detectLayoutTemplates(?array $originalTemplate = null): array
    {
        $layouts = [];

        if ($originalTemplate && ! empty($originalTemplate['group']) && ! empty($originalTemplate['name'])) {
            $layouts = $this->getNestedLayoutTemplates($originalTemplate['group'] . '/' . $originalTemplate['name']);
        } else {
            $currentGroup = ee()->TMPL->group_name ?? '';
            $currentName = ee()->TMPL->template_name ?? '';
            if ($currentGroup && $currentName && ! $this->isLayoutTemplate($currentGroup, $currentName)) {
                $layouts = $this->getNestedLayoutTemplates($currentGroup . '/' . $currentName);
            }
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

            [$group, $name] = explode('/', $current, 2);
            $next = $this->findLayoutInTemplateFile($group, $name);
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

    protected function findLayoutInTemplateFile(string $groupName, string $templateName): ?string
    {
        $template = ee('Model')->get('Template')
            ->with('TemplateGroup')
            ->filter('template_name', $templateName)
            ->filter('TemplateGroup.group_name', $groupName)
            ->filter('TemplateGroup.site_id', ee()->config->item('site_id'))
            ->first();

        if (! $template) {
            return null;
        }

        $rawContent = $template->template_data ?? '';
        if (! empty($rawContent) && preg_match('/\{layout=["\']([^"\']+)["\']/i', $rawContent, $matches)) {
            return $this->normalizeTemplatePath($matches[1]);
        }

        $filePath = $template->getFilePath();
        if ($filePath && file_exists($filePath)) {
            $fileContent = file_get_contents($filePath);
            if (! empty($fileContent) && preg_match('/\{layout=["\']([^"\']+)["\']/i', $fileContent, $matches)) {
                return $this->normalizeTemplatePath($matches[1]);
            }
        }

        return null;
    }

    protected function detectSingleLayoutFromRuntime(): ?string
    {
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

        $templateData = ee()->TMPL->template ?? '';
        if (! empty($templateData) && preg_match('/\{layout=["\']([^"\']+)["\']/i', $templateData, $matches)) {
            return $this->normalizeTemplatePath($matches[1]);
        }

        $groupName = ee()->TMPL->group_name ?? '';
        $templateName = ee()->TMPL->template_name ?? '';
        if ($groupName && $templateName) {
            $layoutPath = $this->findLayoutInTemplateFile($groupName, $templateName);
            if ($layoutPath) {
                return $layoutPath;
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
                    return $layoutPath;
                }
            }
        }

        return null;
    }

    /**
     * Format output for simple/single tag usage
     */
    protected function formatSimpleOutput(array $templates, string $format, ?string $calledFrom = null): string
    {
        if (empty($templates) && empty($calledFrom)) {
            return '';
        }

        $lines = [];

        // Add "called from" indicator first
        if ($calledFrom) {
            $lines[] = $calledFrom . ' (called_from)';
        }

        foreach ($templates as $template) {
            $lines[] = $template['group'] . '/' . $template['name'] . ' (' . $template['type'] . ')';
        }

        switch ($format) {
            case 'list':
                return '<ul><li>' . implode('</li><li>', $lines) . '</li></ul>';
            case 'json':
                $output = [
                    'called_from' => $calledFrom,
                    'templates' => $templates,
                ];
                return json_encode($output);
            case 'comma':
                $paths = array_map(fn($t) => $t['group'] . '/' . $t['name'], $templates);
                if ($calledFrom) {
                    array_unshift($paths, $calledFrom . ' (called_from)');
                }
                return implode(', ', $paths);
            default:
                return implode("\n", $lines);
        }
    }
}
