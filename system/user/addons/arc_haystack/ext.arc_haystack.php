<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

use CreativeArc\ArcHaystack\Settings;

class Arc_haystack_ext
{
    public $name    = 'ARC Haystack';
    public $version = '1.6.0';

    // Set to true by the Log tag so the extension skips writing for that request
    public static $tagDidLog = false;

    private static $fetchedPaths = [];
    private static $didWrite     = false;

    /**
     * Fires each time EE loads a template from the database (main template,
     * embeds, and layout templates all trigger this hook separately).
     */
    public function template_fetch_template($row)
    {
        // Front-end page requests only
        if (! defined('REQ') || REQ !== 'PAGE') {
            return ee()->extensions->last_call !== false ? ee()->extensions->last_call : $row;
        }

        $group    = $row['group_name']    ?? '';
        $template = $row['template_name'] ?? '';

        if ($group !== '' && $template !== '') {
            self::$fetchedPaths[] = $group . '/' . $template;
        }

        return ee()->extensions->last_call !== false ? ee()->extensions->last_call : $row;
    }

    /**
     * Fires after all template parsing is complete. When $isPartial is false
     * we are at the final assembled output, meaning all template_fetch_template
     * calls for this request have already fired.
     */
    public function template_post_parse($finalTemplate, $isPartial, $siteId, $currentTemplateInfo = [])
    {
        $out = ee()->extensions->last_call !== false ? ee()->extensions->last_call : $finalTemplate;

        // Only act on the final output, not on each embed/layout pass
        if ($isPartial || self::$didWrite || empty(self::$fetchedPaths)) {
            return $out;
        }

        // Skip if the {exp:arc_haystack:log} tag already wrote a record
        if (self::$tagDidLog) {
            return $out;
        }

        $settings = Settings::get();
        if (($settings['logging_enabled'] ?? 'y') === 'n') {
            return $out;
        }

        self::$didWrite = true;

        $paths        = array_values(array_unique(self::$fetchedPaths));
        $mainTemplate = array_shift($paths);
        $embeds       = $paths;

        $scheme  = (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $pageUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');

        ee()->db->insert('arc_haystack_logs', [
            'template_path' => $mainTemplate,
            'main_template' => $mainTemplate,
            'page_url'      => substr($pageUrl, 0, 2048),
            'embeds_used'   => ! empty($embeds) ? json_encode($embeds) : null,
            'logged_at'     => time(),
        ]);

        return $out;
    }
}
