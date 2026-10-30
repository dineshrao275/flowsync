import { useEffect } from 'react';
import { getAppName } from '../utils/appName';
import { usePageTitleContext } from '../context/PageTitleContext';

export default function usePageTitle(title) {
    const context = usePageTitleContext();
    const appName = getAppName();
    const effective = title || appName;

    useEffect(() => {
        document.title = effective === appName ? appName : `${effective} · ${appName}`;
        if (context) context.setTitle(effective);
    }, [effective, appName, context]);
}