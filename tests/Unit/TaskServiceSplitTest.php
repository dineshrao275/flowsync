<?php

namespace Tests\Unit;

use App\Services\TaskService;
use App\Services\Tasks\TaskColumnOrder;
use App\Services\Tasks\TaskInputResolver;
use App\Services\Tasks\TaskPresenter;
use App\Services\Tasks\TaskReader;
use App\Services\Tasks\TaskWatchers;
use Tests\TestCase;

/**
 * P1.7: TaskService delegates to focused collaborators and keeps its public
 * surface (controllers, automation and HRMS callers are untouched).
 */
class TaskServiceSplitTest extends TestCase
{
    public function test_task_service_keeps_its_public_methods(): void
    {
        foreach (['create', 'update', 'delete', 'move', 'board', 'list', 'show', 'present', 'presentStatus',
            'addWatcher', 'removeWatcher', 'watchers'] as $method) {
            $this->assertTrue(method_exists(TaskService::class, $method), $method);
        }
    }

    public function test_collaborators_resolve_from_the_container(): void
    {
        foreach ([TaskInputResolver::class, TaskPresenter::class, TaskReader::class, TaskWatchers::class, TaskColumnOrder::class] as $class) {
            $this->assertInstanceOf($class, app($class));
        }

        $this->assertInstanceOf(TaskService::class, app(TaskService::class));
    }

    public function test_task_service_stays_under_the_class_ceiling(): void
    {
        $this->assertLessThanOrEqual(300, count(file(app_path('Services/TaskService.php'))));
    }
}
