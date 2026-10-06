import { useCallback, useEffect, useRef, useState } from 'react';
import api from '../../services/api';
import Button from '../ui/Button';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';
import { useAuth } from '../../context/AuthContext';
import { useToast } from '../../context/ToastContext';

export default function CommentThread({ task, projectId, isManager }) {
    const { user } = useAuth();
    const toast = useToast();
    const [comments, setComments] = useState(null);
    const [error, setError] = useState(null);
    const [newComment, setNewComment] = useState('');
    const [replyTo, setReplyTo] = useState(null);
    const [replyText, setReplyText] = useState('');
    const [busy, setBusy] = useState(false);

    const [sugField, setSugField] = useState(null);
    const [suggestions, setSuggestions] = useState([]);
    const [sugActive, setSugActive] = useState(0);
    const [sugPos, setSugPos] = useState({ top: 0, left: 0 });
    const [sugText, setSugText] = useState({ value: '', at: 0, caret: 0 });
    const sugAnchor = useRef(null);
    const sugFieldRef = useRef(null);
    const debounceRef = useRef(null);

    const fetchComments = useCallback(() => {
        api.get(`/projects/${projectId}/tasks/${task.id}/comments`)
            .then(({ data }) => {
                setComments(data.comments);
                setError(null);
            })
            .catch(() => setError('Could not load comments.'));
    }, [projectId, task.id]);

    useEffect(() => {
        fetchComments();
    }, [fetchComments]);

    useEffect(() => {
        if (!window.Echo) return undefined;
        const channel = window.Echo.private(`project.${projectId}`);
        channel.listen('.comment.synced', fetchComments);
        return () => {
            channel.stopListening('.comment.synced');
        };
    }, [fetchComments, projectId]);

    useEffect(() => () => clearTimeout(debounceRef.current), []);

    function closeSuggestions() {
        setSugField(null);
        setSuggestions([]);
    }

    function openSuggestions(field, el, value, caret) {
        const before = value.slice(0, caret);
        const at = before.lastIndexOf('@');
        if (at === -1 || /\s/.test(before.slice(at + 1))) {
            if (sugField === field) closeSuggestions();
            return;
        }

        const query = before.slice(at + 1, caret);
        const rect = el.getBoundingClientRect();

        setSugField(field);
        setSugActive(0);
        setSugText({ value, at, caret });
        setSugPos({ top: rect.bottom + 4, left: rect.left, width: Math.max(240, Math.min(320, rect.width)) });
        sugAnchor.current = el;
        sugFieldRef.current = field;

        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => {
            api.get(`/projects/${projectId}/members/autocomplete`, { params: { q: query } })
                .then(({ data }) => {
                    if (sugFieldRef.current !== field) return;
                    setSuggestions(data.members);
                })
                .catch(() => {
                    if (sugFieldRef.current === field) setSuggestions([]);
                });
        }, 200);
    }

    function handleTyping(field, value, caret, el) {
        if (field === 'comment') setNewComment(value);
        else setReplyText(value);

        openSuggestions(field, el, value, caret);
    }

    function selectSuggestion(member) {
        const { value, at, caret } = sugText;
        const next = `${value.slice(0, at)}@${member.name} ${value.slice(caret)}`;
        if (sugField === 'comment') setNewComment(next);
        else setReplyText(next);

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
        api.post(`/projects/${projectId}/tasks/${task.id}/comments`, body)
            .then(({ data }) => {
                setNewComment('');
                fetchComments();
                if (data.truncated_mentions) {
                    toast.warning('Some mentioned people were skipped — comments can notify up to 20.');
                }
            })
            .catch(() => {})
            .finally(() => setBusy(false));
    }

    function submitReply(e, parentId) {
        e.preventDefault();
        const body = { comment: replyText.trim(), parent_id: parentId };
        setBusy(true);
        api.post(`/projects/${projectId}/tasks/${task.id}/comments`, body)
            .then(({ data }) => {
                setReplyText('');
                setReplyTo(null);
                fetchComments();
                if (data.truncated_mentions) {
                    toast.warning('Some mentioned people were skipped — comments can notify up to 20.');
                }
            })
            .catch(() => {})
            .finally(() => setBusy(false));
    }

    function editComment(id) {
        const next = window.prompt('Edit comment:');
        if (!next) return;
        api.put(`/projects/${projectId}/tasks/${task.id}/comments/${id}`, { comment: next.trim() })
            .then(fetchComments)
            .catch(() => {});
    }

    function deleteComment(id) {
        if (!window.confirm('Delete this comment?')) return;
        api.delete(`/projects/${projectId}/tasks/${task.id}/comments/${id}`)
            .then(fetchComments)
            .catch(() => {});
    }

    if (error) {
        return <Alert>{error}</Alert>;
    }

    if (!comments) {
        return (
            <div className="flex justify-center py-10">
                <Spinner />
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <form onSubmit={submitComment} className="relative flex gap-2">
                <textarea
                    rows="2"
                    className="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none"
                    placeholder="Add a comment…  (type @ to mention someone)"
                    value={newComment}
                    onChange={(e) => handleTyping('comment', e.target.value, e.target.selectionStart, e.currentTarget)}
                    onKeyDown={handleKeyDown}
                />
                <Button type="submit" loading={busy} disabled={!newComment.trim()}>
                    Post
                </Button>
            </form>

            {comments.length === 0 ? (
                <p className="py-6 text-center text-sm text-gray-400">No comments yet.</p>
            ) : (
                <ul className="space-y-3">
                    {comments.map((comment) => {
                        const own = comment.user?.id && comment.user.id === user?.id;
                        const canEdit = own || isManager;
                        return (
                            <li key={comment.id} className="rounded-lg border border-gray-100 px-3 py-2.5">
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="text-xs text-gray-400">
                                            <span className="font-semibold text-gray-700">{comment.user?.name ?? 'Unknown'}</span>
                                            {comment.edited_at ? ' · edited' : ''} · {new Date(comment.created_at).toLocaleString()}
                                        </p>
                                        <p className="mt-1 whitespace-pre-wrap text-sm text-gray-800">{comment.comment}</p>
                                    </div>
                                    <div className="flex flex-shrink-0 items-center gap-1">
                                        {canEdit && (
                                            <>
                                                <button
                                                    type="button"
                                                    className="text-xs text-gray-400 transition hover:text-indigo-600"
                                                    onClick={() => editComment(comment.id)}
                                                >
                                                    Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    className="text-xs text-gray-400 transition hover:text-red-600"
                                                    onClick={() => deleteComment(comment.id)}
                                                >
                                                    Delete
                                                </button>
                                            </>
                                        )}
                                        <button
                                            type="button"
                                            className="text-xs text-gray-400 transition hover:text-indigo-600"
                                            onClick={() => setReplyTo(replyTo === comment.id ? null : comment.id)}
                                        >
                                            Reply
                                        </button>
                                    </div>
                                </div>

                                {comment.replies?.length > 0 && (
                                    <ul className="mt-3 space-y-2 border-l-2 border-gray-100 pl-3">
                                        {comment.replies.map((reply) => (
                                            <li key={reply.id}>
                                                <p className="text-xs text-gray-400">
                                                    <span className="font-semibold text-gray-700">{reply.user?.name ?? 'Unknown'}</span>
                                                    {reply.edited_at ? ' · edited' : ''} · {new Date(reply.created_at).toLocaleString()}
                                                </p>
                                                <p className="mt-0.5 whitespace-pre-wrap text-sm text-gray-700">{reply.comment}</p>
                                                {(reply.user?.id && reply.user.id === user?.id) || isManager ? (
                                                    <div className="mt-1 flex gap-2">
                                                        <button
                                                            type="button"
                                                            className="text-xs text-gray-400 transition hover:text-indigo-600"
                                                            onClick={() => editComment(reply.id)}
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="text-xs text-gray-400 transition hover:text-red-600"
                                                            onClick={() => deleteComment(reply.id)}
                                                        >
                                                            Delete
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
                                            className="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none"
                                            placeholder="Write a reply…"
                                            value={replyText}
                                            onChange={(e) => handleTyping('reply', e.target.value, e.target.selectionStart, e.currentTarget)}
                                            onKeyDown={handleKeyDown}
                                            autoFocus
                                        />
                                        <Button type="submit" loading={busy} disabled={!replyText.trim()}>
                                            Reply
                                        </Button>
                                    </form>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            {sugField && suggestions.length > 0 && (
                <div
                    className="fixed z-40 max-h-56 overflow-auto rounded-lg border border-gray-200 bg-white py-1 shadow-popover"
                    style={{ top: sugPos.top, left: sugPos.left, width: sugPos.width }}
                >
                    {suggestions.map((member, index) => (
                        <button
                            type="button"
                            key={member.id}
                            className={`flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm ${
                                index === sugActive ? 'bg-indigo-50' : 'hover:bg-gray-50'
                            }`}
                            onMouseEnter={() => setSugActive(index)}
                            onClick={() => selectSuggestion(member)}
                        >
                            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-100 text-[11px] font-semibold text-indigo-700">
                                {member.name
                                    .split(' ')
                                    .map((part) => part[0])
                                    .slice(0, 2)
                                    .join('')
                                    .toUpperCase()}
                            </span>
                            <span className="min-w-0">
                                <span className="block truncate font-medium text-gray-800">{member.name}</span>
                                <span className="block truncate text-xs text-gray-400">{member.email}</span>
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}