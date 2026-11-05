import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Bug, ArrowClockwise } from '@phosphor-icons/react';
import api from '../services/api';
import { useAuth } from '../context/AuthContext';
import { useToast } from '../context/ToastContext';
import { useSetCrumbs } from '../context/BreadcrumbContext';
import usePageTitle from '../hooks/usePageTitle';
import PageHeader from '../components/ui/PageHeader';
import Card from '../components/ui/Card';
import Button from '../components/ui/Button';
import Badge from '../components/ui/Badge';
import StatusPill from '../components/ui/StatusPill';
import Alert from '../components/ui/Alert';
import Tabs from '../components/ui/Tabs';
import Drawer from '../components/ui/Drawer';
import Pagination from '../components/ui/Pagination';
import ContentSkeleton from '../components/ui/ContentSkeleton';
import Select from '../components/ui/Select';
import Icon from '../components/ui/Icon';
import { Table, Th, Td, TableEmpty } from '../components/ui/Table';
import { formatDateTime } from '../utils/format';

const STATUSES = ['open', 'investigating', 'resolved', 'ignored'];
const SEVERITIES = ['warning', 'error', 'critical', 'alert', 'emergency'];
const SOURCES = ['backend', 'queue', 'console', 'frontend'];

const STATUS_TONE = { open: 'warning', investigating: 'info', resolved: 'success', ignored: 'neutral' };
const SEVERITY_TONE = { warning: 'warning', error: 'danger', critical: 'danger', alert: 'danger', emergency: 'danger' };

function Stat({ label, value, tone = '' }) {
    return (
        <div className="rounded-xl border border-line bg-surface px-4 py-3">
            <p className={`text-2xl font-bold ${tone || 'text-fg'}`}>{value}</p>
            <p className="text-caption text-fg-muted">{label}</p>
        </div>
    );
}

function Field({ label, children }) {
    return (
        <div>
            <dt className="text-caption uppercase tracking-wide text-fg-subtle">{label}</dt>
            <dd className="mt-0.5 break-words text-sm text-fg">{children || '—'}</dd>
        </div>
    );
}

/**
 * Super Admin application error console: one rollup row per error fingerprint,
 * with per-event occurrences, triage status and staff notes. Read access is
 * `errors.view`; status/notes need `errors.manage` (server-enforced).
 */
