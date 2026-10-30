import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useAuth } from './AuthContext';
import { productAvailable, productStorageKey, resolveProduct } from '../utils/product';

const ProductContext = createContext(null);

function readStoredProduct(key) {
    if (typeof window === 'undefined') return null;

    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeStoredProduct(key, product) {
    if (typeof window === 'undefined') return;

    try {
        window.localStorage.setItem(key, product);
    } catch {
        // Private mode — the shell still follows this session's navigation.
    }
}

/**
 * One source of truth for the active product shell.
 *
 * The URL owns product-specific routes; shared tenant routes (Administration,
 * Billing, …) keep whichever product the caller was using so a link does not
 * flip the whole sidebar. Entitlements always win: a stored/borrowed product
 * the tenant no longer has is clamped back to a reachable one.
 */
export function ProductProvider({ children }) {
    const { user, loading } = useAuth();
    const location = useLocation();
    const storageKey = productStorageKey(user);
    const [storedProduct, setStoredProduct] = useState(() => readStoredProduct(storageKey));

    useEffect(() => {
        setStoredProduct(readStoredProduct(storageKey));
    }, [storageKey]);

    const activeProduct = useMemo(
        () =>
            resolveProduct({
                pathname: location.pathname,
                stateProduct: location.state?.product,
                storedProduct,
                user,
            }),
        [location.pathname, location.state, storedProduct, user],
    );

    useEffect(() => {
        if (!user || loading) return;
        if (activeProduct === storedProduct) return;

        setStoredProduct(activeProduct);
        writeStoredProduct(storageKey, activeProduct);
    }, [activeProduct, storedProduct, storageKey, user, loading]);

    const switchProduct = useCallback(
        (product) => {
            if (!productAvailable(user, product)) return false;

            setStoredProduct(product);
            writeStoredProduct(storageKey, product);

            return true;
        },
        [storageKey, user],
    );

    const value = useMemo(
        () => ({
            activeProduct,
            switchProduct,
            isProductAvailable: (product) => productAvailable(user, product),
        }),
        [activeProduct, switchProduct, user],
    );

    return <ProductContext.Provider value={value}>{children}</ProductContext.Provider>;
}

export function useActiveProduct() {
    const context = useContext(ProductContext);
    if (!context) {
        throw new Error('useActiveProduct must be used within a ProductProvider');
    }

    return context;
}
