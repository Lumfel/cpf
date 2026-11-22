import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';
import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

type DocumentWithViewTransition = Document & {
    startViewTransition?: (updateCallback: () => void) => {
        finished: Promise<void>;
    };
};

let themeTransitionTimer: number | null = null;
let activeTransitionId = 0;

function applyThemeTransition(nextIsDark: boolean): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;
    const prefersReducedMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    if (prefersReducedMotion) {
        root.classList.toggle('dark', nextIsDark);
        return;
    }

    const doc = document as DocumentWithViewTransition;

    if (typeof doc.startViewTransition === 'function') {
        const transitionId = ++activeTransitionId;
        root.classList.add('cpf-theme-switching');

        try {
            const transition = doc.startViewTransition(() => {
                root.classList.toggle('dark', nextIsDark);
            });

            void transition.finished.finally(() => {
                if (transitionId === activeTransitionId) {
                    root.classList.remove('cpf-theme-switching');
                }
            });

            return;
        } catch {
            if (transitionId === activeTransitionId) {
                root.classList.remove('cpf-theme-switching');
            }
        }
    }

    if (themeTransitionTimer !== null) {
        window.clearTimeout(themeTransitionTimer);
    }

    root.classList.add('cpf-theme-fallback-transition');
    root.classList.toggle('dark', nextIsDark);

    themeTransitionTimer = window.setTimeout(() => {
        root.classList.remove('cpf-theme-fallback-transition');
        themeTransitionTimer = null;
    }, 240);
}

export function updateTheme(value: Appearance, animate = false): void {
    if (typeof window === 'undefined') {
        return;
    }

    const currentlyDark = document.documentElement.classList.contains('dark');
    let nextIsDark = false;

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        nextIsDark = mediaQueryList.matches;
    } else {
        nextIsDark = value === 'dark';
    }

    if (animate && currentlyDark !== nextIsDark) {
        applyThemeTransition(nextIsDark);
        return;
    }

    document.documentElement.classList.toggle('dark', nextIsDark);
}

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;

    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const getStoredAppearance = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return localStorage.getItem('appearance') as Appearance | null;
};

const prefersDark = (): boolean => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

const appearance = ref<Appearance>('system');
const systemPrefersDark = ref<boolean>(prefersDark());

const handleSystemThemeChange = () => {
    systemPrefersDark.value = prefersDark();
    const currentAppearance = getStoredAppearance();

    updateTheme(currentAppearance || 'system');
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    systemPrefersDark.value = prefersDark();

    // Initialize theme from saved preference or default to system...
    const savedAppearance = getStoredAppearance();
    appearance.value = savedAppearance || 'system';
    updateTheme(appearance.value);

    // Set up system theme change listener...
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance(): UseAppearanceReturn {
    onMounted(() => {
        systemPrefersDark.value = prefersDark();
        const savedAppearance = localStorage.getItem(
            'appearance',
        ) as Appearance | null;

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }
    });

    const resolvedAppearance = computed<ResolvedAppearance>(() => {
        if (appearance.value === 'system') {
            return systemPrefersDark.value ? 'dark' : 'light';
        }

        return appearance.value;
    });

    function updateAppearance(value: Appearance) {
        appearance.value = value;

        // Store in localStorage for client-side persistence...
        localStorage.setItem('appearance', value);

        // Store in cookie for SSR...
        setCookie('appearance', value);

        updateTheme(value, true);
    }

    return {
        appearance,
        resolvedAppearance,
        updateAppearance,
    };
}
