import { useCallback, useEffect, useState } from 'react';
import api from '../../services/api';
import Button from '../ui/Button';
import Alert from '../ui/Alert';
import Spinner from '../ui/Spinner';
import { useAuth } from '../../context/AuthContext';

export default function CommentThread({ task, projectId, isManager }) {
    const { user } = useAuth();
    const [comments, setComments] = useState(null);
    const [error, setError] = useState(null);
    const [newComment, setNewComment] = useState('');
    const [replyTo, setReplyTo] = useState(null);
    const [replyText, setReplyText] = useState('');
    const [busy, setBusy] = useState(false);

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

    function submitComment(e) {
        e.preventDefault();
        const body = { comment: newComment.trim() };
        setBusy(true);
        api.post(`/projects/${projectId}/tasks/${task.id}/comments`, body)
            .then(() => {
                setNewComment('');
                fetchComments();
            })
            .catch(() => {})
            .finally(() => setBusy(false));
    }

    function submitReply(e, parentId) {
        e.preventDefault();
        const body = { comment: replyText.trim(), parent_id: parentId };
        setBusy(true);
        api.post(`/projects/${projectId}/tasks/${task.id}/comments`, body)
            .then(() => {
                setReplyText('');
                setReplyTo(null);
                fetchComments();
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
            <form onSubmit={submitComment} className="flex gap-2">
                <textarea
                    rows="2"
                    className="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none"
                    placeholder="Add a comment…"
                    value={newComment}
                    onChange={(e) => setNewComment(e.target.value)}
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
                                    <form onSubmit={(e) => submitReply(e, comment.id)} className="mt-3 flex gap-2">
                                        <textarea
                                            rows="2"
                                            className="flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none"
                                            placeholder="Write a reply…"
                                            value={replyText}
                                            onChange={(e) => setReplyText(e.target.value)}
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
        </div>
    );
}