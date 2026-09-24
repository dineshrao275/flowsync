import { createContext, useCallback, useContext, useMemo, useState } from 'react';

const BreadcrumbContext = createContext(null);

export function BreadcrumbProvider({ children }) {
    const [crumbs, setCrumbs] = useState([]);

    const value = useMemo(() => ({ crumbs, setCrumbs }), [crumbs]);

    return <BreadcrumbContext.Provider value={value}>{children}</BreadcrumbContext.Provider>;
}

export function useCrumbs() {
    return useContext(BreadcrumbContext);
}

export function useSetCrumbs() {
    const { setCrumbs } = useContext(BreadcrumbContext);
    return useCallback(setCrumbs, [setCrumbs]);
}