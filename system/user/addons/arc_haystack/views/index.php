<?php echo ee('CP/Alert')->getAllInlines(); ?>
<?php
function arcSortTh($label, $col, $currentSort, $currentDir, $baseUrl, $sortParam, $dirParam) {
    $newDir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
    $indicator = $currentSort === $col ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';
    $sep = strpos($baseUrl, '?') !== -1 ? '&' : '?';
    $url = $baseUrl . $sep . $sortParam . '=' . urlencode($col) . '&' . $dirParam . '=' . urlencode($newDir);
    return '<th><a href="' . htmlspecialchars($url) . '" style="color:inherit;text-decoration:none;white-space:nowrap;">' . htmlspecialchars($label) . $indicator . '</a></th>';
}
?>

<div class="panel">
    <div class="panel-heading">
        <div class="title-bar">
            <h3 class="title-bar__title"><?php echo lang('site_template_status_title'); ?></h3>
            <div class="title-bar__extra-tools">
                <div style="display: inline-flex; align-items: center; gap: 6px;">
                    <select id="arc-grid-type" class="select">
                        <?php
                        $typeOptions = [
                            'all'      => lang('filter_all_types'),
                            'template' => 'Template',
                            'partial'  => 'Partial',
                            'variable' => 'Variable',
                        ];
                        foreach ($typeOptions as $val => $label):
                        ?>
                            <option value="<?php echo $val; ?>" <?php if ($grid_type_filter === $val) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select id="arc-grid-active" class="select">
                        <?php
                        $activeOptions = [
                            'all'   => lang('filter_all'),
                            'yes'   => lang('filter_active_yes'),
                            'empty' => lang('filter_active_empty'),
                        ];
                        foreach ($activeOptions as $val => $label):
                        ?>
                            <option value="<?php echo $val; ?>" <?php if ($grid_active_filter === $val) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button button--default" onclick="arcHaystackGridFilter()"><?php echo lang('filter'); ?></button>
                </div>
                <script>
                function arcHaystackGridFilter() {
                    var base   = <?php echo json_encode($grid_filter_url); ?>;
                    var type   = document.getElementById('arc-grid-type').value;
                    var active = document.getElementById('arc-grid-active').value;
                    var sort   = <?php echo json_encode($grid_sort); ?>;
                    var dir    = <?php echo json_encode($grid_dir); ?>;
                    var sep    = base.indexOf('?') !== -1 ? '&' : '?';
                    var url    = base + sep + 'grid_type=' + encodeURIComponent(type) + '&grid_active=' + encodeURIComponent(active);
                    if (sort) { url += '&grid_sort=' + encodeURIComponent(sort) + '&grid_dir=' + encodeURIComponent(dir); }
                    window.location.href = url;
                }
                </script>
                <a class="button button--primary" href="<?php echo $grid_export_url; ?>"><?php echo lang('export_button'); ?></a>
            </div>
        </div>
    </div>
    <div class="panel-body">
        <?php if (empty($template_grid)): ?>
            <p class="no-results"><?php echo lang('no_templates_found'); ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-list">
                    <thead>
                        <tr>
                            <?php echo arcSortTh(lang('template_group'), 'group_name', $grid_sort, $grid_dir, $grid_sort_base_url, 'grid_sort', 'grid_dir'); ?>
                            <?php echo arcSortTh(lang('name'),           'name',       $grid_sort, $grid_dir, $grid_sort_base_url, 'grid_sort', 'grid_dir'); ?>
                            <?php echo arcSortTh(lang('type'),           'type',       $grid_sort, $grid_dir, $grid_sort_base_url, 'grid_sort', 'grid_dir'); ?>
                            <?php echo arcSortTh(lang('active'),         'active',     $grid_sort, $grid_dir, $grid_sort_base_url, 'grid_sort', 'grid_dir'); ?>
                            <?php echo arcSortTh(lang('logged_at'),      'logged_at',  $grid_sort, $grid_dir, $grid_sort_base_url, 'grid_sort', 'grid_dir'); ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($template_grid as $row): ?>
                            <tr>
                                <td>
                                    <?php if (in_array($row['row_type'], ['template', 'embed'])): ?>
                                        <code><?php echo htmlspecialchars($row['group_name']); ?></code>
                                    <?php else: ?>
                                        <span class="txt-muted"><?php echo htmlspecialchars($row['group_name']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['type']); ?></td>
                                <td class="text-center">
                                    <?php if ($row['active']): ?>
                                        <span class="st-open"><?php echo lang('yes'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['logged_at']): ?>
                                        <?php echo htmlspecialchars($row['logged_at']); ?>
                                    <?php else: ?>
                                        <span class="txt-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php echo $grid_pagination; ?>
        <?php endif; ?>
    </div>
</div>
