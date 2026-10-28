<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\IssueType;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** The issue types a project allows. An empty scheme means every type is allowed (the pre-F4.8 behaviour). */
class ProjectIssueScheme
{
    /** @return list<int> */
    public static function ids(Project $project): array
    {
        if (! Schema::hasTable('project_issue_types')) {
            return [];
        }

        return DB::table('project_issue_types')->where('project_id', $project->id)->pluck('issue_type_id')->map(fn ($v) => (int) $v)->all();
    }

    public static function assertAllowed(Project $project, ?IssueType $type): void
    {
        $ids = self::ids($project);

        if ($type !== null && $ids !== [] && ! in_array($type->id, $ids, true)) {
            throw ValidationException::withMessages(['issue_type_id' => __('This project does not use the :type issue type.', ['type' => $type->name])]);
        }
    }

    /** @param list<int> $ids */
    public static function replace(Project $project, array $ids): void
    {
        $valid = IssueType::query()->whereIn('id', $ids)->pluck('id')->all();

        DB::transaction(function () use ($project, $valid): void {
            DB::table('project_issue_types')->where('project_id', $project->id)->delete();
            foreach ($valid as $id) {
                DB::table('project_issue_types')->insert(['project_id' => $project->id, 'issue_type_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public static function seedFor(Project $project, string $boardType): void
    {
        $slugs = config("board_types.types.$boardType.issue_types");
        if (is_array($slugs)) {
            self::replace($project, IssueType::query()->whereIn('slug', $slugs)->pluck('id')->all());
        }
    }
}
