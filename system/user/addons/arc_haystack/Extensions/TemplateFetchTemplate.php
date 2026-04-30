<?php

namespace CreativeArc\ArcHaystack\Extensions;

use ExpressionEngine\Service\Addon\Controllers\Extension\AbstractRoute;

class TemplateFetchTemplate extends AbstractRoute
{
    /**
     * Fires each time EE loads a template from the database (main template,
     * embeds, and layout templates all trigger this hook separately).
     *
     * @param array $row
     * @return array
     */
    public function process($row)
    {
        if (! defined('REQ') || REQ !== 'PAGE' || ! is_array($row)) {
            return ee()->extensions->last_call !== false ? ee()->extensions->last_call : $row;
        }

        $group = $row['group_name'] ?? '';
        $template = $row['template_name'] ?? '';

        if ($group !== '' && $template !== '') {
            RequestState::$fetchedPaths[] = $group . '/' . $template;
        }

        if (! empty($row['template_data'])) {
            RequestState::$templateContent[] = $row['template_data'];
        }

        return ee()->extensions->last_call !== false ? ee()->extensions->last_call : $row;
    }
}
