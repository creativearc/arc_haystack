<?php

namespace CreativeArc\ArcHaystack;

class Settings
{
    protected static array $defaults = [
        'logging_enabled' => 'y',
    ];

    public static function get(): array
    {
        $rows = ee()->db
            ->select('setting_key, setting_value')
            ->from('arc_haystack_settings')
            ->get()
            ->result_array();

        $settings = static::$defaults;
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return $settings;
    }

    public static function save(array $settings): void
    {
        foreach ($settings as $key => $value) {
            ee()->db->replace('arc_haystack_settings', [
                'setting_key'   => $key,
                'setting_value' => $value,
            ]);
        }
    }
}
