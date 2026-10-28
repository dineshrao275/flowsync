<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Media;
use App\Models\WebsitePage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * The product tour on the public site: real application screenshots (kept in `resources/site-assets/product`) imported
 * into the media library and wired into the `showcase` block of the home and features pages. Idempotent — safe to run on
 * every deploy; once an editor changes the block in the CMS this leaves it alone (it only ever ADDS a missing block or a
 * missing hero image).
 */
class SiteShowcase
{
    /** @var array<string, array{title: string, text: string, alt: string}> */
    public const SHOTS = [
        'dashboard' => ['title' => 'Your whole workspace at a glance', 'text' => 'Live task, hours-logged and delivery charts across every project — no spreadsheets to refresh.', 'alt' => 'Dashboard showing open tasks, overdue work, project progress and hours logged'],
        'board' => ['title' => 'Plan and ship on a Kanban board', 'text' => 'Drag cards between columns with WIP limits, swimlanes, story points, labels and due dates.', 'alt' => 'Kanban board with Backlog, To Do, In Progress, In Review and Done columns'],
        'task-detail' => ['title' => 'Every detail behind one task', 'text' => 'Rich descriptions, comments, attachments, dependencies, time logs and a full activity trail.', 'alt' => 'Task detail drawer with description, issue type, status, priority and labels'],
        'employees' => ['title' => 'A people directory that scales', 'text' => 'Search and filter hundreds of employees by department, manager, status or work mode — and save the views you use.', 'alt' => 'Employee directory with search, filters and saved filters'],
        'attendance' => ['title' => 'Attendance without the guesswork', 'text' => 'Monthly calendars, one-tap clock-in, late and overtime totals, and regularisation approvals.', 'alt' => 'Monthly attendance calendar with present, absent and week-off days and month totals'],
        'leave' => ['title' => 'Leave, approved in a click', 'text' => 'Requests, balances, policies and blackout dates for every leave type, with manager approval built in.', 'alt' => 'Leave requests table with employee, dates, leave type, days and status'],
    ];

    /** @return array<string, int> shot name → media id */
    public static function importMedia(): array
    {
        $ids = [];
        $disk = Storage::disk('public');
        $source = resource_path('site-assets/product');

        foreach (self::SHOTS as $name => $shot) {
            $file = "{$source}/{$name}.webp";
            if (! File::exists($file)) {
                continue;
            }

            $original = "showcase-{$name}.webp";
            $media = Media::where('original_name', $original)->first();

            if (! $media || ! $disk->exists($media->path)) {
                $path = "media/{$original}";
                $disk->put($path, File::get($file));
                [$w, $h] = @getimagesize($file) ?: [null, null];
                $media = Media::updateOrCreate(['original_name' => $original], [
                    'disk' => 'public', 'path' => $path, 'mime' => 'image/webp', 'size' => File::size($file),
                    'width' => $w, 'height' => $h, 'alt' => $shot['alt'],
                ]);
            }

            $ids[$name] = $media->id;
        }

        return $ids;
    }

    /** @return int number of pages changed */
    public static function install(): int
    {
        $ids = self::importMedia();
        if ($ids === []) {
            return 0;
        }

        $changed = 0;

        foreach (['home', 'features'] as $slug) {
            $page = WebsitePage::where('slug', $slug)->first();
            if (! $page) {
                continue;
            }

            $content = array_values((array) $page->content);
            $dirty = false;

            // Hero: the dashboard under the call to action (home only).
            foreach ($content as $i => $block) {
                if (($block['type'] ?? null) === 'hero' && empty($block['media_id']) && $slug === 'home' && isset($ids['dashboard'])) {
                    $content[$i]['media_id'] = $ids['dashboard'];
                    $dirty = true;
                }
            }

            // Split blocks still showing the empty placeholder get a real screen (board, then leave, then employees).
            $spare = array_values(array_filter(array_map(fn ($n) => $ids[$n] ?? null, ['board', 'leave', 'employees'])));
            foreach ($content as $i => $block) {
                if (($block['type'] ?? null) === 'split' && empty($block['media_id']) && $spare !== []) {
                    $content[$i]['media_id'] = array_shift($spare);
                    $dirty = true;
                }
            }

            if (! collect($content)->contains(fn ($b) => ($b['type'] ?? null) === 'showcase')) {
                $block = [
                    'type' => 'showcase',
                    'eyebrow' => 'See it in action',
                    'heading' => 'One workspace for projects and people',
                    'lead' => 'Real screens from the product — from the sprint board to payroll-ready attendance.',
                    'items' => collect(self::SHOTS)->filter(fn ($_, $name) => isset($ids[$name]))
                        ->map(fn ($shot, $name) => ['title' => $shot['title'], 'text' => $shot['text'], 'media_id' => $ids[$name]])->values()->all(),
                ];

                // After the first features block when there is one, otherwise right after the hero.
                $at = collect($content)->search(fn ($b) => ($b['type'] ?? null) === 'features');
                $at = $at === false ? (collect($content)->search(fn ($b) => ($b['type'] ?? null) === 'hero') === false ? 0 : collect($content)->search(fn ($b) => ($b['type'] ?? null) === 'hero')) : $at;
                array_splice($content, $at + 1, 0, [$block]);
                $dirty = true;
            }

            if ($dirty) {
                $page->update(['content' => $content]);
                $changed++;
            }
        }

        return $changed;
    }
}
