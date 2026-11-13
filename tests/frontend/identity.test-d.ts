import { expectTypeOf, it } from 'vite-plus/test';
import { version } from '@artisan-toolbox/dapter';

it('types the public package version as a string', () => {
    expectTypeOf(version).toEqualTypeOf<string>();

    // @ts-expect-error Package versions are strings.
    const invalidVersion: typeof version = 1;
    void invalidVersion;
});
