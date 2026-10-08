<?php

namespace App\Enums\Hrms;

enum TemplateTaskCategory: string
{
    case Document = 'document';
    case Task = 'task';
    case Asset = 'asset';
    case Access = 'access';
    case Orientation = 'orientation';
    case Other = 'other';

    /**
     * `document` is the hinge: materialising a case turns each one into a
     * `document_requests` row, which is why documents had to ship first.
     */
    public function label(): string
    {
        return match ($this) {
            self::Document => 'Document',
            self::Task => 'Task',
            self::Asset => 'Asset',
            self::Access => 'System access',
            self::Orientation => 'Orientation',
            self::Other => 'Other',
        };
    }
}
