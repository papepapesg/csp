<?php

namespace Database\Seeders;

use App\Foundation\Models\UiTranslation;
use Illuminate\Database\Seeder;

/**
 * Baseline i18n resources, organised by culture → domain → section. The base
 * culture (en, Kenyan English) needs no rows — keys ARE the source text; sw and
 * fr ship the strings previously hard-coded in i18n.js / lang JSON. Operators
 * refine or add cultures from the Localization Studio (/i18n/studio).
 */
class UiTranslationSeeder extends Seeder
{
    /** locale → domain → section → [key => value] */
    private const RESOURCES = [
        'sw' => [
            'NAV' => ['menu' => [
                'Dashboard' => 'Dashibodi', 'Customers' => 'Wateja', 'Tickets' => 'Tiketi',
                'Reports' => 'Ripoti', 'Workflow' => 'Mtiririko', 'Rules' => 'Sheria',
                'Commercial' => 'Biashara', 'Templates' => 'Violezo', 'Warehouse' => 'Ghala',
                'Localization' => 'Lugha',
            ]],
            'COMMON' => ['actions' => [
                'Save' => 'Hifadhi', 'Cancel' => 'Ghairi', 'Search' => 'Tafuta',
                'Create' => 'Unda', 'Status' => 'Hali', 'Delete' => 'Futa', 'Edit' => 'Hariri',
            ]],
            'BACKEND' => ['api' => [
                'Operation accepted' => 'Operesheni imekubaliwa',
                'Not found' => 'Haipatikani',
                'Forbidden' => 'Imekatazwa',
            ]],
        ],
        'fr' => [
            'NAV' => ['menu' => [
                'Dashboard' => 'Tableau de bord', 'Customers' => 'Clients', 'Tickets' => 'Tickets',
                'Reports' => 'Rapports', 'Workflow' => 'Flux', 'Rules' => 'Règles',
                'Commercial' => 'Commercial', 'Templates' => 'Modèles', 'Warehouse' => 'Entrepôt',
                'Localization' => 'Localisation',
            ]],
            'COMMON' => ['actions' => [
                'Save' => 'Enregistrer', 'Cancel' => 'Annuler', 'Search' => 'Rechercher',
                'Create' => 'Créer', 'Status' => 'Statut', 'Delete' => 'Supprimer', 'Edit' => 'Modifier',
            ]],
            'BACKEND' => ['api' => [
                'Operation accepted' => 'Opération acceptée',
                'Not found' => 'Introuvable',
                'Forbidden' => 'Interdit',
            ]],
        ],
    ];

    public function run(): void
    {
        foreach (self::RESOURCES as $locale => $domains) {
            foreach ($domains as $domain => $sections) {
                foreach ($sections as $section => $pairs) {
                    foreach ($pairs as $key => $value) {
                        UiTranslation::query()->updateOrCreate(
                            [
                                'operator_code' => UiTranslation::ALL_OPERATORS,
                                'locale' => $locale,
                                'domain' => $domain,
                                'section' => $section,
                                'key' => $key,
                            ],
                            ['value' => $value, 'updated_by' => 'SEED'],
                        );
                    }
                }
            }
        }
    }
}
