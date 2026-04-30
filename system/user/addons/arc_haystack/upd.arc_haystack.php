<?php

if (! defined('BASEPATH')) {
    exit('No direct script access allowed');
}

use ExpressionEngine\Service\Addon\Installer;

class Arc_haystack_upd extends Installer
{
    public $has_cp_backend    = 'y';
    public $has_publish_fields = 'n';

    public $methods = [
        ['hook' => 'template_fetch_template', 'priority' => 10, 'enabled' => 'y'],
        ['hook' => 'template_post_parse',     'priority' => 10, 'enabled' => 'y'],
    ];

    public function install()
    {
        parent::install();
        $this->createLogTable();
        $this->createSettingsTable();
        return true;
    }

    public function update($current = '')
    {
        ee()->load->dbforge();

        if (version_compare($current, '1.1.0', '<')) {
            $this->createLogTable();
        }

        if (version_compare($current, '1.2.0', '<')) {
            $this->addColumnIfMissing('embeds_used',    ['type' => 'TEXT', 'null' => true, 'after' => 'page_url']);
            $this->addColumnIfMissing('partials_used',  ['type' => 'TEXT', 'null' => true, 'after' => 'embeds_used']);
            $this->addColumnIfMissing('variables_used', ['type' => 'TEXT', 'null' => true, 'after' => 'partials_used']);
        }

        if (version_compare($current, '1.3.0', '<')) {
            $this->addColumnIfMissing('main_template',   ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'template_path']);
            $this->addColumnIfMissing('layout_template', ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'main_template']);
            $this->addColumnIfMissing('called_from',     ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'layout_template']);
        }

        // 1.4.0: Converted to modern MVC routing (no schema changes)

        if (version_compare($current, '1.5.0', '<')) {
            $this->createSettingsTable();
        }

        parent::update($current);

        return true;
    }

    public function uninstall()
    {
        ee()->load->dbforge();
        ee()->dbforge->drop_table('arc_haystack_logs');
        ee()->dbforge->drop_table('arc_haystack_settings', true);
        parent::uninstall();
        return true;
    }

    protected function createLogTable()
    {
        ee()->load->dbforge();

        if (ee()->db->table_exists('arc_haystack_logs')) {
            return;
        }

        ee()->dbforge->add_field([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'template_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'main_template' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'layout_template' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'called_from' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'page_url' => [
                'type'       => 'VARCHAR',
                'constraint' => 2048,
            ],
            'embeds_used' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'partials_used' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'variables_used' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'logged_at' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
        ]);

        ee()->dbforge->add_key('id', true);
        ee()->dbforge->add_key('logged_at');
        ee()->dbforge->create_table('arc_haystack_logs', true);
    }

    protected function createSettingsTable(): void
    {
        ee()->load->dbforge();

        if (ee()->db->table_exists('arc_haystack_settings')) {
            return;
        }

        ee()->dbforge->add_field([
            'setting_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'setting_value' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ]);
        ee()->dbforge->add_key('setting_key', true);
        ee()->dbforge->create_table('arc_haystack_settings', true);
    }

    protected function addColumnIfMissing(string $column, array $definition): void
    {
        if (!in_array($column, ee()->db->list_fields('arc_haystack_logs'))) {
            ee()->dbforge->add_column('arc_haystack_logs', [$column => $definition]);
        }
    }
}
