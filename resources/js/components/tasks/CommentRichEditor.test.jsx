import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import CommentRichEditor from './CommentRichEditor';

describe('CommentRichEditor component', () => {
    it('renders toolbar buttons, textarea, and mode tabs', () => {
        render(
            <CommentRichEditor
                value=""
                onChange={() => {}}
                placeholder="Write a comment..."
                submitLabel="Post"
            />
        );

        expect(screen.getByPlaceholderText('Write a comment...')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^write$/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^preview$/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /^post$/i })).toBeInTheDocument();
        expect(screen.getByRole('toolbar')).toBeInTheDocument();
    });

    it('switches between Write and Preview tabs', () => {
        render(
            <CommentRichEditor
                value="**Bold content** and @User"
                onChange={() => {}}
                placeholder="Write a comment..."
            />
        );

        const previewTab = screen.getByRole('button', { name: /^preview$/i });
        fireEvent.click(previewTab);

        expect(screen.getByText('Bold content')).toBeInTheDocument();
        expect(screen.queryByPlaceholderText('Write a comment...')).not.toBeInTheDocument();

        const writeTab = screen.getByRole('button', { name: /^write$/i });
        fireEvent.click(writeTab);

        expect(screen.getByPlaceholderText('Write a comment...')).toBeInTheDocument();
    });

    it('inserts bold formatting on Bold button click', () => {
        const handleChange = vi.fn();
        render(
            <CommentRichEditor
                value=""
                onChange={handleChange}
                placeholder="Write a comment..."
            />
        );

        const boldBtn = screen.getByRole('button', { name: /bold/i });
        fireEvent.click(boldBtn);

        expect(handleChange).toHaveBeenCalledWith('**bold text**', expect.any(Number), expect.any(HTMLTextAreaElement));
    });

    it('wraps selected text with bold formatting', () => {
        const handleChange = vi.fn();
        render(
            <CommentRichEditor
                value="Hello world"
                onChange={handleChange}
                placeholder="Write a comment..."
            />
        );

        const textarea = screen.getByPlaceholderText('Write a comment...');
        textarea.setSelectionRange(0, 5); // Select "Hello"

        const boldBtn = screen.getByRole('button', { name: /bold/i });
        fireEvent.click(boldBtn);

        expect(handleChange).toHaveBeenCalledWith('**Hello** world', expect.any(Number), expect.any(HTMLTextAreaElement));
    });

    it('submits on Ctrl+Enter keyboard shortcut', () => {
        const handleSubmit = vi.fn();
        render(
            <CommentRichEditor
                value="Valid comment"
                onChange={() => {}}
                onSubmit={handleSubmit}
                placeholder="Write a comment..."
            />
        );

        const textarea = screen.getByPlaceholderText('Write a comment...');
        fireEvent.keyDown(textarea, { key: 'Enter', ctrlKey: true });

        expect(handleSubmit).toHaveBeenCalled();
    });

    describe('Enter inside a list', () => {
        function setup(value, caret = value.length) {
            const onChange = vi.fn();
            render(<CommentRichEditor value={value} onChange={onChange} placeholder="Write a comment..." />);
            const textarea = screen.getByPlaceholderText('Write a comment...');
            textarea.setSelectionRange(caret, caret);

            return { textarea, onChange };
        }

        it('continues a numbered list with the next number', () => {
            const { textarea, onChange } = setup('1. first');

            const notCancelled = fireEvent.keyDown(textarea, { key: 'Enter' });

            expect(notCancelled).toBe(false); // preventDefault: the browser must not add its own newline
            expect(onChange).toHaveBeenCalledWith('1. first\n2. ', 12, textarea);
        });

        it('continues bullets', () => {
            const { textarea, onChange } = setup('- a');

            fireEvent.keyDown(textarea, { key: 'Enter' });

            expect(onChange.mock.calls[0][0]).toBe('- a\n- ');
        });

        it('continues checklists with an unchecked box', () => {
            const { textarea, onChange } = setup('- [x] done');

            fireEvent.keyDown(textarea, { key: 'Enter' });

            expect(onChange.mock.calls[0][0]).toBe('- [x] done\n- [ ] ');
        });

        it('ends the list when Enter is pressed on an empty item', () => {
            const { textarea, onChange } = setup('1. a\n2. ');

            fireEvent.keyDown(textarea, { key: 'Enter' });

            expect(onChange.mock.calls[0][0]).toBe('1. a\n');
        });

        it('leaves a normal line to the browser', () => {
            const { textarea, onChange } = setup('just text');

            expect(fireEvent.keyDown(textarea, { key: 'Enter' })).toBe(true);
            expect(onChange).not.toHaveBeenCalled();
        });

        it('leaves Shift+Enter to the browser', () => {
            const { textarea, onChange } = setup('1. a');

            expect(fireEvent.keyDown(textarea, { key: 'Enter', shiftKey: true })).toBe(true);
            expect(onChange).not.toHaveBeenCalled();
        });
    });
});
