import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import TaskList from '@tiptap/extension-task-list';
import TaskItem from '@tiptap/extension-task-item';
import Underline from '@tiptap/extension-underline';
import Placeholder from '@tiptap/extension-placeholder';

function normalized(editor) {
    return editor.isEmpty ? '' : editor.getHTML();
}

function ToolButton({ title, active = false, disabled = false, onClick, label, labelClassName = '' }) {
    return (
        <button
            type="button"
            title={title}
            aria-label={title}
            aria-pressed={active}
            disabled={disabled}
            onClick={onClick}
            className={`flex h-7 min-w-7 items-center justify-center rounded px-1.5 text-sm transition disabled:cursor-not-allowed disabled:opacity-30 ${
                active ? 'bg-[var(--accent-soft)] font-semibold text-[var(--accent-soft-text)]' : 'text-gray-600 hover:bg-gray-100'
            } ${labelClassName}`}
        >
            {label}
        </button>
    );
}

function Divider() {
    return <span className="mx-0.5 h-5 w-px shrink-0 bg-gray-200" aria-hidden="true" />;
}

/**
 * Rich-text editor for task descriptions: bold/italic/strike/underline,
 * headings, bullet/ordered/checklist lists, quotes, code, undo/redo.
 *
 * Stores HTML (the `description` column is `text`, so length is unbounded).
 * An empty document normalizes to `''` — never `<p></p>` — so blank stays
 * blank in the database. External `value` changes (task switch, post-save
 * reset) flow back in; the equality guard keeps keystrokes from
 * round-tripping through the parent.
 */
export default function RichTextEditor({ value = '', onChange, disabled = false, placeholder = '', error = null }) {
    const { t } = useTranslation();
    const editor = useEditor({
        extensions: [
            StarterKit.configure({ heading: { levels: [1, 2, 3] } }),
            TaskList,
            TaskItem.configure({ nested: true }),
            Underline,
            Placeholder.configure({ placeholder }),
        ],
        content: value || '',
        editable: !disabled,
        editorProps: { attributes: { class: 'rich-text-editor-content' } },
        onUpdate: ({ editor: next }) => onChange?.(normalized(next)),
    });

    useEffect(() => {
        if (!editor) return;
        const next = value || '';
        if (next !== editor.getHTML() && !(next === '' && editor.isEmpty)) {
            editor.commands.setContent(next, { emitUpdate: false });
        }
    }, [editor, value]);

    useEffect(() => {
        editor?.setEditable(!disabled);
    }, [editor, disabled]);

    if (!editor) {
        return <div className="min-h-32 animate-pulse rounded-lg bg-gray-50" aria-hidden="true" />;
    }

    const run = (command) => (e) => {
        e.preventDefault();
        command();
    };

    return (
        <div>
            <div
                className={`overflow-hidden rounded-lg border bg-white transition ${
                    error ? 'border-red-400' : 'border-gray-300 focus-within:border-[var(--accent)]'
                } ${disabled ? 'bg-gray-50 opacity-75' : ''}`}
            >
                {!disabled && (
                    <div className="flex flex-wrap items-center gap-0.5 border-b border-gray-200 bg-gray-50/80 px-2 py-1.5" role="toolbar" aria-label={t('admin.editor.toolbar')}>
                        <ToolButton title={t('admin.editor.bold')} active={editor.isActive('bold')} onClick={run(() => editor.chain().focus().toggleBold().run())} label="B" labelClassName="font-bold" />
                        <ToolButton title={t('admin.editor.italic')} active={editor.isActive('italic')} onClick={run(() => editor.chain().focus().toggleItalic().run())} label="I" labelClassName="italic" />
                        <ToolButton title={t('admin.editor.strikethrough')} active={editor.isActive('strike')} onClick={run(() => editor.chain().focus().toggleStrike().run())} label="S" labelClassName="line-through" />
                        <ToolButton title={t('admin.editor.underline')} active={editor.isActive('underline')} onClick={run(() => editor.chain().focus().toggleUnderline().run())} label="U" labelClassName="underline" />
                        <Divider />
                        <ToolButton title={t('admin.editor.heading1')} active={editor.isActive('heading', { level: 1 })} onClick={run(() => editor.chain().focus().toggleHeading({ level: 1 }).run())} label="H1" labelClassName="text-xs font-bold" />
                        <ToolButton title={t('admin.editor.heading2')} active={editor.isActive('heading', { level: 2 })} onClick={run(() => editor.chain().focus().toggleHeading({ level: 2 }).run())} label="H2" labelClassName="text-xs font-bold" />
                        <ToolButton title={t('admin.editor.heading3')} active={editor.isActive('heading', { level: 3 })} onClick={run(() => editor.chain().focus().toggleHeading({ level: 3 }).run())} label="H3" labelClassName="text-xs font-bold" />
                        <ToolButton title={t('admin.editor.paragraph')} active={editor.isActive('paragraph')} onClick={run(() => editor.chain().focus().setParagraph().run())} label="¶" />
                        <Divider />
                        <ToolButton title={t('admin.editor.bulletList')} active={editor.isActive('bulletList')} onClick={run(() => editor.chain().focus().toggleBulletList().run())} label="≡" />
                        <ToolButton title={t('admin.editor.numberedList')} active={editor.isActive('orderedList')} onClick={run(() => editor.chain().focus().toggleOrderedList().run())} label="1≡" labelClassName="text-xs" />
                        <ToolButton title={t('admin.editor.checklist')} active={editor.isActive('taskList')} onClick={run(() => editor.chain().focus().toggleTaskList().run())} label="☑" />
                        <Divider />
                        <ToolButton title={t('admin.editor.quote')} active={editor.isActive('blockquote')} onClick={run(() => editor.chain().focus().toggleBlockquote().run())} label="❝" />
                        <ToolButton title={t('admin.editor.codeBlock')} active={editor.isActive('codeBlock')} onClick={run(() => editor.chain().focus().toggleCodeBlock().run())} label="</>" labelClassName="font-mono text-xs" />
                        <ToolButton title={t('admin.editor.horizontalLine')} onClick={run(() => editor.chain().focus().setHorizontalRule().run())} label="―" />
                        <Divider />
                        <ToolButton title={t('admin.editor.undo')} disabled={!editor.can().undo()} onClick={run(() => editor.chain().focus().undo().run())} label="↶" />
                        <ToolButton title={t('admin.editor.redo')} disabled={!editor.can().redo()} onClick={run(() => editor.chain().focus().redo().run())} label="↷" />
                        <ToolButton title={t('admin.editor.clearFormatting')} onClick={run(() => editor.chain().focus().clearNodes().unsetAllMarks().run())} label="✕" labelClassName="text-xs" />
                    </div>
                )}
                <EditorContent editor={editor} />
            </div>
            {error && <p className="mt-1.5 text-sm text-red-600">{error}</p>}
        </div>
    );
}
