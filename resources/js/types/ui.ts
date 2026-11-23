export type Appearance = 'light' | 'dark' | 'sunrise' | 'joy' | 'vice' | 'system';
export type ResolvedAppearance = 'light' | 'dark' | 'sunrise' | 'joy' | 'vice';

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};
