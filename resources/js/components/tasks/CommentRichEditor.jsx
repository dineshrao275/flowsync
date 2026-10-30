import { useCallback, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    TextB,
    TextItalic,
    TextStrikethrough,
    TextUnderline,
    TextH,
    Quotes,
    Code,
    ListBullets,
    ListNumbers,
    ListChecks,
    Link,
    At,
    PencilSimple,
    Eye,
} from '@phosphor-icons/react';
import Button from '../ui/Button';
import HighlightedContent from '../ui/HighlightedContent';

function ToolButton({ title, onClick, disabled = false, icon: Icon, label = null, shortcut = null }) {
    return (
        <button
            type="button"
            title={shortcut ? `${title} (${shortcut})` : title}
            aria-label={title}
            disabled={disabled}
            onClick={onClick}
            className="flex h-7 w-7 items-center justify-center rounded text-gray-600 transition hover:bg-gray-200/70 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-30 active:scale-95"
        >
            {Icon ? <Icon size={15} weight="bold" /> : <span className="text-xs font-bold">{label}</span>}
        </button>
    );
}

function Divider() {
    return <span className="mx-0.5 h-4 w-px shrink-0 bg-gray-200" aria-hidden="true" />;
}

/**
 * Rich-text editor for task comments & replies:
 * - Toolbar: bold, italic, strike, underline, headings, quotes, code, lists, checklists, links, mentions
 * - Write & Preview tabs
 * - Selection wrapping and keyboard shortcuts (Ctrl+B, Ctrl+I, Ctrl+U, Ctrl+K, Ctrl+Enter)
 * - Retains textarea accessibility, caret positioning and @ mention autocomplete
 */
