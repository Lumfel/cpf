<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class([
    'dark' => ($appearance ?? 'system') == 'dark',
    'theme-sunrise' => ($appearance ?? 'system') == 'sunrise',
    'theme-joy' => ($appearance ?? 'system') == 'joy',
    'theme-vice' => ($appearance ?? 'system') == 'vice',
])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                } else if (appearance === 'sunrise') {
                    document.documentElement.classList.add('theme-sunrise');
                } else if (appearance === 'joy') {
                    document.documentElement.classList.add('theme-joy');
                } else if (appearance === 'vice') {
                    document.documentElement.classList.add('theme-vice');
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #FBF6EE;
            }

            html.dark {
                background-color: #0E141A;
            }

            html.theme-sunrise {
                background-color: #FBD8D5;
            }

            html.theme-joy {
                background-color: #FBF4E4;
            }

            html.theme-vice {
                background-color: #120B1F;
            }
        </style>

        <link rel="icon" type="image/png" sizes="256x256" href="/favicon.png?v=2">
        <link rel="shortcut icon" type="image/png" href="/favicon.png?v=2">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=2">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
