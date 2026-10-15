import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import RichTextEditor from './RichTextEditor';

describe('RichTextEditor', () => {
    it('renders the full toolbar', () => {
        render(<RichTextEditor value="" onChange={() => {}} />);

        for (const name of [
            'Bold',
            'Italic',
            'Strikethrough',
            'Underline',
            'Heading 1',
            'Heading 2',
            'Heading 3',
            'Bullet list',
            'Numbered list',
            'Checklist',
            'Quote',
            'Code block',
            'Undo',
            'Redo',
            'Clear formatting',
        ]) {
            expect(screen.getByTitle(name)).toBeInTheDocument();
        }
    });

    it('shows the given content and applies a heading toggle', () => {
        const onChange = vi.fn();
        const { container } = render(<RichTextEditor value="<p>hi</p>" onChange={onChange} />);

        expect(container.querySelector('.tiptap p')).toHaveTextContent('hi');

        fireEvent.click(screen.getByTitle('Heading 1'));

        expect(container.querySelector('.tiptap h1')).toHaveTextContent('hi');
    });

    it('syncs an external reset back into the document', () => {
        const onChange = vi.fn();
        const { container, rerender } = render(<RichTextEditor value="<p>hi</p>" onChange={onChange} />);

        expect(container.querySelector('.tiptap')).toHaveTextContent('hi');

        rerender(<RichTextEditor value="" onChange={onChange} />);

        expect(container.querySelector('.tiptap').textContent).toBe('');
    });
});