export default function CommentRichEditor({
    value = '',
    onChange,
    onSubmit,
    onCancel = null,
    placeholder = '',
    submitLabel = 'Post',
    cancelLabel = 'Cancel',
    busy = false,
    autoFocus = false,
    onKeyDown = null,
    projectId = null,
    textareaRef = null,
    minRows = 3,
    disabled = false,
    className = '',
}) {
    const { t } = useTranslation();
    const [tab, setTab] = useState('write');
    const localRef = useRef(null);
    const activeRef = textareaRef || localRef;

    const applyFormat = useCallback(
        (type) => {
            if (tab !== 'write') setTab('write');
            const el = activeRef.current;
            if (!el) return;

            const start = el.selectionStart ?? 0;
            const end = el.selectionEnd ?? 0;
            const current = value || '';
            const selected = current.slice(start, end);

            let prefix = '';
            let suffix = '';
            let sampleText = '';

            switch (type) {
                case 'bold':
                    prefix = '**';
                    suffix = '**';
                    sampleText = 'bold text';
                    break;
                case 'italic':
                    prefix = '*';
                    suffix = '*';
                    sampleText = 'italic text';
                    break;
                case 'strike':
                    prefix = '~~';
                    suffix = '~~';
                    sampleText = 'strikethrough text';
                    break;
                case 'underline':
                    prefix = '<u>';
                    suffix = '</u>';
                    sampleText = 'underlined text';
                    break;
                case 'code':
                    prefix = '`';
                    suffix = '`';
                    sampleText = 'code';
                    break;
                case 'codeblock':
                    prefix = '```\n';
                    suffix = '\n```';
                    sampleText = 'code block';
                    break;
                case 'heading':
                    prefix = '### ';
                    sampleText = 'Heading';
                    break;
                case 'quote':
                    prefix = '> ';
                    sampleText = 'Quote';
                    break;
                case 'bullet':
                    prefix = '- ';
                    sampleText = 'List item';
                    break;
                case 'number':
                    prefix = '1. ';
                    sampleText = 'List item';
                    break;
                case 'task':
                    prefix = '- [ ] ';
                    sampleText = 'Task item';
                    break;
                case 'link':
                    prefix = '[';
                    suffix = '](https://example.com)';
                    sampleText = 'link text';
                    break;
                case 'mention':
                    prefix = '@';
                    break;
                default:
                    return;
            }

            let nextValue;
            let selStart;
            let selEnd;

            if (type === 'mention') {
                nextValue = current.slice(0, start) + '@' + current.slice(end);
                selStart = start + 1;
                selEnd = start + 1;
            } else if (selected.length > 0) {
                nextValue = current.slice(0, start) + prefix + selected + suffix + current.slice(end);
                selStart = start + prefix.length;
                selEnd = selStart + selected.length;
            } else {
                nextValue = current.slice(0, start) + prefix + sampleText + suffix + current.slice(end);
                selStart = start + prefix.length;
                selEnd = selStart + sampleText.length;
            }

            onChange?.(nextValue, selEnd, el);

            requestAnimationFrame(() => {
                el.focus();
                el.setSelectionRange(selStart, selEnd);
            });
        },
        [activeRef, onChange, tab, value],
    );

    function handleInternalKeyDown(e) {
        if (onKeyDown) {
            onKeyDown(e);
            if (e.defaultPrevented) return;
        }

        if ((e.ctrlKey || e.metaKey) && !e.shiftKey && !e.altKey) {
            if (e.key === 'b' || e.key === 'B') {
                e.preventDefault();
                applyFormat('bold');
                return;
            }
            if (e.key === 'i' || e.key === 'I') {
                e.preventDefault();
                applyFormat('italic');
                return;
            }
            if (e.key === 'u' || e.key === 'U') {
                e.preventDefault();
                applyFormat('underline');
                return;
            }
            if (e.key === 'k' || e.key === 'K') {
                e.preventDefault();
                applyFormat('link');
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                if (value?.trim() && !busy && onSubmit) {
                    onSubmit(e);
                }
            }
        }
    }

    return (
        <div
            className={`overflow-hidden rounded-xl border border-gray-300 bg-white shadow-xs transition-all focus-within:border-[var(--accent)] focus-within:ring-2 focus-within:ring-[var(--accent-ring)] ${
                disabled ? 'opacity-70 bg-gray-50' : ''
            } ${className}`}
        >
            {/* Header Toolbar */}
            <div className="flex flex-wrap items-center justify-between gap-1 border-b border-gray-200 bg-gray-50/80 px-2.5 py-1.5" role="toolbar" aria-label={t('admin.editor.toolbar')}>
                <div className="flex flex-wrap items-center gap-0.5">
                    <ToolButton title={t('admin.editor.bold')} shortcut="Ctrl+B" onClick={() => applyFormat('bold')} icon={TextB} disabled={disabled} />
                    <ToolButton title={t('admin.editor.italic')} shortcut="Ctrl+I" onClick={() => applyFormat('italic')} icon={TextItalic} disabled={disabled} />
                    <ToolButton title={t('admin.editor.strikethrough')} onClick={() => applyFormat('strike')} icon={TextStrikethrough} disabled={disabled} />
                    <ToolButton title={t('admin.editor.underline')} shortcut="Ctrl+U" onClick={() => applyFormat('underline')} icon={TextUnderline} disabled={disabled} />
                    <Divider />
                    <ToolButton title={t('admin.editor.heading2')} onClick={() => applyFormat('heading')} icon={TextH} disabled={disabled} />
                    <ToolButton title={t('admin.editor.quote')} onClick={() => applyFormat('quote')} icon={Quotes} disabled={disabled} />
                    <ToolButton title="Inline Code" onClick={() => applyFormat('code')} icon={Code} disabled={disabled} />
                    <ToolButton title={t('admin.editor.codeBlock')} onClick={() => applyFormat('codeblock')} label="{ }" disabled={disabled} />
                    <Divider />
                    <ToolButton title={t('admin.editor.bulletList')} onClick={() => applyFormat('bullet')} icon={ListBullets} disabled={disabled} />
                    <ToolButton title={t('admin.editor.numberedList')} onClick={() => applyFormat('number')} icon={ListNumbers} disabled={disabled} />
                    <ToolButton title={t('admin.editor.checklist')} onClick={() => applyFormat('task')} icon={ListChecks} disabled={disabled} />
                    <Divider />
                    <ToolButton title="Insert Link" shortcut="Ctrl+K" onClick={() => applyFormat('link')} icon={Link} disabled={disabled} />
                    <ToolButton title="Mention Team Member" onClick={() => applyFormat('mention')} icon={At} disabled={disabled} />
                </div>

                {/* Write / Preview Tab Switcher */}
                <div className="flex items-center rounded-lg bg-gray-200/70 p-0.5 text-xs font-medium">
                    <button
                        type="button"
                        onClick={() => setTab('write')}
                        className={`flex items-center gap-1 rounded-md px-2 py-0.5 transition ${
                            tab === 'write' ? 'bg-white text-gray-900 shadow-xs' : 'text-gray-600 hover:text-gray-900'
                        }`}
                    >
                        <PencilSimple size={13} weight="bold" />
                        <span>Write</span>
                    </button>
                    <button
                        type="button"
                        onClick={() => setTab('preview')}
                        className={`flex items-center gap-1 rounded-md px-2 py-0.5 transition ${
                            tab === 'preview' ? 'bg-white text-gray-900 shadow-xs' : 'text-gray-600 hover:text-gray-900'
                        }`}
                    >
                        <Eye size={13} weight="bold" />
                        <span>Preview</span>
                    </button>
                </div>
            </div>

            {/* Editor Body */}
            {tab === 'write' ? (
                <textarea
                    ref={activeRef}
                    rows={minRows}
                    disabled={disabled}
                    autoFocus={autoFocus}
                    className="w-full resize-y border-0 bg-transparent px-3 py-2.5 text-sm text-gray-800 focus:outline-none focus:ring-0 placeholder:text-gray-400"
                    placeholder={placeholder}
                    value={value}
                    onChange={(e) => onChange?.(e.target.value, e.target.selectionStart, e.currentTarget)}
                    onKeyDown={handleInternalKeyDown}
                />
            ) : (
                <div className="min-h-[88px] max-h-96 overflow-y-auto bg-gray-50/40 p-3">
                    {value?.trim() ? (
                        <HighlightedContent content={value} projectId={projectId} />
                    ) : (
                        <p className="py-4 text-center text-xs italic text-gray-400">
                            Nothing to preview yet. Switch to Write mode to compose your comment.
                        </p>
                    )}
                </div>
            )}

            {/* Footer Action Bar */}
            <div className="flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 bg-gray-50/50 px-3 py-2">
                <span className="text-[11px] font-medium text-gray-400">
                    Markdown supported · Type <span className="font-semibold text-gray-600">@</span> to mention · <kbd className="rounded bg-gray-200/60 px-1 py-0.5 font-mono text-[10px] text-gray-600">Ctrl+Enter</kbd> to post
                </span>
                <div className="flex items-center gap-2">
                    {onCancel && (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={onCancel}
                            className="rounded-lg px-2.5 py-1 text-xs font-medium text-gray-500 transition hover:bg-gray-100 hover:text-gray-800 disabled:opacity-50"
                        >
                            {cancelLabel}
                        </button>
                    )}
                    <Button
                        type="button"
                        size="sm"
                        loading={busy}
                        disabled={disabled || !value?.trim() || busy}
                        onClick={onSubmit}
                    >
                        {submitLabel}
                    </Button>
                </div>
            </div>
        </div>
    );
}
