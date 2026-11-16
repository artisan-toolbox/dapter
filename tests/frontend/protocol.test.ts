import { describe, expect, it } from 'vite-plus/test';
import corpus from '../../resources/contracts/read-v1.json';
import { parseFailure, parsePage } from '../../resources/js/protocol';

describe('current read protocol corpus', () => {
    it('uses the same complete responses for raw route cases and parser examples', () => {
        for (const request of corpus.requests) {
            const examples = request.status === 200 ? corpus.successes : corpus.failures;
            const example = examples.find(({ id }) => id === request.id);
            expect(example?.response, request.id).toEqual(request.response);
        }
    });
    it.each(corpus.successes)('accepts $id with its complete JSON values', ({ response }) => {
        const accepted = parsePage(response);
        expect(accepted.data).toEqual(response.data);
        expect(accepted.requested).toEqual(response.requested);
        expect(accepted.state).toEqual(response.state);
        expect(accepted.paging).toEqual(response.paging);
        expect(accepted.options).toEqual(response.capabilities.paging.per_page_options);
        expect(Object.isFrozen(accepted.data)).toBe(true);
    });
    it.each(corpus.failures)('accepts $id without inferring kind from status', ({ response }) => {
        expect(parseFailure(response)).toEqual(response.error);
    });
    it.each(corpus.invalid_successes)('rejects success control $id', ({ response }) => {
        expect(() => parsePage(response)).toThrow();
    });
    it.each(corpus.invalid_failures)('rejects failure control $id', ({ response }) => {
        expect(parseFailure(response)).toBeNull();
    });
});
