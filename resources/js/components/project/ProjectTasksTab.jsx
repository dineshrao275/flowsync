import { useTranslation } from 'react-i18next';
import { Columns } from '@phosphor-icons/react';
import Button from '../ui/Button';
import Spinner from '../ui/Spinner';
import Alert from '../ui/Alert';
import Icon from '../ui/Icon';
import Pagination from '../ui/Pagination';
import KanbanBoard from '../tasks/KanbanBoard';
import FiltersBar from '../tasks/FiltersBar';
import CalendarView from '../tasks/CalendarView';
import TimelineView from '../tasks/TimelineView';
import TaskTable from '../tasks/TaskTable';

export default function ProjectTasksTab({
    view,
    setView,
    setPage,
    swimlane,
    setSwimlane,
    loadingTasks,
    filters,
    setFilters,
    taskOptions,
    hasModule,
    jqlError,
    projectId,
    isProjectManager,
    boardPrefs,
    setShowColumns,
    canCreateTask,
    setShowCreate,
    tasksError,
    board,
    canMoveTask,
    canEditTask,
    openTask,
    handleDragEnd,
    quickAddTask,
    updateBoardPrefs,
    archived,
    setEditingColumn,
    moveColumn,
    removeColumn,
    statuses,
    listTasks,
    pagination,
}) {
    const { t } = useTranslation();

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center gap-3">
                <div className="flex items-center gap-3">
                    <div className="flex rounded-lg border border-gray-300 p-0.5">
                        <button
                            type="button"
                            onClick={() => {
                                setView('board');
                                setPage(1);
                            }}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${
                                view === 'board' ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'text-gray-600 hover:bg-gray-100'
                            }`}
                        >
                            {t('tms.project.board')}
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                setView('list');
                                setPage(1);
                            }}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${
                                view === 'list' ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'text-gray-600 hover:bg-gray-100'
                            }`}
                        >
                            {t('tms.project.list')}
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                setView('calendar');
                                setPage(1);
                            }}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${
                                view === 'calendar' ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'text-gray-600 hover:bg-gray-100'
                            }`}
                        >
                            {t('tms.calendar.view')}
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                setView('timeline');
                                setPage(1);
                            }}
                            className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${
                                view === 'timeline' ? 'bg-[var(--accent)] text-[var(--accent-contrast)]' : 'text-gray-600 hover:bg-gray-100'
                            }`}
                        >
                            {t('tms.timeline.view')}
                        </button>
                    </div>
                    {view === 'board' && (
                        <select
                            value={swimlane}
                            onChange={(e) => setSwimlane(e.target.value)}
                            className="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-600 shadow-sm focus:outline-none"
                            title={t('tms.project.swimlaneTitle')}
                        >
                            <option value="none">{t('tms.project.noSwimlanes')}</option>
                            <option value="assignee">{t('tms.project.swimlanesByAssignee')}</option>
                        </select>
                    )}
                    {loadingTasks && <Spinner className="h-5 w-5" />}
                </div>

                <FiltersBar
                    filters={filters}
                    options={{ ...taskOptions, sprints: hasModule('sprints'), savedFilters: hasModule('saved_filters'), jql: hasModule('jql') }}
                    jqlError={jqlError}
                    projectId={projectId}
                    canShareFilters={isProjectManager}
                    onChange={(next) => {
                        setFilters(next);
                        setPage(1);
                    }}
                />
                <div className="ml-auto flex items-center gap-2">
                    {view === 'board' && (
                        <Button type="button" variant="secondary" onClick={() => setShowColumns(true)}>
                            <Icon as={Columns} size="sm" className="mr-1.5" />
                            {t('tms.columns.button')}
                            {boardPrefs.hidden.length > 0 && (
                                <span className="ml-1.5 rounded-full bg-gray-200 px-1.5 text-xs font-semibold text-gray-700">
                                    {boardPrefs.hidden.length}
                                </span>
                            )}
                        </Button>
                    )}
                    {canCreateTask && (
                        <Button onClick={() => setShowCreate(true)}>{t('tms.project.newTask')}</Button>
                    )}
                </div>
            </div>

            {tasksError ? (
                <Alert>{tasksError}</Alert>
            ) : view === 'board' && board ? (
                <KanbanBoard
                    board={board}
                    canMove={canMoveTask}
                    canEdit={canEditTask}
                    canCreate={canCreateTask}
                    onOpen={openTask}
                    onDragEnd={handleDragEnd}
                    onQuickAdd={quickAddTask}
                    prefs={{ ...boardPrefs, save: updateBoardPrefs }}
                    manage={isProjectManager && !archived ? { onEdit: setEditingColumn, onMove: moveColumn, onDelete: removeColumn, order: statuses.map((s) => s.id) } : null}
                />
            ) : view === 'timeline' ? (
                <TimelineView projectId={projectId} filters={filters} onOpen={openTask} />
            ) : view === 'calendar' ? (
                <CalendarView projectId={projectId} filters={filters} canEdit={canEditTask} onOpen={openTask} />
            ) : view === 'list' ? (
                <div className="space-y-3">
                    <TaskTable tasks={listTasks} canEdit={canEditTask} onOpen={openTask} />
                    {pagination && (
                        <Pagination
                            page={pagination.current_page}
                            pages={pagination.last_page}
                            total={pagination.total}
                            onChange={setPage}
                        />
                    )}
                </div>
            ) : null}
        </div>
    );
}