export default function SystemErrors() {
    const { t } = useTranslation();
    const { can } = useAuth();
    const toast = useToast();
    const canManage = can('errors.manage');

    usePageTitle(t('admin.errors.pageTitle'));
    useSetCrumbs([{ label: t('admin.platform.breadcrumb'), to: '/admin' }, { label: t('admin.errors.crumb') }]);

    const [items, setItems] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [summary, setSummary] = useState(null);
    const [status, setStatus] = useState('open');
    const [severity, setSeverity] = useState('');
    const [source, setSource] = useState('');
    const [module, setModule] = useState('');
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [selected, setSelected] = useState([]);
    const [bulkStatus, setBulkStatus] = useState('resolved');
    const [bulkSaving, setBulkSaving] = useState(false);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const [drawerOpen, setDrawerOpen] = useState(false);
    const [detail, setDetail] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [statusDraft, setStatusDraft] = useState('open');
    const [noteDraft, setNoteDraft] = useState('');
    const [saving, setSaving] = useState(false);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const [list, counts] = await Promise.all([
                api.get('/system/errors', {
                    params: {
                        status: status || undefined,
                        severity: severity || undefined,
                        source: source || undefined,
                        module: module || undefined,
                        q: q || undefined,
                        page,
                    },
                }),
                api.get('/system/errors/summary'),
            ]);
            setItems(list.data.items);
            setPagination(list.data.pagination);
            setSummary(counts.data);
            setError(null);
        } catch {
            setError(t('admin.errors.loadFailed'));
        } finally {
            setLoading(false);
        }
    }, [status, severity, source, module, q, page, t]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        setSelected([]);
    }, [status, severity, source, module, q, page]);

    async function openError(row) {
        setDrawerOpen(true);
        setDetail(null);
        setDetailLoading(true);
        setNoteDraft('');
        try {
            const { data } = await api.get(`/system/errors/${row.id}`);
            setDetail(data.error);
            setStatusDraft(data.error.status);
        } catch {
            toast.error(t('admin.errors.detailFailed'));
            setDrawerOpen(false);
        } finally {
            setDetailLoading(false);
        }
    }

    async function refreshDetail(id) {
        const { data } = await api.get(`/system/errors/${id}`);
        setDetail(data.error);
        setStatusDraft(data.error.status);
    }

    async function saveStatus() {
        if (!detail) return;
        setSaving(true);
        try {
            const { data } = await api.put(`/system/errors/${detail.id}`, {
                status: statusDraft,
                note: noteDraft.trim() || undefined,
            });
            toast.success(data.message);
            setNoteDraft('');
            await refreshDetail(detail.id);
            await load();
        } catch {
            toast.error(t('admin.errors.updateFailed'));
        } finally {
            setSaving(false);
        }
    }

    async function addNote() {
        if (!detail || !noteDraft.trim()) return;
        setSaving(true);
        try {
            const { data } = await api.post(`/system/errors/${detail.id}/notes`, { body: noteDraft.trim() });
            toast.success(data.message);
            setNoteDraft('');
            await refreshDetail(detail.id);
        } catch {
            toast.error(t('admin.errors.noteFailed'));
        } finally {
            setSaving(false);
        }
    }

    const pageIds = items.map((row) => row.id);
    const allSelected = pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const someSelected = selected.length > 0 && !allSelected;

    function toggleRow(id) {
        setSelected((prev) => (prev.includes(id) ? prev.filter((entry) => entry !== id) : [...prev, id]));
    }

    function toggleAll() {
        setSelected(allSelected ? [] : pageIds);
    }

    async function applyBulk() {
        if (selected.length === 0) return;
        setBulkSaving(true);
        try {
            const { data } = await api.post('/system/errors/bulk', {
                ids: selected,
                status: bulkStatus,
            });
            toast.success(t('admin.errors.bulkUpdated', { count: data.updated }));
            setSelected([]);
            await load();
        } catch {
            toast.error(t('admin.errors.bulkFailed'));
        } finally {
            setBulkSaving(false);
        }
    }

    const hasFilters = status !== 'open' || Boolean(severity || source || module || q);

    function clearFilters() {
        setStatus('open');
        setSeverity('');
        setSource('');
        setModule('');
        setQ('');
        setPage(1);
    }

    const tabs = [
        { key: 'open', label: t('admin.errors.status.open'), count: summary?.statuses?.open },
        { key: 'investigating', label: t('admin.errors.status.investigating'), count: summary?.statuses?.investigating },
        { key: 'resolved', label: t('admin.errors.status.resolved'), count: summary?.statuses?.resolved },
        { key: 'ignored', label: t('admin.errors.status.ignored'), count: summary?.statuses?.ignored },
        { key: 'all', label: t('admin.errors.status.all'), count: summary?.total },
    ];

    return (
        <div className="space-y-6">
            <PageHeader
                title={t('admin.errors.title')}
                description={t('admin.errors.subtitle')}
                actions={
                    <Button variant="secondary" onClick={load} disabled={loading}>
                        <Icon as={ArrowClockwise} size="sm" />
                        {t('admin.errors.refresh')}
                    </Button>
                }
            />

            {error && <Alert>{error}</Alert>}

            <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Stat label={t('admin.errors.cards.open')} value={summary?.open ?? '—'} tone={summary?.open ? 'text-warning' : ''} />
                <Stat label={t('admin.errors.cards.new24h')} value={summary?.new_24h ?? '—'} />
                <Stat label={t('admin.errors.cards.total')} value={summary?.total ?? '—'} />
                <Stat label={t('admin.errors.cards.resolved')} value={summary?.statuses?.resolved ?? '—'} tone="text-success" />
            </div>

            <Tabs
                label={t('admin.errors.statusFilter')}
                tabs={tabs}
                active={status || 'all'}
                onChange={(key) => {
                    setStatus(key === 'all' ? '' : key);
                    setPage(1);
                }}
            />

            <div className="flex flex-wrap items-center gap-2">
                <input
                        type="search"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={t('admin.errors.searchPlaceholder')}
                        className="w-64 rounded-lg border border-line-input bg-surface px-3 py-2 text-sm text-fg outline-none focus:border-[var(--accent)]"
                    />
                    <Select
                        value={severity}
                        onChange={(e) => { setSeverity(e.target.value); setPage(1); }}
                        aria-label={t('admin.errors.severity')}
                        className="rounded-lg border border-line-input bg-surface text-sm text-fg"
                    >
                        <option value="">{t('admin.errors.anySeverity')}</option>
                        {SEVERITIES.map((level) => (
                            <option key={level} value={level}>{t(`admin.errors.severityLevels.${level}`)}</option>
                        ))}
                    </Select>
                    <Select
                        value={source}
                        onChange={(e) => { setSource(e.target.value); setPage(1); }}
                        aria-label={t('admin.errors.source')}
                        className="rounded-lg border border-line-input bg-surface text-sm text-fg"
                    >
                        <option value="">{t('admin.errors.anySource')}</option>
                        {SOURCES.map((key) => (
                            <option key={key} value={key}>{t(`admin.errors.sources.${key}`)}</option>
                        ))}
                    </Select>
                    <Select
                        value={module}
                        onChange={(e) => { setModule(e.target.value); setPage(1); }}
                        aria-label={t('admin.errors.module')}
                        className="rounded-lg border border-line-input bg-surface text-sm text-fg"
                    >
                        <option value="">{t('admin.errors.anyModule')}</option>
                        {(summary?.modules ?? []).map((entry) => (
                            <option key={entry.module} value={entry.module}>{entry.module} ({entry.count})</option>
                        ))}
                    </Select>
                {hasFilters && (
                    <Button type="button" variant="ghost" onClick={clearFilters}>{t('admin.errors.clear')}</Button>
                )}
            </div>

            {selected.length > 0 && (
                <div className="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-surface px-4 py-3">
                    <span className="text-sm font-medium text-fg">
                        {t('admin.errors.selectedCount', { count: selected.length })}
                    </span>
                    <Select
                        value={bulkStatus}
                        onChange={(e) => setBulkStatus(e.target.value)}
                        aria-label={t('admin.errors.bulkStatus')}
                        className="w-48"
                    >
                        {STATUSES.map((key) => (
                            <option key={key} value={key}>{t(`admin.errors.status.${key}`)}</option>
                        ))}
                    </Select>
                    <Button type="button" onClick={applyBulk} disabled={bulkSaving}>
                        {t('admin.errors.bulkApply')}
                    </Button>
                    <Button type="button" variant="ghost" onClick={() => setSelected([])} disabled={bulkSaving}>
                        {t('admin.errors.bulkClear')}
                    </Button>
                </div>
            )}

            {loading ? (
                <ContentSkeleton size="lg" />
            ) : (
                <Table>
                    <thead>
                        <tr>
                            <Th>
                                <input
                                    type="checkbox"
                                    checked={allSelected}
                                    ref={(el) => { if (el) el.indeterminate = someSelected; }}
                                    onChange={toggleAll}
                                    aria-label={t('admin.errors.selectAll')}
                                    className="h-4 w-4 rounded border-line-input text-[var(--accent)]"
                                />
                            </Th>
                            <Th>{t('admin.errors.thError')}</Th>
                            <Th>{t('admin.errors.thModule')}</Th>
                            <Th>{t('admin.errors.thSeverity')}</Th>
                            <Th>{t('admin.errors.thStatus')}</Th>
                            <Th>{t('admin.errors.thSource')}</Th>
                            <Th align="right">{t('admin.errors.thOccurrences')}</Th>
                            <Th align="right">{t('admin.errors.thLastSeen')}</Th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.length === 0 ? (
                            <TableEmpty colSpan={8}>{t('admin.errors.empty')}</TableEmpty>
                        ) : (
                            items.map((row) => (
                                <tr
                                    key={row.id}
                                    className="cursor-pointer hover:bg-surface-sunken"
                                    onClick={() => openError(row)}
                                >
                                    <Td>
                                        <input
                                            type="checkbox"
                                            checked={selected.includes(row.id)}
                                            onClick={(e) => e.stopPropagation()}
                                            onChange={() => toggleRow(row.id)}
                                            aria-label={t('admin.errors.selectRow')}
                                            className="h-4 w-4 rounded border-line-input text-[var(--accent)]"
                                        />
                                    </Td>
                                    <Td>
                                        <div className="max-w-xl">
                                            <div className="truncate font-medium text-fg" title={row.message}>{row.message}</div>
                                            <div className="truncate text-caption text-fg-subtle">{row.exception_class || '—'}</div>
                                        </div>
                                    </Td>
                                    <Td><Badge tone="neutral">{row.module || 'unknown'}</Badge></Td>
                                    <Td><StatusPill tone={SEVERITY_TONE[row.severity] ?? 'neutral'} label={t(`admin.errors.severityLevels.${row.severity}`, row.severity)} /></Td>
                                    <Td>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <StatusPill tone={STATUS_TONE[row.status] ?? 'neutral'} label={t(`admin.errors.status.${row.status}`, row.status)} />
                                            {row.recurred && <Badge tone="danger">{t('admin.errors.recurred')}</Badge>}
                                        </div>
                                    </Td>
                                    <Td>{t(`admin.errors.sources.${row.source}`, row.source)}</Td>
                                    <Td align="right">{row.occurrences}</Td>
                                    <Td align="right"><span className="whitespace-nowrap text-caption text-fg-muted">{formatDateTime(row.last_seen_at)}</span></Td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </Table>
            )}

            {pagination && (
                <Pagination
                    page={pagination.current_page}
                    pages={pagination.last_page}
                    total={pagination.total}
                    onChange={setPage}
                />
            )}

            <Drawer
                open={drawerOpen}
                onClose={() => setDrawerOpen(false)}
                title={detail?.message ?? t('admin.errors.detailTitle')}
                subtitle={detail?.exception_class}
                allowFullscreen
            >
                {detailLoading || !detail ? (
                    <div className="p-6"><ContentSkeleton size="lg" /></div>
                ) : (
                    <div className="space-y-6 p-6">
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusPill tone={STATUS_TONE[detail.status] ?? 'neutral'} label={t(`admin.errors.status.${detail.status}`, detail.status)} />
                            <StatusPill tone={SEVERITY_TONE[detail.severity] ?? 'neutral'} label={t(`admin.errors.severityLevels.${detail.severity}`, detail.severity)} />
                            <Badge tone="neutral">{detail.module || 'unknown'}</Badge>
                            <Badge tone="info">{t(`admin.errors.sources.${detail.source}`, detail.source)}</Badge>
                            {detail.recurred && <Badge tone="danger">{t('admin.errors.recurred')}</Badge>}
                        </div>

                        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                            <Field label={t('admin.errors.fields.tenant')}>{detail.tenant ?? detail.tenant_id ?? t('admin.errors.platformWide')}</Field>
                            <Field label={t('admin.errors.fields.occurrences')}>{detail.occurrences}</Field>
                            <Field label={t('admin.errors.fields.firstSeen')}>{formatDateTime(detail.first_seen_at)}</Field>
                            <Field label={t('admin.errors.fields.lastSeen')}>{formatDateTime(detail.last_seen_at)}</Field>
                            <Field label={t('admin.errors.fields.resolvedAt')}>{formatDateTime(detail.resolved_at)}</Field>
                            <Field label={t('admin.errors.fields.requestId')}><span className="font-mono text-xs">{detail.request_id || '—'}</span></Field>
                            <Field label={t('admin.errors.fields.path')}>
                                {detail.method && <span className="font-mono text-xs">{detail.method} </span>}
                                <span className="font-mono text-xs">{detail.path || '—'}</span>
                                {detail.http_status && <> · {detail.http_status}</>}
                            </Field>
                            <Field label={t('admin.errors.fields.file')}>
                                <span className="font-mono text-xs">{detail.file ? `${detail.file}:${detail.line}` : '—'}</span>
                            </Field>
                            <Field label={t('admin.errors.fields.environment')}>{detail.environment || '—'}</Field>
                        </dl>

                        {canManage && (
                            <Card title={t('admin.errors.triageTitle')} subtitle={t('admin.errors.triageSubtitle')}>
                                <div className="space-y-3">
                                    <Select
                                        id="error-status"
                                        label={t('admin.errors.statusLabel')}
                                        value={statusDraft}
                                        onChange={(e) => setStatusDraft(e.target.value)}
                                    >
                                        {STATUSES.map((key) => (
                                            <option key={key} value={key}>{t(`admin.errors.status.${key}`)}</option>
                                        ))}
                                    </Select>
                                    <textarea
                                        value={noteDraft}
                                        onChange={(e) => setNoteDraft(e.target.value)}
                                        rows={3}
                                        maxLength={2000}
                                        placeholder={t('admin.errors.notePlaceholder')}
                                        className="w-full rounded-lg border border-line-input bg-surface px-3 py-2 text-sm text-fg outline-none focus:border-[var(--accent)]"
                                    />
                                    <div className="flex flex-wrap gap-2">
                                        <Button onClick={saveStatus} disabled={saving || statusDraft === detail.status && !noteDraft.trim()}>
                                            {t('admin.errors.saveStatus')}
                                        </Button>
                                        <Button variant="secondary" onClick={addNote} disabled={saving || !noteDraft.trim()}>
                                            {t('admin.errors.addNote')}
                                        </Button>
                                    </div>
                                </div>
                            </Card>
                        )}

                        <section>
                            <h3 className="mb-2 text-sm font-semibold text-fg">{t('admin.errors.stackTitle')}</h3>
                            <pre className="max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-surface-sunken p-3 text-xs text-fg-muted">
                                {detail.stack_trace || t('admin.errors.noStack')}
                            </pre>
                        </section>

                        {detail.context && Object.keys(detail.context).length > 0 && (
                            <section>
                                <h3 className="mb-2 text-sm font-semibold text-fg">{t('admin.errors.contextTitle')}</h3>
                                <pre className="max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-surface-sunken p-3 text-xs text-fg-muted">
                                    {JSON.stringify(detail.context, null, 2)}
                                </pre>
                            </section>
                        )}

                        <section>
                            <h3 className="mb-2 text-sm font-semibold text-fg">{t('admin.errors.occurrencesTitle')}</h3>
                            {detail.occurrences_preview.length === 0 ? (
                                <p className="text-sm text-fg-muted">{t('admin.errors.noOccurrences')}</p>
                            ) : (
                                <ul className="divide-y divide-line rounded-lg border border-line">
                                    {detail.occurrences_preview.map((occurrence) => (
                                        <li key={occurrence.id} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2 text-sm">
                                            <span className="text-fg">{formatDateTime(occurrence.occurred_at)}</span>
                                            <span className="font-mono text-xs text-fg-muted">{occurrence.method} {occurrence.path}</span>
                                            {occurrence.request_id && <span className="font-mono text-xs text-fg-subtle">{occurrence.request_id}</span>}
                                            {occurrence.tenant_id && <span className="text-xs text-fg-subtle">tenant {occurrence.tenant_id}</span>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section>
                            <h3 className="mb-2 text-sm font-semibold text-fg">{t('admin.errors.notesTitle')}</h3>
                            {detail.notes.length === 0 ? (
                                <p className="text-sm text-fg-muted">{t('admin.errors.noNotes')}</p>
                            ) : (
                                <ul className="space-y-2">
                                    {detail.notes.map((note) => (
                                        <li key={note.id} className="rounded-lg border border-line p-3">
                                            <div className="flex items-center justify-between gap-2 text-caption text-fg-subtle">
                                                <span>{note.author_name || t('admin.errors.unknownAuthor')}</span>
                                                <span>{formatDateTime(note.created_at)}</span>
                                            </div>
                                            <p className="mt-1 whitespace-pre-wrap text-sm text-fg">{note.body}</p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section>
                            <h3 className="mb-2 text-sm font-semibold text-fg">{t('admin.errors.historyTitle')}</h3>
                            {detail.history.length === 0 ? (
                                <p className="text-sm text-fg-muted">{t('admin.errors.noHistory')}</p>
                            ) : (
                                <ul className="space-y-2">
                                    {detail.history.map((entry) => (
                                        <li key={entry.id} className="rounded-lg border border-line p-3 text-sm">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge tone="neutral">{t(`admin.errors.status.${entry.from_status}`, entry.from_status || '—')}</Badge>
                                                <span aria-hidden="true">→</span>
                                                <Badge tone="info">{t(`admin.errors.status.${entry.to_status}`, entry.to_status)}</Badge>
                                                <span className="text-caption text-fg-subtle">
                                                    {entry.actor_name || t('admin.errors.unknownAuthor')} · {formatDateTime(entry.created_at)}
                                                </span>
                                            </div>
                                            {entry.note && <p className="mt-1 whitespace-pre-wrap text-fg-muted">{entry.note}</p>}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <p className="flex items-center gap-1.5 text-caption text-fg-subtle">
                            <Icon as={Bug} size="xs" />
                            {detail.fingerprint}
                        </p>
                    </div>
                )}
            </Drawer>
        </div>
    );
}
