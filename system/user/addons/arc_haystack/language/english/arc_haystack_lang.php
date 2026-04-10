<?php

$lang = [
    'arc_haystack_module_name' => 'ARC Haystack',
    'arc_haystack_module_description' => 'Provides detailed tracking of which templates, partials, and variables are used to generate a page',

    // Page titles and navigation
    'arc_haystack_logs_title' => 'ARC Haystack Logs',
    'log_details_title' => 'Log Details',
    'clear_logs_title' => 'Clear Logs',
    'back_to_logs' => 'Back to Logs',

    // Alert messages
    'invalid_log' => 'Invalid Log',
    'no_log_id_specified' => 'No log ID specified.',
    'log_not_found' => 'Log Not Found',
    'log_not_found_message' => 'The requested log entry could not be found.',
    'logs_cleared' => 'Logs Cleared',
    'logs_cleared_message' => 'All haystack logs have been cleared.',

    // Settings
    'logging_enabled' => 'Logging Enabled',
    'logging_enabled_desc' => 'When disabled, the {exp:arc_haystack:log} tag will not record any data.',
    'enabled' => 'Enabled',
    'save' => 'Save',
    'settings_saved' => 'Settings Saved',

    // Index view
    'template_usage_logs' => 'Template Usage Logs',
    'log_entries' => 'log entries',
    'clear_all_logs' => 'Clear All Logs',
    'clear_logs_confirm' => 'Are you sure you want to delete all %s log entries? This action cannot be undone.',
    'no_logs_recorded' => 'No logs recorded yet. Use <code>{exp:arc_haystack:log}</code> in your templates to start logging.',

    // Table headers - Index
    'main_template' => 'Main Template',
    'layout' => 'Layout',
    'called_from' => 'Called From',
    'page_url' => 'Page URL',
    'embeds' => 'Embeds',
    'partials' => 'Partials',
    'variables' => 'Variables',
    'logged_at' => 'Logged At',
    'actions' => 'Actions',
    'view' => 'View',

    // View page - Log Entry Details
    'log_entry_details' => 'Log Entry Details',
    'log_id' => 'Log ID',
    'fallback' => '(fallback)',
    'layout_template' => 'Layout Template',
    'none_detected' => 'None detected',
    'tag_called_from' => 'Tag Called From',
    'unknown' => 'Unknown',

    // Main Template Information
    'main_template_information' => 'Main Template Information',
    'template_id' => 'Template ID',
    'template_path' => 'Template Path',
    'edit_template' => 'Edit Template',
    'template_type' => 'Template Type',
    'file_location' => 'File Location',
    'line_count' => 'Line Count',
    'php_enabled' => 'PHP Enabled',
    'parse_input' => 'Input',
    'parse_output' => 'Output',
    'contains_php_code' => 'Contains PHP Code',
    'stored_revisions' => 'Stored Revisions',
    'access_roles' => 'Access Roles',
    'caching_enabled' => 'Caching Enabled',
    'hit_count' => 'Hit Count',
    'template_not_found_error' => 'Template not found: %s',

    // Layout Template Details
    'layout_template_details' => 'Layout Template Details',
    'unable_to_load_template_details' => 'Unable to load template details',

    // Tag Called From Details
    'tag_called_from_details' => 'Tag Called From Details',

    // Embeds section
    'embeds_count' => 'Embeds (%s)',
    'template' => 'Template',
    'lines' => 'Lines',
    'php' => 'PHP',
    'revisions' => 'Revisions',
    'edit' => 'Edit',
    'not_found' => 'Not found',

    // Partials section
    'template_partials_count' => 'Template Partials (%s)',
    'partial_name' => 'Partial Name',
    'scope' => 'Scope',
    'global' => 'Global',
    'site' => 'Site',

    // Variables section
    'template_variables_count' => 'Template Variables (%s)',
    'variable_name' => 'Variable Name',

    // Common values
    'yes' => 'Yes',
    'no' => 'No',
    'database_only' => 'Database only',
    'all_roles' => 'All roles',
    'unknown_error' => 'Unknown error',
    'no_embeds_partials_variables' => 'No embeds, partials, or variables were logged for this page view.',

    // Error messages for View route
    'invalid_template_path_format' => 'Invalid template path format',
    'template_group_not_found' => 'Template group not found',
    'template_not_found' => 'Template not found',
    'partial_not_found' => 'Partial not found',
    'variable_not_found' => 'Variable not found',

    // TemplateInfo tag output labels
    'template_label' => 'Template',
    'id_label' => 'ID',
    'type_label' => 'Type',
    'file_label' => 'File',
    'lines_label' => 'Lines',
    'php_enabled_label' => 'PHP Enabled',
    'has_php_code_label' => 'Has PHP Code',
    'revisions_label' => 'Revisions',
    'access_label' => 'Access',

    // Export functionality
    'export_logs_title' => 'Export Logs',
    'export_log' => 'Export Log',
    'export_options' => 'Export Options',
    'export_options_description' => 'Export log entries as CSV or XML. All filters are optional.',
    'export_format' => 'Format',
    'export_format_desc' => 'Choose the file format for the export.',
    'export_format_csv' => 'CSV',
    'export_format_xml' => 'XML',
    'export_date_start' => 'Start Date/Time',
    'export_date_start_desc' => 'Only include log entries on or after this date/time.',
    'export_date_end' => 'End Date/Time',
    'export_date_end_desc' => 'Only include log entries on or before this date/time.',
    'export_limit' => 'Limit',
    'export_limit_desc' => 'Maximum number of records to export.',
    'export_limit_placeholder' => 'No limit',
    'export_button' => 'Export',
    'cancel' => 'Cancel',
];
