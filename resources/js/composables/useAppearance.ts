import type { ComputedRef, Ref } from 'vue';
import { computed, onMounted, ref } from 'vue';
import type { Appearance, ResolvedAppearance } from '@/types';

export type { Appearance, ResolvedAppearance };

export type UseAppearanceReturn = {
    appearance: Ref<Appearance>;
    resolvedAppearance: ComputedRef<ResolvedAppearance>;
    updateAppearance: (value: Appearance) => void;
};

let themeTransitionTimer: number | null = null;

function playSkyTransition(targetIsDark: boolean): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    const root = document.documentElement;
    if (themeTransitionTimer !== null) {
        window.clearTimeout(themeTransitionTimer);
    }

    root.classList.add('cpf-theme-transitioning');

    const existingVeil = document.getElementById('cpf-sky-veil');
    if (existingVeil) {
        existingVeil.remove();
    }

    const veil = document.createElement('div');
    veil.id = 'cpf-sky-veil';
    veil.setAttribute('aria-hidden', 'true');
    veil.className = targetIsDark ? 'cpf-sky-veil cpf-sky-sunset' : 'cpf-sky-veil cpf-sky-sunrise';
    document.body.appendChild(veil);

    themeTransitionTimer = window.setTimeout(() => {
        root.classList.remove('cpf-theme-transitioning');
        veil.remove();
        themeTransitionTimer = null;
    }, 1050);
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
        playSkyTransition(nextIsDark);
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

const handleSystemThemeChange = () => {
    const currentAppearance = getStoredAppearance();

    updateTheme(currentAppearance || 'system');
};

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // Initialize theme from saved preference or default to system...
    const savedAppearance = getStoredAppearance();
    updateTheme(savedAppearance || 'system');

    // Set up system theme change listener...
    mediaQuery()?.addEventListener('change', handleSystemThemeChange);
}

const appearance = ref<Appearance>('system');

export function useAppearance(): UseAppearanceReturn {
    onMounted(() => {
        const savedAppearance = localStorage.getItem(
            'appearance',
        ) as Appearance | null;

        if (savedAppearance) {
            appearance.value = savedAppearance;
        }
    });

    const resolvedAppearance = computed<ResolvedAppearance>(() => {
        if (appearance.value === 'system') {
            return prefersDark() ? 'dark' : 'light';
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
