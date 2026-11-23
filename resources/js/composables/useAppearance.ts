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

function isValidAppearance(value: string | null): value is Appearance {
    return (
        value === 'light' ||
        value === 'dark' ||
        value === 'sunrise' ||
        value === 'joy' ||
        value === 'vice' ||
        value === 'system'
    );
}

function getCurrentRootTheme(root: HTMLElement): ResolvedAppearance {
    if (root.classList.contains('dark')) {
        return 'dark';
    }
    if (root.classList.contains('theme-sunrise')) {
        return 'sunrise';
    }
    if (root.classList.contains('theme-joy')) {
        return 'joy';
    }
    if (root.classList.contains('theme-vice')) {
        return 'vice';
    }
    return 'light';
}

function applyThemeClasses(root: HTMLElement, nextTheme: ResolvedAppearance): void {
    root.classList.toggle('dark', nextTheme === 'dark');
    root.classList.toggle('theme-sunrise', nextTheme === 'sunrise');
    root.classList.toggle('theme-joy', nextTheme === 'joy');
    root.classList.toggle('theme-vice', nextTheme === 'vice');
}

function applyThemeTransition(nextTheme: ResolvedAppearance): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    const root = document.documentElement;
    const prefersReducedMotion = window.matchMedia(
        '(prefers-reduced-motion: reduce)',
    ).matches;

    if (prefersReducedMotion) {
        applyThemeClasses(root, nextTheme);
        return;
    }

    const doc = document as DocumentWithViewTransition;

    if (typeof doc.startViewTransition === 'function') {
        const transitionId = ++activeTransitionId;
        root.classList.add('cpf-theme-switching');

        try {
            const transition = doc.startViewTransition(() => {
                applyThemeClasses(root, nextTheme);
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
    applyThemeClasses(root, nextTheme);

    themeTransitionTimer = window.setTimeout(() => {
        root.classList.remove('cpf-theme-fallback-transition');
        themeTransitionTimer = null;
    }, 240);
}

export function updateTheme(value: Appearance, animate = false): void {
    if (typeof window === 'undefined') {
        return;
    }

    const root = document.documentElement;
    const currentTheme = getCurrentRootTheme(root);
    let nextTheme: ResolvedAppearance = 'light';

    if (value === 'system') {
        const mediaQueryList = window.matchMedia(
            '(prefers-color-scheme: dark)',
        );
        nextTheme = mediaQueryList.matches ? 'dark' : 'light';
    } else {
        nextTheme = value;
    }

    if (animate && currentTheme !== nextTheme) {
        applyThemeTransition(nextTheme);
        return;
    }

    applyThemeClasses(root, nextTheme);
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

const getStoredAppearance = (): Appearance | null => {
    if (typeof window === 'undefined') {
        return null;
    }

    const stored = localStorage.getItem('appearance');
    return isValidAppearance(stored) ? stored : null;
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
        const savedAppearance = getStoredAppearance();

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
