<div class="panel">
    <div class="panel-heading">
        <div class="title-bar">
            <h3 class="title-bar__title"><?php echo lang('log_entry_details'); ?></h3>
            <div class="title-bar__extra-tools">
                <a class="button button--default" href="<?php echo $back_url; ?>"><?php echo lang('back_to_logs'); ?></a>
            </div>
        </div>
    </div>
    <div class="panel-body">
        <div class="table-responsive">
            <table class="table-list">
                <tbody>
                    <tr>
                        <th style="width: 200px;"><?php echo lang('log_id'); ?></th>
                        <td><?php echo htmlspecialchars($log['id']); ?></td>
                    </tr>
                    <tr>
                        <th><?php echo lang('main_template'); ?></th>
                        <td>
                            <?php if (!empty($log['main_template'])): ?>
                                <code><?php echo htmlspecialchars($log['main_template']); ?></code>
                            <?php else: ?>
                                <code><?php echo htmlspecialchars($log['template_path']); ?></code>
                                <span class="txt-muted"><?php echo lang('fallback'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo lang('layout_template'); ?></th>
                        <td>
                            <?php $layoutVal = $log['layout_template'] ?? null; ?>
                            <?php if ($layoutVal): ?>
                                <code><?php echo htmlspecialchars($layoutVal); ?></code>
                            <?php else: ?>
                                <span class="txt-muted"><?php echo lang('none_detected'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo lang('tag_called_from'); ?></th>
                        <td>
                            <?php $calledVal = $log['called_from'] ?? null; ?>
                            <?php if ($calledVal): ?>
                                <code><?php echo htmlspecialchars($calledVal); ?></code>
                            <?php else: ?>
                                <span class="txt-muted"><?php echo lang('unknown'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo lang('page_url'); ?></th>
                        <td>
                            <a href="<?php echo htmlspecialchars($log['page_url']); ?>" target="_blank" rel="noopener">
                                <?php echo htmlspecialchars($log['page_url']); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <th><?php echo lang('logged_at'); ?></th>
                        <td><?php echo htmlspecialchars($log['logged_at']); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Main Template Info -->
<div class="panel">
    <div class="panel-heading">
        <div class="title-bar">
            <h3 class="title-bar__title"><?php echo lang('main_template_information'); ?></h3>
        </div>
    </div>
    <div class="panel-body">
        <?php if ($main_template['found']): ?>
            <div class="table-responsive">
                <table class="table-list">
                    <tbody>
                        <tr>
                            <th style="width: 200px;"><?php echo lang('template_id'); ?></th>
                            <td><?php echo htmlspecialchars($main_template['template_id']); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo lang('template_path'); ?></th>
                            <td>
                                <code><?php echo htmlspecialchars($main_template['path']); ?></code>
                                <a href="<?php echo $main_template['edit_url']; ?>" class="button button--small button--default" style="margin-left: 10px;"><?php echo lang('edit_template'); ?></a>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo lang('template_type'); ?></th>
                            <td><?php echo htmlspecialchars($main_template['template_type']); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo lang('file_location'); ?></th>
                            <td><code><?php echo htmlspecialchars($main_template['file_path']); ?></code></td>
                        </tr>
                        <tr>
                            <th><?php echo lang('line_count'); ?></th>
                            <td><?php echo number_format($main_template['line_count']); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo lang('php_enabled'); ?></th>
                            <td>
                                <?php if ($main_template['allow_php'] === 'y'): ?>
                                    <span class="st-warning"><?php echo lang('yes'); ?></span>
                                    (<?php echo lang('parse_input'); ?>: <?php echo $main_template['php_parse_location'] === 'i' ? lang('parse_input') : lang('parse_output'); ?>)
                                <?php else: ?>
                                    <?php echo lang('no'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo lang('contains_php_code'); ?></th>
                            <td>
                                <?php if ($main_template['has_php_code'] === lang('yes')): ?>
                                    <span class="st-warning"><?php echo htmlspecialchars($main_template['has_php_code']); ?></span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($main_template['has_php_code']); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo lang('stored_revisions'); ?></th>
                            <td><?php echo number_format($main_template['revision_count']); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo lang('access_roles'); ?></th>
                            <td><?php echo htmlspecialchars($main_template['access_roles']); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo lang('caching_enabled'); ?></th>
                            <td><?php echo htmlspecialchars($main_template['cache_enabled']); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo lang('hit_count'); ?></th>
                            <td><?php echo number_format($main_template['hits']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="txt-muted"><?php echo sprintf(lang('template_not_found_error'), htmlspecialchars($main_template['error'] ?? lang('unknown_error'))); ?></p>
        <?php endif; ?>
    </div>
</div>

<!-- Layout Template Info -->
<?php $layoutTemplateVal = $log['layout_template'] ?? null; ?>
<?php if ($layoutTemplateVal): ?>
<div class="panel">
    <div class="panel-heading">
        <div class="title-bar">
            <h3 class="title-bar__title"><?php echo lang('layout_template_details'); ?></h3>
        </div>
    </div>
    <div class="panel-body">
        <?php if (!empty($layout_template) && $layout_template['found']): ?>
            <div class="table-responsive">
                <table class="table-list">
                    <tbody>
                        <tr>
                            <th style="width: 200px;"><?php echo lang('template_path'); ?></th>
                            <td>
                                <code><?php echo htmlspecialchars($layout_template['path']); ?></code>
                                <a href="<?php echo $layout_template['edit_url']; ?>" class="button button--small button--default" style="margin-left: 10px;"><?php echo lang('edit_template'); ?></a>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo lang('file_location'); ?></th>
                            <td><code><?php echo htmlspecialchars($layout_template['file_path']); ?></code></td>
                        </tr>
                        <tr>
                            <th><?php echo lang('line_count'); ?></th>
                            <td><?php echo number_format($layout_template['line_count']); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo lang('php_enabled'); ?></th>
                            <td>
                                <?php if ($layout_template['allow_php'] === 'y'): ?>
                                    <span class="st-warning"><?php echo lang('yes'); ?></span>
                                <?php else: ?>
                                    <?php echo lang('no'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo lang('stored_revisions'); ?></th>
                            <td><?php echo number_format($layout_template['revision_count']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php elseif (!empty($layout_template)): ?>
            <p class="txt-muted"><?php echo sprintf(lang('template_not_found_error'), htmlspecialchars($layout_template['error'] ?? lang('unknown_error'))); ?></p>
        <?php else: ?>
            <p><?php echo lang('template_path'); ?>: <code><?php echo htmlspecialchars($layoutTemplateVal); ?></code></p>
            <p class="txt-muted"><?php echo lang('unable_to_load_template_details'); ?></p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Called From Template Info -->
<?php $calledFromVal = $log['called_from'] ?? null; ?>
<?php if ($calledFromVal): ?>
<div class="panel">
    <div class="panel-heading">
        <div class="title-bar">
            <h3 class="title-bar__title"><?php echo lang('tag_called_from_details'); ?></h3>
        </div>
    </div>
    <div class="panel-body">
        <?php if (!empty($called_from) && $called_from['found']): ?>
            <div class="table-responsive">
                <table class="table-list">
                    <tbody>
                        <tr>
                            <th style="width: 200px;"><?php echo lang('template_path'); ?></th>
                            <td>
                                <code><?php echo htmlspecialchars($called_from['path']); ?></code>
                                <a href="<?php echo $called_from['edit_url']; ?>" class="button button--small button--default" style="margin-left: 10px;"><?php echo lang('edit_template'); ?></a>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo lang('file_location'); ?></th>
                            <td><code><?php echo htmlspecialchars($called_from['file_path']); ?></code></td>
                        </tr>
                        <tr>
                            <th><?php echo lang('line_count'); ?></th>
                            <td><?php echo number_format($called_from['line_count']); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php elseif (!empty($called_from)): ?>
            <p class="txt-muted"><?php echo sprintf(lang('template_not_found_error'), htmlspecialchars($called_from['error'] ?? lang('unknown_error'))); ?></p>
        <?php else: ?>
            <p><?php echo lang('template_path'); ?>: <code><?php echo htmlspecialchars($calledFromVal); ?></code></p>
            <p class="txt-muted"><?php echo lang('unable_to_load_template_details'); ?></p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Embeds -->
<?php if (!empty($embeds)): ?>
<div class="panel">
    <div class="panel-heading">
        <div class="title-bar">
            <h3 class="title-bar__title"><?php echo sprintf(lang('embeds_count'), count($embeds)); ?></h3>
        </div>
    </div>
    <div class="panel-body">
        <div class="table-responsive">
            <table class="table-list">
                <thead>
                    <tr>
                        <th><?php echo lang('template'); ?></th>
                        <th><?php echo lang('file_location'); ?></th>
                        <th><?php echo lang('lines'); ?></th>
                        <th><?php echo lang('php'); ?></th>
                        <th><?php echo lang('revisions'); ?></th>
                        <th><?php echo lang('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($embeds as $embed): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($embed['display_path'] ?? $embed['path']); ?></code></td>
                            <?php if ($embed['found']): ?>
                                <td><code style="font-size: 11px;"><?php echo htmlspecialchars($embed['file_path']); ?></code></td>
                                <td><?php echo number_format($embed['line_count']); ?></td>
                                <td>
                                    <?php if ($embed['allow_php'] === 'y'): ?>
                                        <span class="st-warning"><?php echo lang('yes'); ?></span>
                                    <?php else: ?>
                                        <?php echo lang('no'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format($embed['revision_count']); ?></td>
                                <td>
                                    <a href="<?php echo $embed['edit_url']; ?>" class="button button--small button--default"><?php echo lang('edit'); ?></a>
                                </td>
                            <?php else: ?>
                                <td colspan="5" class="txt-muted"><?php echo htmlspecialchars($embed['error'] ?? lang('not_found')); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Partials -->
<?php if (!empty($partials)): ?>
<div class="panel">
    <div class="panel-heading">
        <div class="title-bar">
            <h3 class="title-bar__title"><?php echo sprintf(lang('template_partials_count'), count($partials)); ?></h3>
        </div>
    </div>
    <div class="panel-body">
        <div class="table-responsive">
            <table class="table-list">
                <thead>
                    <tr>
                        <th><?php echo lang('partial_name'); ?></th>
                        <th><?php echo lang('scope'); ?></th>
                        <th><?php echo lang('file_location'); ?></th>
                        <th><?php echo lang('lines'); ?></th>
                        <th><?php echo lang('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($partials as $partial): ?>
                        <tr>
                            <td><code>{<?php echo htmlspecialchars($partial['name']); ?>}</code></td>
                            <?php if ($partial['found']): ?>
                                <td>
                                    <?php if ($partial['is_global']): ?>
                                        <span class="st-info"><?php echo lang('global'); ?></span>
                                    <?php else: ?>
                                        <?php echo lang('site'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><code style="font-size: 11px;"><?php echo htmlspecialchars($partial['file_path']); ?></code></td>
                                <td><?php echo number_format($partial['line_count']); ?></td>
                                <td>
                                    <a href="<?php echo $partial['edit_url']; ?>" class="button button--small button--default"><?php echo lang('edit'); ?></a>
                                </td>
                            <?php else: ?>
                                <td colspan="4" class="txt-muted"><?php echo htmlspecialchars($partial['error'] ?? lang('not_found')); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Variables -->
<?php if (!empty($variables)): ?>
<div class="panel">
    <div class="panel-heading">
        <div class="title-bar">
            <h3 class="title-bar__title"><?php echo sprintf(lang('template_variables_count'), count($variables)); ?></h3>
        </div>
    </div>
    <div class="panel-body">
        <div class="table-responsive">
            <table class="table-list">
                <thead>
                    <tr>
                        <th><?php echo lang('variable_name'); ?></th>
                        <th><?php echo lang('scope'); ?></th>
                        <th><?php echo lang('file_location'); ?></th>
                        <th><?php echo lang('lines'); ?></th>
                        <th><?php echo lang('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($variables as $variable): ?>
                        <tr>
                            <td><code>{<?php echo htmlspecialchars($variable['name']); ?>}</code></td>
                            <?php if ($variable['found']): ?>
                                <td>
                                    <?php if ($variable['is_global']): ?>
                                        <span class="st-info"><?php echo lang('global'); ?></span>
                                    <?php else: ?>
                                        <?php echo lang('site'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><code style="font-size: 11px;"><?php echo htmlspecialchars($variable['file_path']); ?></code></td>
                                <td><?php echo number_format($variable['line_count']); ?></td>
                                <td>
                                    <a href="<?php echo $variable['edit_url']; ?>" class="button button--small button--default"><?php echo lang('edit'); ?></a>
                                </td>
                            <?php else: ?>
                                <td colspan="4" class="txt-muted"><?php echo htmlspecialchars($variable['error'] ?? lang('not_found')); ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (empty($embeds) && empty($partials) && empty($variables)): ?>
<div class="panel">
    <div class="panel-body">
        <p class="txt-muted"><?php echo lang('no_embeds_partials_variables'); ?></p>
    </div>
</div>
<?php endif; ?>
