<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

class ComfyUiFleetTemplateService
{
    private array $templates;
    private array $templatesBySlug;

    public function __construct()
    {
        $this->templates = $this->loadTemplates();
        $this->templatesBySlug = [];
        foreach ($this->templates as $template) {
            $this->templatesBySlug[$template['template_slug']] = $template;
        }
    }

    public function all(): array
    {
        return $this->templates;
    }

    public function find(string $slug): ?array
    {
        return $this->templatesBySlug[$slug] ?? null;
    }

    public function requireTemplate(string $slug): array
    {
        $template = $this->find($slug);
        if (!$template) {
            throw new InvalidArgumentException("Unknown fleet template: {$slug}");
        }

        return $template;
    }

    private function loadTemplates(): array
    {
        $path = base_path('resources/comfyui/fleet-templates.json');
        if (!file_exists($path)) {
            throw new RuntimeException("Fleet templates file not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Failed to read fleet templates file: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Fleet templates must be a JSON array.');
        }

        $seen = [];
        $templates = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Fleet template entry must be an object.');
            }

            $slug = $item['template_slug'] ?? null;
            $displayName = $item['display_name'] ?? null;
            $allowedInstanceTypes = $item['allowed_instance_types'] ?? null;
            $maxSize = $item['max_size'] ?? null;

            if (!is_string($slug) || $slug === '') {
                throw new RuntimeException('Fleet template missing template_slug.');
            }
            if (!is_string($displayName) || $displayName === '') {
                throw new RuntimeException("Fleet template {$slug} missing display_name.");
            }
            if (!is_array($allowedInstanceTypes) || $allowedInstanceTypes === []) {
                throw new RuntimeException("Fleet template {$slug} must define allowed_instance_types.");
            }
            foreach ($allowedInstanceTypes as $instanceType) {
                if (!is_string($instanceType) || $instanceType === '') {
                    throw new RuntimeException("Fleet template {$slug} has an invalid instance type.");
                }
            }
            if (!is_int($maxSize)) {
                throw new RuntimeException("Fleet template {$slug} must define max_size.");
            }
            if (isset($seen[$slug])) {
                throw new RuntimeException("Duplicate fleet template slug: {$slug}");
            }

            $seen[$slug] = true;
            $templates[] = [
                'template_slug' => $slug,
                'display_name' => $displayName,
                'allowed_instance_types' => $allowedInstanceTypes,
                'max_size' => $maxSize,
                'warmup_seconds' => isset($item['warmup_seconds']) ? (int) $item['warmup_seconds'] : null,
                'backlog_target' => isset($item['backlog_target']) ? (int) $item['backlog_target'] : null,
                'scale_to_zero_minutes' => isset($item['scale_to_zero_minutes']) ? (int) $item['scale_to_zero_minutes'] : null,
            ];
        }

        return $templates;
    }
}
