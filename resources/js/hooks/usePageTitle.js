import { useEffect } from 'react';
import { usePageTitleContext } from '../context/PageTitleContext';

export default function usePageTitle(title) {
    const context = usePageTitleContext();
    const effective = title || 'FlowSync';

    useEffect(() => {
        document.title = effective === 'FlowSync' ? 'FlowSync' : `${effective} · FlowSync`;
        if (context) context.setTitle(effective);
    }, [effective, context]);
}