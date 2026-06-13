// SOPHIX i18n foundation. The operator's deployment config drives language and
// regional formatting; default is Kenyan English (en-KE conventions, KES).
// Usage in any page:  const { t, money, dateFmt } = useI18n();
import { usePage } from '@inertiajs/vue3';

const dictionaries = {
    en: {}, // Kenyan English is the source language — keys ARE the text.
    sw: {
        Dashboard: 'Dashibodi', Customers: 'Wateja', Tickets: 'Tiketi', Reports: 'Ripoti',
        Workflow: 'Mtiririko', Rules: 'Sheria', Commercial: 'Biashara', Templates: 'Violezo',
        RBAC: 'RBAC', NOC: 'NOC', Warehouse: 'Ghala', 'IT-Ops': 'IT-Ops',
        Save: 'Hifadhi', Cancel: 'Ghairi', Search: 'Tafuta', Create: 'Unda', Status: 'Hali',
    },
    fr: {
        Dashboard: 'Tableau de bord', Customers: 'Clients', Tickets: 'Tickets', Reports: 'Rapports',
        Workflow: 'Flux', Rules: 'Règles', Commercial: 'Commercial', Templates: 'Modèles',
        RBAC: 'RBAC', NOC: 'NOC', Warehouse: 'Entrepôt', 'IT-Ops': 'IT-Ops',
        Save: 'Enregistrer', Cancel: 'Annuler', Search: 'Rechercher', Create: 'Créer', Status: 'Statut',
    },
};

// Map operator locale -> Intl regional locale (cultural formatting, not just words).
const intlLocale = { en: 'en-KE', sw: 'sw-KE', fr: 'fr-SN' };

export function useI18n() {
    const page = usePage();
    const op = page.props.operatorConfig ?? {};
    const locale = op.default_locale ?? 'en';
    const region = intlLocale[locale] ?? 'en-KE';
    const currency = op.currency_code ?? 'KES';

    // Resolution order: studio-managed resources (ui_translation catalog, merged
    // global + operator rows for this culture) → built-in fallback dictionary →
    // the key itself (en is the source language).
    const resources = page.props.i18nResources ?? {};
    // t('key') returns the translated string; t('Hello :name', { name }) interpolates
    // :placeholder tokens after translation, so dynamic strings stay translatable.
    const t = (key, params) => {
        let s = resources[key] ?? dictionaries[locale]?.[key] ?? key;
        if (params) for (const [k, v] of Object.entries(params)) s = s.replaceAll(`:${k}`, v ?? '');
        return s;
    };
    const money = (amount) => new Intl.NumberFormat(region, { style: 'currency', currency }).format(Number(amount ?? 0));
    const dateFmt = (d, opts = { dateStyle: 'medium', timeStyle: 'short' }) =>
        d ? new Intl.DateTimeFormat(region, opts).format(new Date(d)) : '—';

    return { t, money, dateFmt, locale, region, currency };
}
