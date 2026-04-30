<?php

namespace CreativeArc\ArcHaystack\Extensions;

use CreativeArc\ArcHaystack\Settings;
use ExpressionEngine\Service\Addon\Controllers\Extension\AbstractRoute;

class TemplatePostParse extends AbstractRoute
{
    /**
     * Fires after all template parsing is complete. When $isPartial is false
     * we are at the final assembled output, meaning all template_fetch_template
     * calls for this request have already fired.
     *
     * @param string $finalTemplate
     * @param bool $isPartial
     * @param int|string $siteId
     * @param array $currentTemplateInfo
     * @return string
     */
    public function process($finalTemplate, $isPartial, $siteId, $currentTemplateInfo = [])
    {
        $out = ee()->extensions->last_call !== false ? ee()->extensions->last_call : $finalTemplate;

        if ($isPartial || RequestState::$didWrite || empty(RequestState::$fetchedPaths)) {
            return $out;
        }

        if (RequestState::$tagDidLog) {
            return $out;
        }

        $settings = Settings::get();
        if (($settings['logging_enabled'] ?? 'y') === 'n') {
            return $out;
        }

        RequestState::$didWrite = true;

        $paths = array_values(array_unique(RequestState::$fetchedPaths));
        $mainTemplate = array_shift($paths);
        $embeds = $paths;

        $scheme = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $pageUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');

        $allContent = implode("\n", RequestState::$templateContent);

        $snippets = ee()->db->select('snippet_name')
            ->where_in('site_id', [(int) $siteId, 0])
            ->get('snippets')
            ->result_array();

        $partialsUsed = [];
        foreach ($snippets as $snippet) {
            $name = $snippet['snippet_name'];
            if (strpos($allContent, '{' . $name . '}') !== false) {
                $partialsUsed[] = $name;
            }
        }

        $globalVariables = ee()->db->select('variable_name')
            ->where_in('site_id', [(int) $siteId, 0])
            ->get('global_variables')
            ->result_array();

        $variablesUsed = [];
        foreach ($globalVariables as $variable) {
            $name = $variable['variable_name'];
            if (strpos($allContent, '{' . $name . '}') !== false) {
                $variablesUsed[] = $name;
            }
        }

        ee()->db->insert('arc_haystack_logs', [
            'template_path' => $mainTemplate,
            'main_template' => $mainTemplate,
            'page_url' => substr($pageUrl, 0, 2048),
            'embeds_used' => ! empty($embeds) ? json_encode($embeds) : null,
            'partials_used' => ! empty($partialsUsed) ? json_encode($partialsUsed) : null,
            'variables_used' => ! empty($variablesUsed) ? json_encode($variablesUsed) : null,
            'logged_at' => ee()->localize->now,
        ]);

        return $out;
    }
}
