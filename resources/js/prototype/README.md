# CPF Isolated Prototype Workspace

All prototype-only frontend code lives exclusively under:
- `resources/js/prototype/` (components, mock data, experimental UI)
- `resources/js/pages/prototype/` (Inertia prototype pages)

And all prototype-only backend code lives exclusively under:
- `app/Http/Controllers/Prototype/`
- `routes/prototype.php`

## Environment Toggle
Set `CPF_PROTOTYPE_ENABLED=false` in `.env` for production deployments to disable all prototype routes completely without touching any main application code.
