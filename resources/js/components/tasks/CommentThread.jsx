import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import UserChip from '../ui/UserChip';
import Avatar from '../ui/Avatar';
import Button from '../ui/Button';
import Alert from '../ui/Alert';
import ContentSkeleton from '../ui/ContentSkeleton';
import Timestamp from '../ui/Timestamp';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';
import { useConfirm, usePrompt } from '../ui/ConfirmDialog';
import api from '../../services/api';
import { caretRect } from '../../utils/caret';
import { onRealtimeReconnect } from '../../utils/realtimeAuth';
import HighlightedContent from '../ui/HighlightedContent';

export default function CommentThread({ task, projectId, isManager, targetCommentId = null }) {
    const { t } = useTranslation();
    const { user } = useAuth();
    const toast = useToast();
    const confirm = useConfirm();
    const prompt = usePrompt();
    const resolvedProjectId = projectId || task?.project_id || task?.project?.id;
    const [comments, setComments] = useState(null);
    const [error, setError] = useState(null);
    const [newComment, setNewComment] = useState('');
    const [replyTo, setReplyTo] = useState(null);
    const [replyText, setReplyText] = useState('');
    const [busy, setBusy] = useState(false);

    const [sugField, setSugField] = useState(null);
    const [suggestions, setSuggestions] = useState([]);
    const [sugActive, setSugActive] = useState(0);
    const [sugPos, setSugPos] = useState({ top: 0, left: 0, width: 300 });
    const [sugText, setSugText] = useState({ value: '', at: 0, caret: 0 });
    const sugAnchor = useRef(null);
    const sugMenuRef = useRef(null);
    const sugFieldRef = useRef(null);
    const debounceRef = useRef(null);

    const fetchComments = useCallback(() => {
        if (!resolvedProjectId || !task?.id) return;
        api.get(`/projects/${resolvedProjectId}/tasks/${task.id}/comments`)
            .then(({ data }) => {
                setComments(data.comments);
                setError(null);
            })
            .catch(() => setError(t('tms.comments.loadFailed')));
    }, [resolvedProjectId, task?.id, t]);

    useEffect(() => {
        fetchComments();
    }, [fetchComments]);

    useEffect(() => {
        if (!window.Echo || !resolvedProjectId) return undefined;
        const channel = window.Echo.private(`project.${resolvedProjectId}`);
        channel.listen('.comment.synced', fetchComments);
        const stopReconnect = onRealtimeReconnect(fetchComments);

        return () => {
            // Pass the handler: the channel is shared with the board, and a bare
            // stopListening would also drop other listeners of this event.
            channel.stopListening('.comment.synced', fetchComments);
            stopReconnect();
        };
    }, [fetchComments, resolvedProjectId]);

    useEffect(() => () => clearTimeout(debounceRef.current), []);

    const lastScrolledCommentRef = useRef(null);

    useEffect(() => {
        if (!comments || !targetCommentId || lastScrolledCommentRef.current === targetCommentId) return;

        const timer = setTimeout(() => {
            const el = document.getElementById(`comment-${targetCommentId}`);
            if (el) {
                lastScrolledCommentRef.current = targetCommentId;
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.classList.add('ring-2', 'ring-[var(--accent-ring)]', 'bg-[var(--accent-soft)]', 'transition-all', 'duration-500');
                setTimeout(() => {
                    el.classList.remove('ring-2', 'ring-[var(--accent-ring)]', 'bg-[var(--accent-soft)]');
                }, 3000);
            }
        }, 120);

        return () => clearTimeout(timer);
    }, [comments, targetCommentId]);

    const closeSuggestions = useCallback(() => {
        setSugField(null);
        setSuggestions([]);
    }, []);

    function openSuggestions(field, el, value, caret) {
        const before = value.slice(0, caret);
        const at = before.lastIndexOf('@');
        if (at === -1 || /\s/.test(before.slice(at + 1))) {
            if (sugField === field) closeSuggestions();
            return;
        }

        const query = before.slice(at + 1, caret);
        const box = el?.getBoundingClientRect?.() || { left: 8, top: 0, bottom: 0 };
        const caretAt = caretRect(el, at);
        const safeLeft = Number.isFinite(caretAt?.left) ? caretAt.left : box.left;
        const safeBottom = Number.isFinite(caretAt?.bottom) ? caretAt.bottom : (box.bottom || 0);
        const safeTop = Number.isFinite(caretAt?.top) ? caretAt.top : (box.top || 0);
        const width = Math.min(300, window.innerWidth - 16);
        const rowsHeight = 224;
        const left = Math.max(8, Math.min(safeLeft, window.innerWidth - width - 8));
        let top = safeBottom + 4;
        // If it would go below viewport, show above instead
        if (top + rowsHeight > window.innerHeight && safeTop - rowsHeight - 4 > 0) {
            top = safeTop - rowsHeight - 4;
        }
        setSugPos({ top, left, width });

        setSugField(field);
        setSugActive(0);
        setSugText({ value, at, caret });
        sugAnchor.current = el;
        sugFieldRef.current = field;

        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => {
            if (!resolvedProjectId) return;
            api.get(`/projects/${resolvedProjectId}/members/autocomplete`, { params: { q: query } })
                .then(({ data }) => {
                    if (sugFieldRef.current !== field) return;
                    setSuggestions(data.members || []);
                })
                .catch(() => {
                    if (sugFieldRef.current === field) setSuggestions([]);
                });
        }, 200);
    }

    useEffect(() => {
        if (!sugField || !sugAnchor.current) return undefined;

        function updatePosition() {
            if (!sugAnchor.current) return;
            const el = sugAnchor.current;
            const box = el.getBoundingClientRect();
            const caretAt = caretRect(el, sugText.at);
            const safeLeft = Number.isFinite(caretAt?.left) ? caretAt.left : box.left;
            const safeBottom = Number.isFinite(caretAt?.bottom) ? caretAt.bottom : (box.bottom || 0);
            const safeTop = Number.isFinite(caretAt?.top) ? caretAt.top : (box.top || 0);
            const width = Math.min(300, window.innerWidth - 16);
            const rowsHeight = 224;
            const left = Math.max(8, Math.min(safeLeft, window.innerWidth - width - 8));
            let top = safeBottom + 4;
            if (top + rowsHeight > window.innerHeight && safeTop - rowsHeight - 4 > 0) {
                top = safeTop - rowsHeight - 4;
            }
            setSugPos({ top, left, width });
        }

        window.addEventListener('scroll', updatePosition, true);
        window.addEventListener('resize', updatePosition);
        return () => {
            window.removeEventListener('scroll', updatePosition, true);
            window.removeEventListener('resize', updatePosition);
        };
    }, [sugField, sugText.at]);

    useEffect(() => {
        if (!sugField) return undefined;
        function handleOutsideClick(e) {
            if (sugMenuRef.current?.contains(e.target) || sugAnchor.current?.contains(e.target)) {
                return;
            }
            closeSuggestions();
        }
        document.addEventListener('mousedown', handleOutsideClick);
        return () => document.removeEventListener('mousedown', handleOutsideClick);
    }, [sugField, closeSuggestions]);

    function handleTyping(field, value, caret, el) {
        if (field === 'comment') setNewComment(value);
        else if (field === 'reply') setReplyText(value);
        else if (field === 'edit') {
            // For edit, we'll handle differently
        }

        openSuggestions(field, el, value, caret);
    }

    function selectSuggestion(member) {
        const { value, at, caret } = sugText;
        const next = `${value.slice(0, at)}@${member.name} ${value.slice(caret)}`;
        if (sugField === 'comment') setNewComment(next);
        else if (sugField === 'reply') setReplyText(next);
        else if (sugField === 'edit') {
            // For edit mode, we'll need to update the prompt - but easier to handle in place
            setNewComment(next); // fallback
        }

        closeSuggestions();
        requestAnimationFrame(() => {
            const el = sugAnchor.current;
            if (el) {
                el.focus();
                const pos = at + member.name.length + 2;
                el.setSelectionRange(pos, pos);
            }
        });
    }

    function handleKeyDown(e) {
        if (!sugField || suggestions.length === 0) {
            if (e.key === 'Escape') closeSuggestions();
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setSugActive((i) => (i + 1) % suggestions.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setSugActive((i) => (i - 1 + suggestions.length) % suggestions.length);
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            selectSuggestion(suggestions[sugActive]);
        } else if (e.key === 'Escape') {
            closeSuggestions();
        }
    }

    function submitComment(e) {
        e.preventDefault();
        const body = { comment: newComment.trim() };
        setBusy(true);
        api.post(`/projects/${resolvedProjectId}/tasks/${task.id}/comments`, body)
            .then(({ data }) => {
                setNewComment('');
                fetchComments();
                if (data.truncated_mentions) {
                    toast.warning(t('tms.comments.truncated'));
                }
            })
            .catch(() => {})
            .finally(() => setBusy(false));
    }

    function submitReply(e, parentId) {
        e.preventDefault();
        const body = { comment: replyText.trim(), parent_id: parentId };
        setBusy(true);
        api.post(`/projects/${resolvedProjectId}/tasks/${task.id}/comments`, body)
            .then(({ data }) => {
                setReplyText('');
                setReplyTo(null);
                fetchComments();
                if (data.truncated_mentions) {
                    toast.warning(t('tms.comments.truncated'));
                }
            })
            .catch(() => {})
            .finally(() => setBusy(false));
    }

    async function editComment(id, currentComment = '') {
        const next = await prompt({
            title: t('tms.comments.editTitle'),
            message: t('tms.comments.editMessage'),
            defaultValue: currentComment,
            placeholder: t('tms.comments.editPlaceholder'),
            multiline: true,
            required: true,
            confirmLabel: t('tms.comments.editConfirm'),
            mention: true,
            projectId: resolvedProjectId,
        });
        if (!next || !next.trim()) return;
        api.put(`/projects/${resolvedProjectId}/tasks/${task.id}/comments/${id}`, { comment: next.trim() })
            .then(fetchComments)
            .catch(() => {});
    }

    async function deleteComment(id) {
        if (!(await confirm({ title: t('tms.comments.deleteTitle'), message: t('tms.comments.deleteMessage'), variant: 'danger' }))) return;
        api.delete(`/projects/${resolvedProjectId}/tasks/${task.id}/comments/${id}`)
            .then(fetchComments)
            .catch(() => {});
    }

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!comments) {
        return (
            <ContentSkeleton size="md" />
        );
    }

    return (
        <div className="space-y-4">
            <form onSubmit={submitComment} className="relative flex gap-2">
                <textarea
                    rows="2"
                    className="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-[var(--accent)] focus:outline-none"
                    placeholder={t('tms.comments.composerPlaceholder')}
                    value={newComment}
                    onChange={(e) => handleTyping('comment', e.target.value, e.target.selectionStart, e.currentTarget)}
                    onKeyDown={handleKeyDown}
                />
                <Button type="submit" loading={busy} disabled={!newComment.trim()}>
                    {t('tms.comments.post')}
                </Button>
            </form>

            {comments.length === 0 ? (
                <p className="py-6 text-center text-sm text-gray-400">{t('tms.comments.empty')}</p>
            ) : (
                <ul className="space-y-3">
                    {comments.map((comment) => {
                        const own = comment.user?.id && comment.user.id === user?.id;
                        const canEdit = own || isManager;
                        return (
                            <li key={comment.id} id={`comment-${comment.id}`} className="rounded-lg border border-gray-100 px-3 py-2.5 transition-all">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="text-xs text-gray-400">
                                            <UserChip user={comment.user} name={comment.user?.name ?? t('tms.comments.unknown')} nameClassName="font-semibold text-gray-700" />
                                            {comment.edited_at ? t('tms.comments.edited') : ''} · <Timestamp date={comment.created_at} />
                                        </p>
                                        <p className="mt-1 text-sm text-gray-800">
                                            <HighlightedContent content={comment.comment} projectId={resolvedProjectId} />
                                        </p>
                                    </div>
                                    <div className="flex flex-shrink-0 items-center gap-1">
                                        {canEdit && (
                                            <>
                                                <button
                                                    type="button"
                                                    className="text-xs text-gray-400 transition hover:text-[var(--accent-soft-text)]"
                                                    onClick={() => editComment(comment.id, comment.comment)}
                                                >
                                                    {t('tms.comments.edit')}
                                                </button>
                                                <button
                                                    type="button"
                                                    className="text-xs text-gray-400 transition hover:text-red-600"
                                                    onClick={() => deleteComment(comment.id)}
                                                >
                                                    {t('tms.comments.delete')}
                                                </button>
                                            </>
                                        )}
                                        <button
                                            type="button"
                                            className="text-xs text-gray-400 transition hover:text-[var(--accent-soft-text)]"
                                            onClick={() => setReplyTo(replyTo === comment.id ? null : comment.id)}
                                        >
                                            {t('tms.comments.reply')}
                                        </button>
                                    </div>
                                </div>

                                {comment.replies?.length > 0 && (
                                    <ul className="mt-3 space-y-2 border-l-2 border-gray-100 pl-3">
                                        {comment.replies.map((reply) => (
                                            <li key={reply.id} id={`comment-${reply.id}`} className="rounded-md p-1.5 transition-all">
                                                <p className="text-xs text-gray-400">
                                                    <UserChip user={reply.user} name={reply.user?.name ?? t('tms.comments.unknown')} nameClassName="font-semibold text-gray-700" />
                                                    {reply.edited_at ? t('tms.comments.edited') : ''} · <Timestamp date={reply.created_at} />
                                                </p>
                                                <p className="mt-0.5 text-sm text-gray-700">
                                                    <HighlightedContent content={reply.comment} projectId={resolvedProjectId} />
                                                </p>
                                                {(reply.user?.id && reply.user.id === user?.id) || isManager ? (
                                                    <div className="mt-1 flex gap-2">
                                                        <button
                                                            type="button"
                                                            className="text-xs text-gray-400 transition hover:text-[var(--accent-soft-text)]"
                                                            onClick={() => editComment(reply.id, reply.comment)}
                                                        >
                                                            {t('tms.comments.edit')}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="text-xs text-gray-400 transition hover:text-red-600"
                                                            onClick={() => deleteComment(reply.id)}
                                                        >
                                                            {t('tms.comments.delete')}
                                                        </button>
                                                    </div>
                                                ) : null}
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {replyTo === comment.id && (
                                    <form onSubmit={(e) => submitReply(e, comment.id)} className="relative mt-3 flex gap-2">
                                        <textarea
                                            rows="2"
                                            className="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-[var(--accent)] focus:outline-none"
                                            placeholder={t('tms.comments.replyPlaceholder')}
                                            value={replyText}
                                            onChange={(e) => handleTyping('reply', e.target.value, e.target.selectionStart, e.currentTarget)}
                                            onKeyDown={handleKeyDown}
                                            autoFocus
                                        />
                                        <Button type="submit" loading={busy} disabled={!replyText.trim()}>
                                            {t('tms.comments.reply')}
                                        </Button>
                                    </form>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            {sugField && suggestions.length > 0 && createPortal(
                <div
                    ref={sugMenuRef}
                    className="fixed z-[9999] max-h-56 overflow-auto rounded-lg border border-gray-200 bg-white py-1 shadow-popover"
                    style={{ top: sugPos.top, left: sugPos.left, width: sugPos.width }}
                >
                    {suggestions.map((member, index) => (
                        <button
                            type="button"
                            key={member.id}
                            className={`flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm ${
                                index === sugActive ? 'bg-[var(--accent-soft)]' : 'hover:bg-gray-50'
                            }`}
                            onMouseDown={(e) => e.preventDefault()}
                            onMouseEnter={() => setSugActive(index)}
                            onClick={() => selectSuggestion(member)}
                        >
                            <Avatar name={member.name} photoUrl={member.photo_url} size="sm" />
                            <span className="min-w-0">
                                <span className="block truncate font-medium text-gray-800">{member.name}</span>
                                <span className="block truncate text-xs text-gray-400">{member.email}</span>
                            </span>
                        </button>
                    ))}
                </div>,
                document.body,
            )}
        </div>
    );
}