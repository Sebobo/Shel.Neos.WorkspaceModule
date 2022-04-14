import React, { createContext, ReactNode, useContext } from 'react';

type ProviderProps = {
    children: ReactNode;
    translate: (id: string, fallback?: string, parameters?: Record<string, string | number> | string[]) => string;
};

type ProviderValues = {
    translate: (id: string, fallback?: string, parameters?: Record<string, string | number> | string[]) => string;
};

export const IntlContext = createContext(null);
export const useIntl = (): ProviderValues => useContext(IntlContext);

export function IntlProvider({ children, translate }: ProviderProps) {
    return <IntlContext.Provider value={{ translate }}>{children}</IntlContext.Provider>;
}
