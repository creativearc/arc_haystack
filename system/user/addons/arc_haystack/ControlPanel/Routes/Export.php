<?php

namespace CreativeArc\ArcHaystack\ControlPanel\Routes;

use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

class Export extends AbstractRoute
{
    protected $route_path = 'export';
    protected $cp_page_title = 'export_logs_title';

    public function process($id = false)
    {
        $vars = [
            'export_url' => ee('CP/URL')->make('addons/settings/arc_haystack/export'),
            'back_url' => ee('CP/URL')->make('addons/settings/arc_haystack'),
        ];

        // POST request triggers download
        if (ee('Request')->isPost()) {
            $format = ee('Request')->post('export_format');
            if ($format === 'xml') {
                return $this->generateXml();
            }
            return $this->generateCsv();
        }

        $this->setHeading(lang($this->cp_page_title));
        $this->addBreadcrumb('index', 'arc_haystack_module_name');
        $this->setBody('export', $vars);

        return $this;
    }

    /**
     * Fetch filtered logs based on POST parameters
     */
    protected function fetchLogs()
    {
        $dateStart = ee('Request')->post('date_start');
        $dateEnd = ee('Request')->post('date_end');
        $limit = (int) ee('Request')->post('export_limit');

        ee()->db->select('*');
        ee()->db->from('arc_haystack_logs');

        if (!empty($dateStart)) {
            $startTimestamp = strtotime($dateStart);
            if ($startTimestamp !== false) {
                ee()->db->where('logged_at >=', $startTimestamp);
            }
        }

        if (!empty($dateEnd)) {
            $endTimestamp = strtotime($dateEnd);
            if ($endTimestamp !== false) {
                ee()->db->where('logged_at <=', $endTimestamp);
            }
        }

        ee()->db->order_by('logged_at', 'DESC');

        if ($limit > 0) {
            ee()->db->limit($limit);
        }

        return ee()->db->get()->result_array();
    }

    /**
     * Parse a log row into normalized arrays
     */
    protected function parseLogRow(array $log)
    {
        $embeds = !empty($log['embeds_used']) ? json_decode($log['embeds_used'], true) : [];
        $partials = !empty($log['partials_used']) ? json_decode($log['partials_used'], true) : [];
        $variables = !empty($log['variables_used']) ? json_decode($log['variables_used'], true) : [];

        return [
            'id' => $log['id'],
            'main_template' => !empty($log['main_template']) ? $log['main_template'] : $log['template_path'],
            'layout' => !empty($log['layout_template']) ? $log['layout_template'] : '',
            'page_url' => $log['page_url'],
            'embeds' => is_array($embeds) ? $embeds : [],
            'partials' => is_array($partials) ? $partials : [],
            'variables' => is_array($variables) ? $variables : [],
            'logged_at' => $log['logged_at'],
        ];
    }

    /**
     * Send download headers
     */
    protected function sendDownloadHeaders($filename, $contentType)
    {
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /**
     * Generate and download CSV matching the dashboard table view
     */
    protected function generateCsv()
    {
        $logs = $this->fetchLogs();

        $csvLines = [];
        $csvLines[] = [
            'Main Template',
            'Layout',
            'Page URL',
            'Embeds',
            'Partials',
            'Variables',
            'Logged At',
        ];

        foreach ($logs as $log) {
            $row = $this->parseLogRow($log);

            $csvLines[] = [
                $row['main_template'],
                $row['layout'],
                $row['page_url'],
                implode('; ', $row['embeds']),
                implode('; ', $row['partials']),
                implode('; ', $row['variables']),
                ee()->localize->human_time($row['logged_at']),
            ];
        }

        $filename = 'arc_haystack_export_' . date('Y-m-d_His') . '.csv';
        $this->sendDownloadHeaders($filename, 'text/csv; charset=utf-8');

        $output = fopen('php://output', 'w');

        // Add BOM for Excel compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        foreach ($csvLines as $line) {
            fputcsv($output, $line, ",", "\"", "\\");
        }

        fclose($output);
        exit;
    }

    /**
     * Generate and download XML matching the dashboard table view
     */
    protected function generateXml()
    {
        $logs = $this->fetchLogs();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('logs');
        $dom->appendChild($root);

        foreach ($logs as $log) {
            $row = $this->parseLogRow($log);

            $entry = $dom->createElement('entry');
            $root->appendChild($entry);

            $entry->appendChild($dom->createElement('id', $row['id']));
            $entry->appendChild($dom->createElement('date', date('Y-m-d H:i:s', $row['logged_at'])));
            $entry->appendChild($dom->createElement('page_url', $row['page_url']));
            $entry->appendChild($dom->createElement('main_template', $row['main_template']));
            $entry->appendChild($dom->createElement('layout_template', $row['layout']));

            // Embed templates
            $embedsNode = $dom->createElement('embed_templates');
            $embedsNode->setAttribute('count', count($row['embeds']));
            foreach ($row['embeds'] as $embed) {
                $embedsNode->appendChild($dom->createElement('embed', $embed));
            }
            $entry->appendChild($embedsNode);

            // Partial templates
            $partialsNode = $dom->createElement('partial_templates');
            $partialsNode->setAttribute('count', count($row['partials']));
            foreach ($row['partials'] as $partial) {
                $partialsNode->appendChild($dom->createElement('partial', $partial));
            }
            $entry->appendChild($partialsNode);

            // Variable templates
            $variablesNode = $dom->createElement('variable_templates');
            $variablesNode->setAttribute('count', count($row['variables']));
            foreach ($row['variables'] as $variable) {
                $variablesNode->appendChild($dom->createElement('variable', $variable));
            }
            $entry->appendChild($variablesNode);
        }

        $filename = 'arc_haystack_export_' . date('Y-m-d_His') . '.xml';
        $this->sendDownloadHeaders($filename, 'application/xml; charset=utf-8');

        echo $dom->saveXML();
        exit;
    }
}
