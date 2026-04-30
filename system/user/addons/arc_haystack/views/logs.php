<?php echo ee('CP/Alert')->getAllInlines(); ?>
<?php
function arcLogSortTh($label, $col, $currentSort, $currentDir, $baseUrl, $sortParam, $dirParam) {
    $newDir = ($currentSort === $col && $currentDir === 'asc') ? 'desc' : 'asc';
    $indicator = $currentSort === $col ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';
    $sep = strpos($baseUrl, '?') !== -1 ? '&' : '?';
    $url = $baseUrl . $sep . $sortParam . '=' . urlencode($col) . '&' . $dirParam . '=' . urlencode($newDir);
    return '<th><a href="' . htmlspecialchars($url) . '" style="color:inherit;text-decoration:none;white-space:nowrap;">' . htmlspecialchars($label) . $indicator . '</a></th>';
}
?>

<div class="panel" style="margin-bottom: 20px;">
    <div class="panel-body">
        <form method="post" action="<?php echo $settings_url; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo CSRF_TOKEN; ?>">
            <input type="hidden" name="save_settings" value="y">
            <input type="hidden" name="logging_enabled" value="n">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div>
                    <label style="font-weight: bold; margin-right: 10px;"><?php echo lang('logging_enabled'); ?></label>
                    <span class="txt-muted"><?php echo lang('logging_enabled_desc'); ?></span>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <label class="checkbox-label">
                        <input type="checkbox" name="logging_enabled" value="y" <?php if ($logging_enabled === 'y') echo 'checked'; ?>>
                        <div class="checkbox-label__text"><?php echo lang('enabled'); ?></div>
                    </label>
                    <button type="submit" class="button button--small button--primary"><?php echo lang('save'); ?></button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-heading">
        <div class="title-bar">
            <h3 class="title-bar__title"><?php echo lang('template_usage_logs_title'); ?></h3>
            <div class="title-bar__extra-tools">
                <?php if ($total_logs > 0): ?>
                    <a class="button button--primary" href="<?php echo $export_url; ?>"><?php echo lang('export_log'); ?></a>
                    <a class="button button--default" href="<?php echo $clear_url; ?>" onclick="return confirm('<?php echo sprintf(lang('clear_logs_confirm'), $total_logs); ?>');"><?php echo lang('clear_all_logs'); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="panel-body">
        <?php if (empty($logs)): ?>
            <p class="no-results"><?php echo lang('no_logs_recorded'); ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-list">
                    <thead>
                        <tr>
                            <?php echo arcLogSortTh(lang('main_template'), 'main_template', $log_sort, $log_dir, $log_sort_base_url, 'log_sort', 'log_dir'); ?>
                            <?php echo arcLogSortTh(lang('page_url'),      'page_url',      $log_sort, $log_dir, $log_sort_base_url, 'log_sort', 'log_dir'); ?>
                            <th><?php echo lang('embeds'); ?></th>
                            <th><?php echo lang('partials'); ?></th>
                            <th><?php echo lang('variables'); ?></th>
                            <?php echo arcLogSortTh(lang('logged_at'), 'logged_at', $log_sort, $log_dir, $log_sort_base_url, 'log_sort', 'log_dir'); ?>
                            <th><?php echo lang('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <?php if (! empty($log['main_template'])): ?>
                                        <code><?php echo htmlspecialchars($log['main_template']); ?></code>
                                    <?php else: ?>
                                        <code><?php echo htmlspecialchars($log['template_path']); ?></code>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $parsedUrl    = parse_url($log['page_url']);
                                        $relativePath = ($parsedUrl['path'] ?? '/');
                                        if (! empty($parsedUrl['query'])) {
                                            $relativePath .= '?' . $parsedUrl['query'];
                                        }
                                    ?>
                                    <a href="<?php echo htmlspecialchars($log['page_url']); ?>" target="_blank" rel="noopener" title="<?php echo htmlspecialchars($log['page_url']); ?>">
                                        <?php echo htmlspecialchars(strlen($relativePath) > 50 ? substr($relativePath, 0, 50) . '...' : $relativePath); ?>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <?php if ($log['embeds_count'] > 0): ?>
                                        <span class="st-info"><?php echo $log['embeds_count']; ?></span>
                                    <?php else: ?>
                                        <span class="txt-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($log['partials_count'] > 0): ?>
                                        <span class="st-info"><?php echo $log['partials_count']; ?></span>
                                    <?php else: ?>
                                        <span class="txt-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($log['variables_count'] > 0): ?>
                                        <span class="st-info"><?php echo $log['variables_count']; ?></span>
                                    <?php else: ?>
                                        <span class="txt-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($log['logged_at']); ?></td>
                                <td>
                                    <a href="<?php echo $log['view_url']; ?>" class="button button--small button--default"><?php echo lang('view'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php echo $pagination; ?>
        <?php endif; ?>
    </div>
</div>
