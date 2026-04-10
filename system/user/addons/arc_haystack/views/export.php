<div class="panel">
    <div class="panel-heading">
        <div class="title-bar">
            <h3 class="title-bar__title"><?php echo lang('export_options'); ?></h3>
        </div>
    </div>
    <div class="panel-body">
        <p class="txt-muted" style="margin-bottom: 20px;"><?php echo lang('export_options_description'); ?></p>

        <form method="post" action="<?php echo $export_url; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo CSRF_TOKEN; ?>">

            <fieldset>
                <div class="field-instruct">
                    <label for="export_format"><?php echo lang('export_format'); ?></label>
                    <em><?php echo lang('export_format_desc'); ?></em>
                </div>
                <div class="field-control">
                    <select name="export_format" id="export_format">
                        <option value="csv"><?php echo lang('export_format_csv'); ?></option>
                        <option value="xml"><?php echo lang('export_format_xml'); ?></option>
                    </select>
                </div>
            </fieldset>

            <fieldset>
                <div class="field-instruct">
                    <label for="date_start"><?php echo lang('export_date_start'); ?></label>
                    <em><?php echo lang('export_date_start_desc'); ?></em>
                </div>
                <div class="field-control">
                    <input type="datetime-local" name="date_start" id="date_start" value="">
                </div>
            </fieldset>

            <fieldset>
                <div class="field-instruct">
                    <label for="date_end"><?php echo lang('export_date_end'); ?></label>
                    <em><?php echo lang('export_date_end_desc'); ?></em>
                </div>
                <div class="field-control">
                    <input type="datetime-local" name="date_end" id="date_end" value="">
                </div>
            </fieldset>

            <fieldset>
                <div class="field-instruct">
                    <label for="export_limit"><?php echo lang('export_limit'); ?></label>
                    <em><?php echo lang('export_limit_desc'); ?></em>
                </div>
                <div class="field-control">
                    <input type="number" name="export_limit" id="export_limit" value="" min="1" placeholder="<?php echo lang('export_limit_placeholder'); ?>">
                </div>
            </fieldset>

            <fieldset class="form-ctrls">
                <button type="submit" class="button button--primary"><?php echo lang('export_button'); ?></button>
                <a href="<?php echo $back_url; ?>" class="button button--default"><?php echo lang('cancel'); ?></a>
            </fieldset>
        </form>
    </div>
</div>
