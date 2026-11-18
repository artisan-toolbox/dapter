import type { AdapterDefinition, FullTextPolicy, LocalePolicy } from '@artisan-toolbox/dapter';
import { definition } from './phase2-fixtures.js';

export const fullText = {
    engine: 'pgsql',
    configuration: 'pg_catalog.english',
    input: 'plain_text',
    maxBytes: 512,
    ranking: 'filter_only',
} as const satisfies FullTextPolicy;
export const locale = {
    locale: 'pt_BR',
    validLocale: 'pt',
    actualLocale: 'root',
    icuVersion: '78.1',
    strength: 'tertiary',
    normalization: true,
    numeric: false,
} as const satisfies LocalePolicy;
export const comparisonDefinition = {
    ...definition,
    fields: {
        ...definition.fields,
        name: {
            search: { valueType: 'string', matchTypes: ['contains', 'full_text'], fullText },
            order: { directions: ['asc', 'desc'], sortTypes: ['string', 'locale'], locale },
            filters: null,
        },
    },
    global: {
        ...definition.global,
        search: {
            valueType: 'string',
            targets: {
                description: { valueType: 'string', matchType: 'full_text', fullText },
                name: { valueType: 'string', matchType: 'contains' },
            },
        },
    },
} as const satisfies AdapterDefinition;
