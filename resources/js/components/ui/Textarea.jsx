import { forwardRef } from 'react';
import { fieldClass } from './fieldStyles';

/** Styled <textarea>; wrap in FormField for label/hint/error. */
const Textarea = forwardRef(function Textarea({ className = '', rows = 4, ...props }, ref) {
    return (
        <textarea
            ref={ref}
            rows={rows}
            className={`${fieldClass} resize-y aria-[invalid=true]:border-danger-fg ${className}`}
            {...props}
        />
    );
});

export default Textarea;
