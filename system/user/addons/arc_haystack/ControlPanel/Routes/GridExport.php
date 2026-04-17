<?php

namespace CreativeArc\ArcHaystack\ControlPanel\Routes;

use CreativeArc\ArcHaystack\Traits\BuildsTemplateGrid;
use ExpressionEngine\Service\Addon\Controllers\Mcp\AbstractRoute;

class GridExport extends AbstractRoute
{
    use BuildsTemplateGrid;

    protected $route_path = 'grid_export';
    protected $cp_page_title = 'export_logs_title';

    public function process($id = false)
    {
        $typeFilter   = ee('Request')->get('grid_type', 'all');
        $activeFilter = ee('Request')->get('grid_active', 'all');

        if (ee('Request')->isPost()) {
            $format = ee('Request')->post('export_format');
            $grid   = $this->fetchGridData($typeFilter, $activeFilter);

            if ($format === 'xml') {
                $this->generateXml($grid);
            }

            $this->generateCsv($grid);
        }

        $vars = [
            'export_url' => ee('CP/URL')->make('addons/settings/arc_haystack/grid_export', [
                'grid_type'   => $typeFilter,
                'grid_active' => $activeFilter,
            ]),
            'back_url' => ee('CP/URL')->make('addons/settings/arc_haystack'),
        ];

        $this->setHeading(lang($this->cp_page_title));
        $this->addBreadcrumb('index', 'arc_haystack_module_name');
        $this->setBody('grid_export', $vars);

        return $this;
    }

    protected function sendDownloadHeaders(string $filename, string $contentType): void
    {
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    protected function generateCsv(array $grid): void
    {
        $filename = 'arc_haystack_grid_' . date('Y-m-d_His') . '.csv';
        $this->sendDownloadHeaders($filename, 'text/csv; charset=utf-8');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel

        fputcsv($output, ['Template Group', 'Name', 'Type', 'Active', 'Logged At']);

        foreach ($grid as $row) {
            fputcsv($output, [
                $row['group_name'],
                $row['name'],
                $row['type'],
                $row['active'] ? 'Yes' : '',
                $row['logged_at'] ? date('Y-m-d H:i:s', $row['logged_at']) : '',
            ]);
        }

        fclose($output);
        exit;
    }

    protected function generateXml(array $grid): void
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElement('templates');
        $dom->appendChild($root);

        foreach ($grid as $row) {
            $entry = $dom->createElement('entry');
            $entry->appendChild($dom->createElement('group',     $row['group_name']));
            $entry->appendChild($dom->createElement('name',      $row['name']));
            $entry->appendChild($dom->createElement('type',      $row['type']));
            $entry->appendChild($dom->createElement('active',    $row['active'] ? 'yes' : ''));
            $entry->appendChild($dom->createElement('logged_at', $row['logged_at'] ? date('Y-m-d H:i:s', $row['logged_at']) : ''));
            $root->appendChild($entry);
        }

        $filename = 'arc_haystack_grid_' . date('Y-m-d_His') . '.xml';
        $this->sendDownloadHeaders($filename, 'application/xml; charset=utf-8');

        echo $dom->saveXML();
        exit;
    }
}
