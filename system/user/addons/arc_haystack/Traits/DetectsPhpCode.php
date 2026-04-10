<?php

namespace CreativeArc\ArcHaystack\Traits;

trait DetectsPhpCode
{
    protected function detectPhpCode(string $content): bool
    {
        if (empty($content)) {
            return false;
        }

        if (preg_match('/<\?php/i', $content)) {
            return true;
        }

        if (preg_match('/<\?[^x]/i', $content)) {
            return true;
        }

        if (preg_match('/<%/', $content)) {
            return true;
        }

        if (preg_match('/<script[^>]+type=["\']?php["\']?/i', $content)) {
            return true;
        }

        return false;
    }
}
